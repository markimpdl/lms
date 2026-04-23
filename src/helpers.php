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

    $candidate = $_SESSION['lang']
        ?? (current_user()['language'] ?? null)
        ?? 'pt';

    $resolved = in_array($candidate, $supported, true) ? $candidate : 'pt';
    return $resolved;
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
    $posted  = (string) ($_POST['_csrf'] ?? '');
    $stored  = (string) ($_SESSION['_csrf'] ?? '');
    $expires = (int)    ($_SESSION['_csrf_expires'] ?? 0);

    if ($stored === '' || $posted === '' || $expires < time() || !hash_equals($stored, $posted)) {
        http_response_code(403);
        throw new RuntimeException('CSRF token inválido ou expirado');
    }

    // Rotaciona: próxima request recebe token novo.
    unset($_SESSION['_csrf'], $_SESSION['_csrf_expires']);
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
