<?php
declare(strict_types=1);

/**
 * Helpers globais do LMS.
 * Carregado por src/bootstrap.php após sessão e autoload.
 */

// ---------------------------------------------------------------------
// Escape HTML
// ---------------------------------------------------------------------

function e(mixed $v): string
{
    if ($v === null) {
        return '';
    }
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ---------------------------------------------------------------------
// i18n
// ---------------------------------------------------------------------

/**
 * Resolve o idioma corrente uma vez por request.
 * Ordem: ?lang= (persistido em sessão) → sessão → preferência do user → 'pt'.
 */
/**
 * Status de progresso do aluno em uma CU. Retorna struct
 * `['status' => 'not_started'|'in_progress'|'completed', 'percent' => 0-100]`.
 *
 * Delega pra `StudentProgress::cuStatus` — a fórmula real vive lá.
 * Fórmula documentada em `doc/10-progresso-e-dashboards.md`.
 *
 * @return array{status:string, percent:int}
 */
function student_cu_status(int $cuId, int $studentId): array
{
    return StudentProgress::cuStatus($cuId, $studentId);
}

/**
 * Status agregado de um curso pro aluno: percent = média das CUs avaliáveis
 * (ver doc/10).
 *
 * @return array{status:string, percent:int}
 */
function student_course_status(int $courseId, int $studentId): array
{
    return StudentProgress::courseStatus($courseId, $studentId);
}

/**
 * XP acumulado do aluno (E14-01). Soma `xp_events.value` — inclui atividades
 * entregues (E6-03) e avaliações com nota ≥ 8 (E7-03). Idempotência de XP já
 * é garantida pelo UK composite em `xp_events`.
 */
function student_total_xp(int $studentId): int
{
    $stmt = Database::pdo()->prepare(
        'SELECT COALESCE(SUM(value), 0) FROM xp_events WHERE student_user_id = ?'
    );
    $stmt->execute([$studentId]);
    return (int) $stmt->fetchColumn();
}

/**
 * Patente atual do aluno dado o XP acumulado (E14-01). Delega pra
 * `Rank::findCurrentByXp` usando o tenant do aluno. null quando tenant sem
 * patentes, aluno em gap, ou aluno sem tenant (super-admin).
 *
 * @return array<string,mixed>|null
 */
function student_current_rank(int $studentId, int $tenantId): ?array
{
    $xp = student_total_xp($studentId);
    return Rank::findCurrentByXp($tenantId, $xp);
}

/**
 * Próxima patente acima do XP atual do aluno (E14-01). null quando aluno
 * já está na faixa topo.
 *
 * @return array<string,mixed>|null
 */
function student_next_rank(int $studentId, int $tenantId): ?array
{
    $xp = student_total_xp($studentId);
    return Rank::findNextByXp($tenantId, $xp);
}

/**
 * Cascata de checks de conquistas após mudança de progressão (E18-04).
 * Chamado depois de `XpEvents::awardActivity` ou `EvaluationSubmissionService::grade`
 * — pontos onde a conclusão de UC pode mudar. Engole Throwable: falha de
 * unlock jamais derruba o fluxo principal (mesmo padrão E14).
 *
 * Idempotente: cada `evaluateForEvent` filtra já-desbloqueados antes do
 * INSERT IGNORE. Chamar em situação que não muda nada = no-op.
 *
 * Recebe `$cuId` por completude semântica do contexto, mas o service
 * recomputa contagens globais — não usa o cuId diretamente.
 */
function student_progression_check(int $studentId, int $tenantId, int $cuId): void
{
    try {
        AchievementsService::evaluateForEvent($studentId, $tenantId, 'uc_completed');
        AchievementsService::evaluateForEvent($studentId, $tenantId, 'cc_completed');
        AchievementsService::evaluateForEvent($studentId, $tenantId, 'course_completed');
    } catch (\Throwable) {
        // best-effort
    }
}

/**
 * Calcula o estado de progressão (visibilidade) de cada CC e UC do
 * curso pro aluno (E19-02). Considera `courses.cc_mode`.
 *
 * Status possíveis por CC e por UC:
 *  - 'current'   — ponto atual; renderiza nítido + navegável
 *  - 'next'      — próximo na fila; renderiza com blur + overlay "Conclua X primeiro"
 *  - 'hidden'    — após o 'next'; não renderizado
 *  - 'completed' — concluído; renderiza nítido
 *  - 'free'      — modo livre (cc_mode='free'); renderiza nítido sem regras
 *
 * `current_cc_name` é o nome da CC atual (referenciado no overlay da
 * próxima CC: "Conclua [current_cc_name] primeiro"). Análogo para UC
 * em `current_cu_name`.
 *
 * @param array $course estrutura retornada por `StudentCurriculum::forStudentCourse`
 * @return array{cc_status:array<int,string>, cu_status:array<int,string>, current_cc_name:?string, current_cu_name:?string}
 */
function course_progression_state(array $course, int $studentId): array
{
    $ccMode        = (string) ($course['cc_mode'] ?? 'sequential');
    $ccs           = $course['ccs'] ?? [];
    $ccStatus      = [];
    $cuStatus      = [];
    $currentCcName = null;
    $currentCuName = null;

    if ($ccMode === 'free') {
        foreach ($ccs as $cc) {
            $ccStatus[(int) $cc['id']] = 'free';
            foreach ($cc['cus'] ?? [] as $cu) {
                $cuStatus[(int) $cu['id']] = 'free';
            }
        }
        return [
            'cc_status'       => $ccStatus,
            'cu_status'       => $cuStatus,
            'current_cc_name' => null,
            'current_cu_name' => null,
        ];
    }

    // CC sem CUs nunca é "completa" — vira current se vier antes.
    $isCcComplete = static function (array $cc, int $sid): bool {
        $cus = $cc['cus'] ?? [];
        if ($cus === []) {
            return false;
        }
        foreach ($cus as $cu) {
            $st = student_cu_status((int) $cu['id'], $sid);
            if (($st['status'] ?? '') !== 'completed') {
                return false;
            }
        }
        return true;
    };

    $foundCurrent   = false;
    $markNextAsNext = false;
    foreach ($ccs as $cc) {
        $ccId = (int) $cc['id'];

        if ($markNextAsNext) {
            $ccStatus[$ccId] = 'next';
            $markNextAsNext  = false;
            continue;
        }
        if ($foundCurrent) {
            $ccStatus[$ccId] = 'hidden';
            continue;
        }
        if ($isCcComplete($cc, $studentId)) {
            $ccStatus[$ccId] = 'completed';
        } else {
            $ccStatus[$ccId] = 'current';
            $currentCcName   = (string) $cc['name'];
            $foundCurrent    = true;
            $markNextAsNext  = true;
        }
    }

    // CUs:
    //  - CC 'completed'     → todas CUs 'completed'
    //  - CC 'next'/'hidden' → todas CUs 'hidden' (CC inteira nem renderiza inner cards)
    //  - CC 'current'       → regra sequencial entre as CUs
    foreach ($ccs as $cc) {
        $ccId = (int) $cc['id'];
        $st   = $ccStatus[$ccId];
        $cus  = $cc['cus'] ?? [];

        if ($st === 'completed') {
            foreach ($cus as $cu) {
                $cuStatus[(int) $cu['id']] = 'completed';
            }
            continue;
        }
        if ($st === 'next' || $st === 'hidden') {
            foreach ($cus as $cu) {
                $cuStatus[(int) $cu['id']] = 'hidden';
            }
            continue;
        }

        // CC atual: aplica regra sequencial pra suas CUs.
        $foundCurrentCu   = false;
        $markNextCuAsNext = false;
        foreach ($cus as $cu) {
            $cuId = (int) $cu['id'];

            if ($markNextCuAsNext) {
                $cuStatus[$cuId]  = 'next';
                $markNextCuAsNext = false;
                continue;
            }
            if ($foundCurrentCu) {
                $cuStatus[$cuId] = 'hidden';
                continue;
            }
            $cuStat = student_cu_status($cuId, $studentId);
            if (($cuStat['status'] ?? '') === 'completed') {
                $cuStatus[$cuId] = 'completed';
            } else {
                $cuStatus[$cuId]  = 'current';
                $currentCuName    = (string) $cu['name'];
                $foundCurrentCu   = true;
                $markNextCuAsNext = true;
            }
        }
    }

    return [
        'cc_status'       => $ccStatus,
        'cu_status'       => $cuStatus,
        'current_cc_name' => $currentCcName,
        'current_cu_name' => $currentCuName,
    ];
}

/**
 * Últimas conquistas desbloqueadas pelo aluno no tenant, ordenadas por
 * `unlocked_at DESC`. Usado pelo card "Conquistas" no ProfileSidebar
 * (E18-06) — 3 miniaturas + link "Ver todas". Engole Throwable.
 *
 * Retorna `[{id, code, icon_key, name, unlocked_at}]` com `name` já
 * resolvido pra língua atual.
 *
 * @return list<array{id:int,code:string,icon_key:string,name:string,unlocked_at:string}>
 */
function student_recent_achievements(int $studentId, int $tenantId, int $limit = 3): array
{
    if ($studentId <= 0 || $tenantId <= 0 || $limit <= 0) {
        return [];
    }
    try {
        $stmt = Database::pdo()->prepare(
            'SELECT a.id, a.code, a.icon_key, a.name_pt, a.name_en, sa.unlocked_at
               FROM student_achievements sa
               JOIN achievements a ON a.id = sa.achievement_id
              WHERE sa.student_user_id = ? AND sa.tenant_id = ?
              ORDER BY sa.unlocked_at DESC
              LIMIT ' . $limit
        );
        $stmt->execute([$studentId, $tenantId]);
        $rows = $stmt->fetchAll();
    } catch (\Throwable) {
        return [];
    }

    $lang = current_lang();
    $out  = [];
    foreach ($rows as $r) {
        $out[] = [
            'id'          => (int) $r['id'],
            'code'        => (string) $r['code'],
            'icon_key'    => (string) $r['icon_key'],
            'name'        => (string) ($lang === 'pt' ? $r['name_pt'] : $r['name_en']),
            'unlocked_at' => (string) $r['unlocked_at'],
        ];
    }
    return $out;
}

/**
 * Indica se o par (evento, canal) está habilitado pro tenant (E21-01).
 *
 * Lê `notification_settings` — linha ausente = enabled (default ON).
 * Cache estático por request por chave composta `tenantId:event:channel`
 * pra evitar N queries quando o mesmo par é consultado em loop (ex.: bulk
 * enrollment, broadcast de novo conteúdo).
 *
 * Defensivo: `tenant_id <= 0` retorna true; Throwable → true. O caller
 * espera bool — degradar pra "enabled" preserva o comportamento atual.
 *
 * Não consultado em lugar nenhum ainda; integração no NotificationService
 * fica em E21-02.
 */
function notification_enabled(int $tenantId, string $event, string $channel): bool
{
    if ($tenantId <= 0) {
        return true;
    }

    static $cache = [];
    $key = $tenantId . ':' . $event . ':' . $channel;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    try {
        $stmt = Database::pdo()->prepare(
            'SELECT enabled FROM notification_settings
              WHERE tenant_id = ? AND event = ? AND channel = ?
              LIMIT 1'
        );
        $stmt->execute([$tenantId, $event, $channel]);
        $value = $stmt->fetchColumn();
    } catch (\Throwable) {
        return $cache[$key] = true;
    }

    // Linha ausente = enabled (default ON). fetchColumn retorna false sem rows.
    if ($value === false || $value === null) {
        return $cache[$key] = true;
    }
    return $cache[$key] = ((int) $value === 1);
}

/**
 * URL do avatar default do aluno (E17-04). Combina `tenants.avatar_style`
 * (config do professor) com `users.gender` (cadastrado em E16-01) pra
 * produzir o path do SVG em `public/assets/avatars/`.
 *
 * Cache estático por request — ProfileSidebar + listagens podem chamar
 * múltiplas vezes pro mesmo aluno sem onerar o DB.
 *
 * Fallback gracioso: aluno sem matrícula/tenant retorna 'arabe-male.svg'.
 */
function student_avatar_url(int $studentId): string
{
    static $cache = [];
    if (isset($cache[$studentId])) {
        return $cache[$studentId];
    }

    $stmt = Database::pdo()->prepare(
        'SELECT u.gender, t.avatar_style
           FROM users u
           JOIN tenants t ON t.id = u.tenant_id
          WHERE u.id = ? AND u.role = "student"
          LIMIT 1'
    );
    $stmt->execute([$studentId]);
    $row = $stmt->fetch();

    $style  = $row !== false ? (string) $row['avatar_style'] : 'arabe';
    $gender = $row !== false ? (string) $row['gender']       : 'male';

    if (!in_array($style, ['arabe', 'ocidental'], true)) {
        $style = 'arabe';
    }
    if (!in_array($gender, ['male', 'female'], true)) {
        $gender = 'male';
    }

    $url = '/assets/avatars/' . $style . '-' . $gender . '.svg';
    $cache[$studentId] = $url;
    return $url;
}

/**
 * Branding visual do tenant (E24-02). Resolve nome + URL da logo a renderizar
 * na navbar, considerando a flag `is_actvet` e os campos opcionais
 * `platform_name` / `logo_path`.
 *
 * Regras:
 *  - Actvet (`is_actvet=1`): logo travada em `/assets/logos/actvet.png`,
 *    nome usa `platform_name` se setado, senão "Skills Hub".
 *  - Não-Actvet: logo do upload (se `logo_path` setado) ou null pra fallback;
 *    nome usa `platform_name` se setado, senão `branding.default_name` (i18n).
 *  - Tenant inexistente / id inválido: fallback genérico.
 *
 * `logo_path` armazena apenas o basename (`<tenant_id>-<ts>.<ext>`); o helper
 * monta a URL final com o prefixo `/uploads/logos/`.
 *
 * Cache estático por request — header pode chamar em qualquer página, e
 * partials que renderizam nome do tenant também.
 *
 * @return array{name:string, logo_url:?string}
 */
function tenant_branding(int $tenantId): array
{
    static $cache = [];
    if (isset($cache[$tenantId])) {
        return $cache[$tenantId];
    }

    $stmt = Database::pdo()->prepare(
        'SELECT is_actvet, platform_name, logo_path FROM tenants WHERE id = ? LIMIT 1'
    );
    $stmt->execute([$tenantId]);
    $row = $stmt->fetch();

    if ($row === false) {
        return $cache[$tenantId] = [
            'name'     => __t('branding.default_name'),
            'logo_url' => null,
        ];
    }

    $isActvet     = (int) $row['is_actvet'] === 1;
    $platformName = ($row['platform_name'] ?? '') !== '' ? (string) $row['platform_name'] : null;
    $logoPath     = ($row['logo_path']     ?? '') !== '' ? (string) $row['logo_path']     : null;

    if ($isActvet) {
        $name    = $platformName ?? 'Skills Hub';
        $logoUrl = '/assets/logos/actvet.png';
    } else {
        $name    = $platformName ?? __t('branding.default_name');
        $logoUrl = $logoPath !== null ? '/uploads/logos/' . $logoPath : null;
    }

    return $cache[$tenantId] = ['name' => $name, 'logo_url' => $logoUrl];
}

/**
 * Pure logic — disponibilidade de acesso do aluno ao curso (E17-03), dados
 * os 3 campos da matrícula. Usada pela CourseCard (sem 2ª query) e por
 * `enrollment_access_status` (que faz query primeiro).
 *
 * Retorna array com `available:bool`, e quando false: `reason` em
 * `'blocked' | 'before' | 'after'` + `message` já traduzida.
 *
 * @return array{available:bool, reason?:string, message?:string}
 */
function enrollment_availability(?string $accessStartsAt, ?string $accessEndsAt, ?string $blockedAt): array
{
    if ($blockedAt !== null && $blockedAt !== '') {
        return [
            'available' => false,
            'reason'    => 'blocked',
            'message'   => __t('enrollment.unavailable.blocked'),
        ];
    }
    $now = time();
    if ($accessStartsAt !== null && $accessStartsAt !== '') {
        $startTs = strtotime($accessStartsAt);
        if ($startTs !== false && $now < $startTs) {
            return [
                'available' => false,
                'reason'    => 'before',
                'message'   => __t('enrollment.unavailable.before', ['date' => format_short_date($accessStartsAt)]),
            ];
        }
    }
    if ($accessEndsAt !== null && $accessEndsAt !== '') {
        $endTs = strtotime($accessEndsAt);
        if ($endTs !== false && $now > $endTs) {
            return [
                'available' => false,
                'reason'    => 'after',
                'message'   => __t('enrollment.unavailable.after', ['date' => format_short_date($accessEndsAt)]),
            ];
        }
    }
    return ['available' => true];
}

/**
 * Disponibilidade de acesso do aluno ao curso (E17-03). Faz query na
 * `enrollments` e delega pra `enrollment_availability`. Retorna
 * `['available' => false, 'reason' => 'no_enrollment']` se aluno não
 * tem matrícula no curso (caller decide se redireciona ou 404).
 *
 * Usada nos gates de `/student/course/{id}` e sub-rotas (cu, activity,
 * evaluation). Pra listagem `/student` (My Courses), prefira
 * `enrollment_availability` direto sobre dados já fetched.
 */
function enrollment_access_status(int $studentId, int $courseId): array
{
    $stmt = Database::pdo()->prepare(
        'SELECT access_starts_at, access_ends_at, blocked_at
           FROM enrollments
          WHERE student_user_id = ? AND course_id = ?
          LIMIT 1'
    );
    $stmt->execute([$studentId, $courseId]);
    $row = $stmt->fetch();
    if ($row === false) {
        return ['available' => false, 'reason' => 'no_enrollment'];
    }
    return enrollment_availability(
        $row['access_starts_at'],
        $row['access_ends_at'],
        $row['blocked_at']
    );
}

/**
 * Renderiza label legível do período de acesso de uma matrícula (E17-01).
 *   (null, null) → "imediato → ilimitado"
 *   ("...", null) → "DD/MM/YYYY → ilimitado"
 *   (null, "...") → "imediato → DD/MM/YYYY"
 *   ("...", "...") → "DD/MM/YYYY → DD/MM/YYYY"
 * Datas formatadas via `format_short_date` (respeita idioma).
 */
function format_period_label(?string $startsAt, ?string $endsAt): string
{
    $start = ($startsAt !== null && $startsAt !== '')
        ? format_short_date($startsAt)
        : __t('enrollments.period.immediate');
    $end = ($endsAt !== null && $endsAt !== '')
        ? format_short_date($endsAt)
        : __t('enrollments.period.unlimited');
    return $start . ' → ' . $end;
}

/**
 * Converte input `<input type="datetime-local">` ("YYYY-MM-DDTHH:MM" ou
 * "YYYY-MM-DDTHH:MM:SS") em DATETIME do MySQL ("YYYY-MM-DD HH:MM:SS").
 * Vazio/whitespace → null. Formato inválido → null (caller decide se
 * isso é erro ou ausência). Usado em E17-01 (período de matrícula) e em
 * outros forms que aceitem datetime opcional.
 */
function parse_datetime_local(?string $val): ?string
{
    $val = trim((string) $val);
    if ($val === '') {
        return null;
    }
    try {
        $dt = new \DateTimeImmutable($val);
    } catch (\Exception) {
        return null;
    }
    return $dt->format('Y-m-d H:i:s');
}

/**
 * Reduz "Marcos Aparecido Ortolani Soares" pra "Marcos Soares" (E16-02).
 * Usado na listagem `/teacher/students` pra deixar a tabela legível em
 * telas menores. Aluno com 1 token só (ex.: "Madonna") retorna esse token.
 * Tooltip no caller preserva acesso ao nome completo no hover.
 */
function format_short_name(string $fullName): string
{
    $name = trim($fullName);
    if ($name === '') {
        return '';
    }
    $tokens = preg_split('/\s+/u', $name) ?: [];
    if (count($tokens) <= 1) {
        return $name;
    }
    return $tokens[0] . ' ' . end($tokens);
}

/**
 * Resume um user agent em "Browser / SO" (TIME-05). Sem libs — switch/case
 * por keyword. Ordem importa: testa Edge antes de Chrome (UA do Edge contem
 * "Chrome"), Opera antes de Chrome, etc. Versoes nao sao extraidas (Win11
 * reporta como Windows NT 10.0 — distinguir nao e confiavel).
 *
 * Devolve string vazia quando UA tambem e vazia.
 */
function format_user_agent_short(?string $ua): string
{
    $ua = trim((string) $ua);
    if ($ua === '') return '';

    if (stripos($ua, 'Edg/') !== false || stripos($ua, 'Edge/') !== false) {
        $browser = 'Edge';
    } elseif (stripos($ua, 'OPR/') !== false || stripos($ua, 'Opera') !== false) {
        $browser = 'Opera';
    } elseif (stripos($ua, 'Firefox') !== false) {
        $browser = 'Firefox';
    } elseif (stripos($ua, 'Chrome') !== false) {
        $browser = 'Chrome';
    } elseif (stripos($ua, 'Safari') !== false) {
        $browser = 'Safari';
    } else {
        $browser = '?';
    }

    if (stripos($ua, 'Android') !== false) {
        $os = 'Android';
    } elseif (stripos($ua, 'iPhone') !== false || stripos($ua, 'iPad') !== false || stripos($ua, 'iOS') !== false) {
        $os = 'iOS';
    } elseif (stripos($ua, 'Windows') !== false) {
        $os = 'Windows';
    } elseif (stripos($ua, 'Mac OS X') !== false || stripos($ua, 'Macintosh') !== false) {
        $os = 'macOS';
    } elseif (stripos($ua, 'Linux') !== false) {
        $os = 'Linux';
    } else {
        $os = '?';
    }

    return $browser . ' / ' . $os;
}

/**
 * Formata duracao em segundos pra string legivel (TIME-04).
 *   null ou <= 0   -> '—'
 *   1..59s         -> '45s'
 *   60..3599s      -> '12min'   (segundos sao ruido em listagem)
 *   >= 3600s       -> '2h 35min'
 */
function format_duration(?int $seconds): string
{
    if ($seconds === null || $seconds <= 0) {
        return '—';
    }
    if ($seconds < 60) {
        return $seconds . 's';
    }
    if ($seconds < 3600) {
        return intdiv($seconds, 60) . 'min';
    }
    $hours = intdiv($seconds, 3600);
    $mins  = intdiv($seconds % 3600, 60);
    return $mins === 0 ? $hours . 'h' : $hours . 'h ' . $mins . 'min';
}

/**
 * Posição linear do aluno no ranking geral do tenant (E9-07). Delega pra
 * `RankingService::myPosition` (window 'all', sem filtros). Engole Throwable
 * graciosamente — sidebar mostra "—" se a query falhar (mesmo padrão do
 * `Enrollment::touchLastAccess` em E14: tracking silencioso jamais derruba a UI).
 *
 * Sem cache no MVP (decisão consolidada do PO no #10).
 */
function student_ranking_position(int $studentId, int $tenantId): ?int
{
    if ($studentId <= 0 || $tenantId <= 0) {
        return null;
    }
    try {
        return RankingService::myPosition($studentId, $tenantId, 'all', []);
    } catch (\Throwable) {
        return null;
    }
}

/**
 * Nome do curso acessado mais recentemente pelo aluno (via
 * `enrollments.last_access_at`, E14-00). Usado como subtítulo no
 * ProfileSidebar. null quando o aluno nunca abriu curso ou não tem
 * matrícula.
 */
function student_recent_course_name(int $studentId): ?string
{
    $stmt = Database::pdo()->prepare(
        'SELECT c.name
           FROM enrollments e
           JOIN courses c ON c.id = e.course_id
          WHERE e.student_user_id = ? AND e.last_access_at IS NOT NULL
          ORDER BY e.last_access_at DESC
          LIMIT 1'
    );
    $stmt->execute([$studentId]);
    $name = $stmt->fetchColumn();
    return $name !== false ? (string) $name : null;
}

/**
 * Converte um path interno (`/student/activity/42`) em URL absoluta pra links
 * em emails (E10-03). Usa `APP_BASE_URL` de `config/env.php`; fallback pro
 * scheme+host do request quando a env estiver vazia (útil em dev).
 *
 * Aceita tanto `/path` quanto `path` — sempre retorna com `/` inicial.
 */
function app_url(string $path): string
{
    $env  = $GLOBALS['__ENV'] ?? [];
    $base = rtrim((string) ($env['APP_BASE_URL'] ?? ''), '/');

    if ($base === '') {
        $scheme = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
        $host   = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $base   = $scheme . '://' . $host;
    }

    if ($path === '' || $path[0] !== '/') {
        $path = '/' . $path;
    }

    return $base . $path;
}

/**
 * Formata um timestamp MySQL como data curta, respeitando o idioma corrente:
 *   pt → `d/m/Y` (ex.: 24/04/2026)
 *   en → `M j, Y` (ex.: Apr 24, 2026)
 * Fallback pra string vazia em entrada inválida. Usado no CourseCard
 * (E14-02) pra "Último acesso: {date}".
 */
/**
 * Formata duração em minutos como label humana pra métricas do professor
 * (E11-04). Adapta unidade:
 *   - null  → "—"
 *   - < 60  → "X min"
 *   - < 1440 (24h) → "Xh Ymin" (omite "Ymin" se 0)
 *   - >= 1440 → "X dias" (arredonda)
 */
function format_duration_minutes(?int $minutes): string
{
    if ($minutes === null) {
        return '—';
    }
    if ($minutes < 60) {
        return $minutes . ' min';
    }
    if ($minutes < 1440) {
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;
        return $m > 0 ? ($h . 'h ' . $m . 'min') : ($h . 'h');
    }
    $days = (int) round($minutes / 1440);
    return $days . ' ' . ($days === 1 ? __t('common.day') : __t('common.days'));
}

function format_short_date(string $mysqlDatetime): string
{
    if ($mysqlDatetime === '' || $mysqlDatetime === '0000-00-00 00:00:00') {
        return '';
    }
    try {
        $dt = new \DateTimeImmutable($mysqlDatetime);
    } catch (\Exception) {
        return '';
    }
    return $dt->format(current_lang() === 'pt' ? 'd/m/Y' : 'M j, Y');
}

/**
 * Formata um timestamp (MySQL DATETIME) em `d/m HH:MM` (pt) ou `m/d HH:MM` (en).
 * Curto o suficiente pro item do dropdown de notificações. Fallback pra string
 * vazia em entrada inválida.
 */
function format_short_datetime(string $mysqlDatetime): string
{
    if ($mysqlDatetime === '' || $mysqlDatetime === '0000-00-00 00:00:00') {
        return '';
    }
    try {
        $dt = new \DateTimeImmutable($mysqlDatetime);
    } catch (\Exception) {
        return '';
    }
    $fmt = current_lang() === 'pt' ? 'd/m H:i' : 'm/d H:i';
    return $dt->format($fmt);
}

function current_lang(): string
{
    static $resolved = null;
    if ($resolved !== null) {
        return $resolved;
    }

    $supported = ['pt', 'en'];

    if (isset($_GET['lang']) && in_array($_GET['lang'], $supported, true)) {
        $_SESSION['lang'] = $_GET['lang'];
    }

    // Fallback 'en' pra visitantes anonimos (login, /forgot, etc.). Usuarios
    // logados tem `language` na sessao/DB; fallback so impacta quem nao tem
    // preferencia setada — e o publico tipico do LMS sao alunos e
    // professores nos EAU, onde EN eh mais neutro que PT.
    $candidate = $_SESSION['lang']
        ?? (current_user()['language'] ?? null)
        ?? 'en';

    $resolved = in_array($candidate, $supported, true) ? $candidate : 'en';
    return $resolved;
}

/**
 * URL do path atual com `?lang=X` mergeado no query string existente.
 * Usada pelo switcher de idioma ANÔNIMO (header.php) pra não matar
 * params como `?token=XYZ` em `/reset`. Para usuário logado, o POST
 * pra /settings/language já preserva via HTTP_REFERER.
 */
function lang_url(string $lang): string
{
    $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    if ($path === '') {
        $path = '/';
    }
    $params = array_merge($_GET, ['lang' => $lang]);
    $qs = http_build_query($params);
    return $qs === '' ? $path : ($path . '?' . $qs);
}

/**
 * Traduz uma chave. Substitui placeholders `:var` com valores de $params.
 * Se a chave não existir, retorna a própria chave e loga em storage/logs/i18n-missing.log.
 *
 * $lang opcional permite renderizar num idioma específico (ex.: email seguindo
 * preferência do destinatário — ADR-014). Quando omitido usa current_lang().
 */
function __t(string $key, array $params = [], ?string $lang = null): string
{
    static $cache = [];
    $lang = $lang ?? current_lang();

    if (!isset($cache[$lang])) {
        $file = LMS_ROOT . '/lang/' . $lang . '.php';
        $data = is_file($file) ? require $file : [];
        $cache[$lang] = is_array($data) ? $data : [];
    }

    $value = $cache[$lang][$key] ?? null;

    if ($value === null) {
        $logFile = LMS_ROOT . '/storage/logs/i18n-missing.log';
        $line = sprintf("[%s] %s: %s\n", date('Y-m-d H:i:s'), $lang, $key);
        @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
        return $key;
    }

    if ($params === []) {
        return $value;
    }

    $replace = [];
    foreach ($params as $k => $v) {
        $replace[':' . $k] = (string) $v;
    }
    return strtr($value, $replace);
}

// ---------------------------------------------------------------------
// CSRF
// ---------------------------------------------------------------------

const CSRF_TTL_SECONDS = 1800; // 30 min

function csrf_token(): string
{
    $expires = $_SESSION['_csrf_expires'] ?? 0;
    if (empty($_SESSION['_csrf']) || $expires < time()) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        $_SESSION['_csrf_expires'] = time() + CSRF_TTL_SECONDS;
    }
    return $_SESSION['_csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

/**
 * Valida o token do POST atual. Token é one-time use: após validar, é rotacionado.
 * Lança RuntimeException com status 403 se inválido.
 */
function csrf_verify(): void
{
    csrf_assert_valid();
    // Rotaciona: próxima request recebe token novo.
    unset($_SESSION['_csrf'], $_SESSION['_csrf_expires']);
}

/**
 * Versão sem rotação — pra endpoints AJAX/JSON que o mesmo usuário pode
 * chamar múltiplas vezes na mesma página sem reload (ex.: /api/code/run
 * em E8-02). O token vale até expirar (TTL de 30 min) ou até o usuário
 * abrir outra página, que força novo token.
 */
function csrf_verify_no_rotate(): void
{
    csrf_assert_valid();
}

function csrf_assert_valid(): void
{
    $posted  = (string) ($_POST['_csrf'] ?? '');
    $stored  = (string) ($_SESSION['_csrf'] ?? '');
    $expires = (int)    ($_SESSION['_csrf_expires'] ?? 0);

    if ($stored === '' || $posted === '' || $expires < time() || !hash_equals($stored, $posted)) {
        http_response_code(403);
        throw new RuntimeException('CSRF token inválido ou expirado');
    }
}

// ---------------------------------------------------------------------
// Auth
// ---------------------------------------------------------------------

function current_user(): ?array
{
    $u = $_SESSION['user'] ?? null;
    return is_array($u) ? $u : null;
}

function current_tenant_id(): ?int
{
    $u = current_user();
    if ($u === null || ($u['role'] ?? null) !== 'teacher') {
        return null;
    }
    $tid = $u['tenant_id'] ?? null;
    return $tid !== null ? (int) $tid : null;
}

/**
 * Tema visual do usuário atual (E27 — F18). Apenas aluno tem preferência;
 * teacher/super-admin/deslogado retornam 'light'. Decisão simplificadora
 * do MVP — dark mode pra teacher/admin entra em E29 (visual unificado).
 *
 * @return string 'light' | 'dark'
 */
function current_user_theme(): string
{
    $u = current_user();
    if ($u === null || ($u['role'] ?? null) !== 'student') {
        return 'light';
    }
    $theme = (string) ($u['theme'] ?? 'light');
    return $theme === 'dark' ? 'dark' : 'light';
}

/**
 * Middleware de autenticação (E1-05).
 *
 * Garante que:
 *   (a) há um usuário na sessão;
 *   (b) esse usuário ainda está `active = 1` no banco;
 *   (c) `password_changed_at` no banco bate com o da sessão — se divergir,
 *       significa que a senha foi trocada em outro dispositivo (ou via reset)
 *       e a sessão corrente deve ser invalidada.
 *
 * Qualquer falha faz logout, empilha flash explicativo e redireciona para /login
 * preservando o `next` para retomar após autenticação.
 */
function require_auth(): void
{
    $user = current_user();
    if ($user === null) {
        $next = $_SERVER['REQUEST_URI'] ?? '/';
        header('Location: /login?next=' . urlencode($next));
        exit;
    }

    $stmt = Database::pdo()->prepare(
        'SELECT active, password_changed_at FROM users WHERE id = ? LIMIT 1'
    );
    $stmt->execute([(int) $user['id']]);
    $row = $stmt->fetch();

    if (!$row || (int) $row['active'] !== 1) {
        AuthController::logout();
        flash('warning', __t('auth.account_deactivated'));
        header('Location: /login');
        exit;
    }

    $dbChanged      = (string) ($row['password_changed_at'] ?? '');
    $sessionChanged = (string) ($user['password_changed_at'] ?? '');
    if ($dbChanged !== $sessionChanged) {
        AuthController::logout();
        flash('info', __t('auth.session_invalidated'));
        header('Location: /login');
        exit;
    }
}

/**
 * Exige que o usuário logado tenha um dos papéis informados.
 * Uso: require_role('teacher'); ou require_role('teacher', 'super_admin');
 *
 * Se o usuário está autenticado mas não tem o papel, renderiza a página 403
 * amigável em vez de encerrar com resposta vazia.
 */
function require_role(string ...$roles): void
{
    require_auth();
    $userRole = current_user()['role'] ?? null;
    if (!in_array($userRole, $roles, true)) {
        http_response_code(403);
        require LMS_ROOT . '/src/templates/errors/403.php';
        exit;
    }
}

// ---------------------------------------------------------------------
// Flash messages
// ---------------------------------------------------------------------

/**
 * Empilha uma mensagem flash para aparecer no próximo render do layout.
 * $type: success | danger | warning | info
 */
function flash(string $type, string $message): void
{
    if (!isset($_SESSION['flash']) || !is_array($_SESSION['flash'])) {
        $_SESSION['flash'] = [];
    }
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

/**
 * Renderiza todas as mensagens flash pendentes. Invocado pelo layout;
 * pode ser chamado manualmente em páginas que não usam o layout mestre.
 */
function render_flash(): void
{
    require LMS_ROOT . '/src/templates/flash.php';
}

// ---------------------------------------------------------------------
// Settings (KV) — E2-05
// ---------------------------------------------------------------------

/**
 * Lê um valor da tabela `settings`. Cache estático por request evita N
 * SELECTs quando a mesma chave é consultada várias vezes na renderização
 * de uma página. Seeds iniciais ficam em install/schema.sql.
 */
function setting_get(string $key, ?string $default = null): ?string
{
    static $cache = [];
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $stmt = Database::pdo()->prepare('SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();

    $cache[$key] = $value === false ? $default : (string) $value;
    return $cache[$key];
}

/**
 * Grava (ou atualiza) um valor na tabela `settings`. `updated_at` é
 * mantido pelo próprio MySQL (`ON UPDATE CURRENT_TIMESTAMP` no schema).
 */
function setting_set(string $key, string $value): void
{
    Database::pdo()->prepare(
        'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
              ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    )->execute([$key, $value]);
}

// ---------------------------------------------------------------------
// Cache simples em arquivo (E2-06)
// ---------------------------------------------------------------------

/**
 * Resultado de $producer cacheado em `storage/cache/<key>.json` por $ttl
 * segundos. Se o arquivo estiver ausente, corrompido ou expirado, chama
 * $producer novamente e regrava.
 *
 * Criado para o painel do super-admin (E2-06), onde recomputar métricas
 * a cada F5 seria desperdício. Falhas de leitura/escrita são silenciosas:
 * o cache é um otimizador, não uma fonte de verdade.
 *
 * `$key` precisa ser um slug simples ([a-z0-9_-]); o helper valida.
 *
 * @param callable(): array $producer
 */
function cached_json(string $key, int $ttl, callable $producer): array
{
    if (preg_match('/^[a-z0-9_\-]+$/', $key) !== 1) {
        throw new InvalidArgumentException('cached_json: $key deve casar /[a-z0-9_-]+/');
    }

    $dir  = LMS_ROOT . '/storage/cache';
    $path = $dir . '/' . $key . '.json';

    if (is_file($path) && (filemtime($path) + $ttl) > time()) {
        $raw = @file_get_contents($path);
        if ($raw !== false) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
    }

    $fresh = $producer();

    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    @file_put_contents($path, json_encode($fresh, JSON_UNESCAPED_UNICODE), LOCK_EX);

    return $fresh;
}

// ---------------------------------------------------------------------
// Navegação hierárquica do currículo (E3-04)
// ---------------------------------------------------------------------

/**
 * Carrega toda a árvore curso → CCs → CUs com um único SELECT + LEFT JOIN,
 * filtrado por tenant. Retorna:
 *
 *   ['id' => 1, 'name' => 'Curso', 'ccs' => [
 *       ['id' => 5, 'name' => 'CC A', 'cus' => [['id' => 10, 'name' => 'CU α'], ...]],
 *       ...
 *   ]]
 *
 * Curso fora do tenant → ['id' => 0, 'name' => '', 'ccs' => []]. Caller
 * decide não mostrar sidebar nesse caso.
 *
 * @return array{id:int, name:string, ccs:list<array{id:int, name:string, cus:list<array{id:int, name:string}>}>}
 */
function curriculum_tree(int $courseId, int $tenantId): array
{
    $sql = 'SELECT c.id  AS course_id, c.name AS course_name,
                   cc.id AS cc_id,     cc.name AS cc_name,
                   cu.id AS cu_id,     cu.name AS cu_name
              FROM courses c
              LEFT JOIN core_competencies cc ON cc.course_id = c.id
              LEFT JOIN competence_units  cu ON cu.core_competency_id = cc.id
             WHERE c.id = ? AND c.tenant_id = ?
             ORDER BY cc.position ASC, cc.id ASC, cu.position ASC, cu.id ASC';

    $stmt = Database::pdo()->prepare($sql);
    $stmt->execute([$courseId, $tenantId]);
    $rows = $stmt->fetchAll();

    if ($rows === []) {
        return ['id' => 0, 'name' => '', 'ccs' => []];
    }

    $tree = [
        'id'   => (int)    $rows[0]['course_id'],
        'name' => (string) $rows[0]['course_name'],
        'ccs'  => [],
    ];

    $ccIndex = [];
    foreach ($rows as $row) {
        if ($row['cc_id'] === null) {
            continue;
        }
        $ccId = (int) $row['cc_id'];
        if (!isset($ccIndex[$ccId])) {
            $ccIndex[$ccId] = count($tree['ccs']);
            $tree['ccs'][] = [
                'id'   => $ccId,
                'name' => (string) $row['cc_name'],
                'cus'  => [],
            ];
        }
        if ($row['cu_id'] !== null) {
            $tree['ccs'][$ccIndex[$ccId]]['cus'][] = [
                'id'   => (int)    $row['cu_id'],
                'name' => (string) $row['cu_name'],
            ];
        }
    }

    return $tree;
}

/**
 * Formata as contagens de descendentes para o modal de exclusão (E3-05).
 * Recebe um array associativo `['ccs' => 3, 'cus' => 8, ...]` e devolve
 * uma lista de strings já no idioma atual — ex.: `['3 CCs', '8 CUs']`.
 * Entradas com valor 0 são omitidas. Traduz cada chave via `delete.label.<key>`.
 *
 * @param array<string,int> $counts
 * @return list<string>
 */
function format_delete_counts(array $counts): array
{
    $out = [];
    foreach ($counts as $key => $val) {
        $val = (int) $val;
        if ($val > 0) {
            $out[] = $val . ' ' . __t('delete.label.' . $key);
        }
    }
    return $out;
}

/**
 * Renderiza um <nav> com breadcrumb Bootstrap a partir de uma lista de itens.
 * O último item vira `active` sem link; os demais viram `<a>` se tiverem `url`.
 *
 * @param list<array{label:string, url?:string}> $items
 */
function breadcrumbs(array $items): string
{
    if ($items === []) {
        return '';
    }
    $out = '<nav aria-label="breadcrumb" class="small"><ol class="breadcrumb">';
    $n = count($items);
    foreach ($items as $i => $it) {
        $isLast = $i === $n - 1;
        $label  = (string) ($it['label'] ?? '');
        if ($isLast || !isset($it['url'])) {
            $out .= '<li class="breadcrumb-item active" aria-current="page">' . e($label) . '</li>';
        } else {
            $out .= '<li class="breadcrumb-item"><a href="' . e((string) $it['url']) . '">' . e($label) . '</a></li>';
        }
    }
    return $out . '</ol></nav>';
}

/**
 * Remove arquivo do disco a partir de um path relativo armazenado no banco
 * (`storage/uploads/...`). Defesa traversal: confina via realpath dentro de
 * `LMS_ROOT/storage/uploads/`. Best-effort — falha em apagar (arquivo já
 * removido, permissão, etc.) é silenciosa.
 *
 * Usado pelo cleanup de DELETE de aluno/curso (E33-feature-pack) quando
 * cascade do banco apaga as linhas mas os arquivos físicos ficariam órfãos.
 */
function safe_unlink_storage(string $relPath): void
{
    $relPath = trim($relPath);
    if ($relPath === '') {
        return;
    }
    $base = @realpath(LMS_ROOT . '/storage/uploads');
    if ($base === false) {
        return;
    }
    $abs  = LMS_ROOT . '/' . ltrim($relPath, '/');
    $real = @realpath($abs);
    if ($real !== false && str_starts_with($real, $base)) {
        @unlink($real);
    }
}
