<?php
declare(strict_types=1);

/**
 * Lógica de autenticação: rate limit de login, verificação de senha,
 * regeneração de sessão no login e invalidação no logout.
 */
final class AuthController
{
    /** Falhas tolerada por CONTA (email) na janela antes de bloquear. */
    public const MAX_ATTEMPTS = 5;

    /**
     * Teto por IP na janela. Alto de propósito: em curso presencial a turma
     * inteira sai pelo mesmo IP público (NAT da escola), então um teto baixo
     * por IP bloqueia alunos que nem erraram a senha. Serve só como freio
     * contra força bruta em volume — que faz milhares de tentativas, não 100.
     */
    public const MAX_ATTEMPTS_IP = 100;

    public const WINDOW_MINUTES = 15;

    /** Retenção de `login_attempts` (dias) — purgada pelo cron diário. */
    public const ATTEMPTS_RETENTION_DAYS = 30;

    /**
     * True se as credenciais devem ser recusadas sem sequer consultar `users`.
     *
     * Bloqueia quando a CONTA acumulou MAX_ATTEMPTS falhas na janela, ou
     * quando o IP passou de MAX_ATTEMPTS_IP falhas. O bloqueio por conta é a
     * proteção real (isola quem errou a senha); o por IP é anti-força-bruta.
     */
    public static function isBlocked(string $email, string $ip): bool
    {
        if ($email !== '' && self::failuresFor('email', $email) >= self::MAX_ATTEMPTS) {
            return true;
        }
        return $ip !== '' && self::failuresFor('ip_address', $ip) >= self::MAX_ATTEMPTS_IP;
    }

    /**
     * Falhas na janela para uma coluna indexada de `login_attempts`.
     *
     * @param 'email'|'ip_address' $column Nome literal — nunca vem de input.
     */
    private static function failuresFor(string $column, string $value): int
    {
        $stmt = Database::pdo()->prepare(
            "SELECT COUNT(*) FROM login_attempts
              WHERE {$column} = ?
                AND success = 0
                AND created_at > (NOW() - INTERVAL ? MINUTE)"
        );
        $stmt->execute([$value, self::WINDOW_MINUTES]);
        return (int) $stmt->fetchColumn();
    }

    public static function recordAttempt(string $email, string $ip, bool $success): void
    {
        Database::pdo()->prepare(
            'INSERT INTO login_attempts (email, ip_address, success) VALUES (?, ?, ?)'
        )->execute([$email, $ip, $success ? 1 : 0]);

        // Credencial correta zera o histórico de falhas da conta: quem acabou
        // de provar que sabe a senha não pode entrar bloqueado no próximo
        // typo por causa de tentativas antigas ainda dentro da janela.
        if ($success && $email !== '') {
            self::clearFailures($email);
        }
    }

    /** Remove as falhas registradas para o email (chamado após login OK). */
    public static function clearFailures(string $email): void
    {
        Database::pdo()
            ->prepare('DELETE FROM login_attempts WHERE email = ? AND success = 0')
            ->execute([$email]);
    }

    /**
     * Apaga tentativas mais velhas que ATTEMPTS_RETENTION_DAYS.
     * Retorna o número de rows removidas. Idempotente.
     */
    public static function purgeOldAttempts(): int
    {
        $stmt = Database::pdo()->prepare(
            'DELETE FROM login_attempts WHERE created_at < (NOW() - INTERVAL ? DAY)'
        );
        $stmt->execute([self::ATTEMPTS_RETENTION_DAYS]);
        return $stmt->rowCount();
    }

    /**
     * Retorna o registro do usuário se email+senha baterem e active=1;
     * null caso contrário. Wrapper sobre `authenticateAll` que aceita só
     * o caso 1-conta (preserva contrato pré-E22). Quando o email aparece
     * em 2+ tenants distintos como aluno (ADR-026 permite), retorna null
     * — caller deve usar `authenticateAll` pra desambiguar.
     *
     * @deprecated Mantido pra backward compat; use authenticateAll.
     */
    public static function authenticate(string $email, string $password): ?array
    {
        $candidates = self::authenticateAll($email, $password);
        return count($candidates) === 1 ? $candidates[0] : null;
    }

    /**
     * Lista de rows que validaram com aquele email+senha (E22-01 — F13).
     * Retorna lista vazia em 0 matches, 1 elemento no caso comum, 2+ quando
     * aluno tem email replicado em N tenants (ADR-026) com a MESMA senha.
     *
     * Re-hash oportunista por row — só dispara pra rows que efetivamente
     * autenticaram (não pode ser pra a row "errada" do ambiente ambíguo).
     *
     * @return list<array<string,mixed>>
     */
    public static function authenticateAll(string $email, string $password): array
    {
        $pdo  = Database::pdo();
        // Sem LIMIT 1 — pode haver N rows quando aluno está em N tenants.
        // LEFT JOIN tenants pega tenant_id do professor (que tem u.tenant_id NULL
        // mas é owner via tenants.owner_user_id). Pra aluno, JOIN não bate
        // (aluno não é owner) → COALESCE cai pra u.tenant_id.
        $stmt = $pdo->prepare(
            'SELECT u.id,
                    COALESCE(t.id, u.tenant_id) AS tenant_id,
                    u.email, u.password_hash, u.password_changed_at,
                    u.name, u.role, u.language, u.theme, u.active
               FROM users u
               LEFT JOIN tenants t ON t.owner_user_id = u.id AND t.active = 1
              WHERE u.email = ? AND u.active = 1'
        );
        $stmt->execute([$email]);
        $rows = $stmt->fetchAll();

        $candidates = [];
        foreach ($rows as $row) {
            if (!password_verify($password, (string) $row['password_hash'])) {
                continue;
            }
            // Re-hash oportunista por row autenticada.
            if (password_needs_rehash($row['password_hash'], PASSWORD_BCRYPT, ['cost' => 12])) {
                $newHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
                $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                    ->execute([$newHash, $row['id']]);
            }
            $candidates[] = $row;
        }
        return $candidates;
    }

    /**
     * Pra UI do seletor de tenant (E22-01): dado uma lista de user_ids
     * (validados via authenticateAll + guardados na sessão), retorna
     * a estrutura de display `[{user_id, tenant_id, tenant_name,
     * teacher_name}]` pra renderizar os cards.
     *
     * Faz 1 query por user (volume baixo — N normalmente é 2-3).
     *
     * @param list<int> $userIds
     * @return list<array{user_id:int, tenant_id:int, tenant_name:string, teacher_name:string}>
     */
    public static function tenantPickerDisplay(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $stmt = Database::pdo()->prepare(
            'SELECT u.id AS user_id, u.tenant_id,
                    t.name AS tenant_name,
                    owner.name AS teacher_name
               FROM users u
               JOIN tenants t        ON t.id = u.tenant_id AND t.active = 1
               JOIN users   owner    ON owner.id = t.owner_user_id
              WHERE u.id IN (' . $placeholders . ')
                AND u.role = "student"
              ORDER BY t.name ASC'
        );
        $stmt->execute(array_map('intval', $userIds));
        $rows = $stmt->fetchAll();

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'user_id'      => (int) $r['user_id'],
                'tenant_id'    => (int) $r['tenant_id'],
                'tenant_name'  => (string) $r['tenant_name'],
                'teacher_name' => (string) $r['teacher_name'],
            ];
        }
        return $out;
    }

    /**
     * Carrega user row completa por id pra `completeLogin` na fase 2 do
     * seletor (após o aluno escolher qual conta entrar). Defensivo:
     * inativos retornam null. Mesma estrutura de authenticate (com
     * tenant_id resolvido via COALESCE).
     */
    public static function loadActiveUserById(int $userId): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT u.id,
                    COALESCE(t.id, u.tenant_id) AS tenant_id,
                    u.email, u.password_hash, u.password_changed_at,
                    u.name, u.role, u.language, u.theme, u.active
               FROM users u
               LEFT JOIN tenants t ON t.owner_user_id = u.id AND t.active = 1
              WHERE u.id = ? AND u.active = 1
              LIMIT 1'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Regenera o ID de sessão, marca o login em `users.last_login_at` e grava
     * o payload do usuário autenticado. Também registra a conexão em
     * `user_logins` (E16-04) com geo-IP best-effort — falha aqui é silenciosa,
     * jamais derruba o login.
     */
    public static function completeLogin(array $user): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        // Registra último login para a listagem administrativa (E2-01).
        Database::pdo()
            ->prepare('UPDATE users SET last_login_at = CURRENT_TIMESTAMP WHERE id = ?')
            ->execute([(int) $user['id']]);

        $_SESSION['user'] = [
            'id'                  => (int) $user['id'],
            'tenant_id'           => $user['tenant_id'] !== null ? (int) $user['tenant_id'] : null,
            'email'               => (string) $user['email'],
            'name'                => (string) $user['name'],
            'role'                => (string) $user['role'],
            'language'            => (string) ($user['language'] ?? 'pt'),
            'theme'               => (string) ($user['theme']    ?? 'light'),
            'password_changed_at' => $user['password_changed_at'] ?? null,
        ];

        // Histórico de conexões (E16-04). Best-effort: model + service engolem
        // Throwable. Geo-IP é uma chamada HTTP de até 3s; se ip-api.com estiver
        // fora ou rate-limited, location fica NULL.
        $ip       = self::clientIp();
        $userAgent = isset($_SERVER['HTTP_USER_AGENT'])
            ? (string) $_SERVER['HTTP_USER_AGENT']
            : null;
        $location = $ip !== '' ? GeoIPClient::lookup($ip) : null;
        UserLogin::recordLogin(
            (int) $user['id'],
            $user['tenant_id'] !== null ? (int) $user['tenant_id'] : null,
            $ip,
            $location,
            $userAgent
        );
    }

    /**
     * IP do cliente, preferindo X-Forwarded-For (primeira entrada válida) e
     * caindo para REMOTE_ADDR. Retorna string vazia se nenhum IP válido.
     */
    private static function clientIp(): string
    {
        $xff = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($xff !== '') {
            $first = trim((string) (explode(',', $xff)[0] ?? ''));
            if ($first !== '' && filter_var($first, FILTER_VALIDATE_IP) !== false) {
                return $first;
            }
        }
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        return $remote !== '' && filter_var($remote, FILTER_VALIDATE_IP) !== false
            ? $remote
            : '';
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path']     ?? '/',
                $params['domain']   ?? '',
                !empty($params['secure']),
                !empty($params['httponly'])
            );
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    public static function dashboardFor(string $role): string
    {
        return match ($role) {
            'super_admin' => '/admin',
            'teacher'     => '/teacher',
            'student'     => '/student',
            default       => '/',
        };
    }

    /**
     * Sanitiza o ?next= para evitar open redirect: só aceita path interno
     * começando por "/" seguido de caractere não-"/".
     */
    public static function safeNext(?string $next): ?string
    {
        if ($next === null || $next === '') {
            return null;
        }
        return preg_match('#^/[^/]#', $next) === 1 ? $next : null;
    }

    // ----- Password reset (E1-03) ----------------------------------------

    public const RESET_TTL_SECONDS    = 3600; // 1 hora
    public const RESET_MAX_PER_HOUR   = 3;

    /**
     * Gera um token de reset de senha para $email (se usuário existe,
     * está ativo e não excedeu o rate limit de 3 por hora). Sempre silencioso
     * — a resposta da página nunca revela se o email existe ou não.
     */
    public static function requestPasswordReset(string $email): void
    {
        $pdo  = Database::pdo();
        // E22-02: SEM LIMIT 1 — pode haver N rows quando aluno tem email
        // replicado em múltiplos tenants (ADR-026 permite). Cada row vira
        // um token próprio + entra como item na lista de cards do email.
        $stmt = $pdo->prepare(
            'SELECT id, name, language FROM users
              WHERE email = ? AND active = 1'
        );
        $stmt->execute([$email]);
        $rows = $stmt->fetchAll();
        if ($rows === []) {
            return;
        }

        $accounts = [];
        foreach ($rows as $user) {
            $userId = (int) $user['id'];

            // Rate limit: 3 tokens por usuário por hora. Skip individual.
            $count = $pdo->prepare(
                'SELECT COUNT(*) FROM password_resets
                  WHERE user_id = ? AND created_at > (NOW() - INTERVAL 1 HOUR)'
            );
            $count->execute([$userId]);
            if ((int) $count->fetchColumn() >= self::RESET_MAX_PER_HOUR) {
                continue;
            }

            $token     = bin2hex(random_bytes(32));          // 64 chars hex
            $tokenHash = hash('sha256', $token);

            $pdo->prepare(
                'INSERT INTO password_resets (user_id, token_hash, expires_at)
                 VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))'
            )->execute([$userId, $tokenHash, self::RESET_TTL_SECONDS]);

            $accounts[] = [
                'user_id'  => $userId,
                'name'     => (string) $user['name'],
                'language' => (string) $user['language'],
                'token'    => $token,
            ];
        }

        if ($accounts === []) {
            return; // todos rate-limited
        }

        // Enriquece com tenant_name + teacher_name pros cards do email (só quando há tenant — alunos).
        $accounts = self::enrichResetAccountsWithTenant($accounts);

        self::sendResetEmail($email, $accounts);
    }

    /**
     * Pra cada account com tenant_id (alunos), busca tenant_name +
     * teacher_name (owner do tenant). Teachers não têm tenant — viram só
     * `['tenant_name' => null, 'teacher_name' => null]` (template detecta
     * e não renderiza card decoration).
     *
     * @param list<array<string,mixed>> $accounts
     * @return list<array<string,mixed>> mesma lista enriquecida
     */
    private static function enrichResetAccountsWithTenant(array $accounts): array
    {
        $userIds = array_map(static fn ($a): int => (int) $a['user_id'], $accounts);
        if ($userIds === []) {
            return $accounts;
        }
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $stmt = Database::pdo()->prepare(
            'SELECT u.id AS user_id, t.name AS tenant_name,
                    owner.name AS teacher_name
               FROM users u
               LEFT JOIN tenants t      ON t.id = u.tenant_id AND t.active = 1
               LEFT JOIN users   owner  ON owner.id = t.owner_user_id
              WHERE u.id IN (' . $placeholders . ')'
        );
        $stmt->execute(array_map('intval', $userIds));
        $byUser = [];
        foreach ($stmt->fetchAll() as $r) {
            $byUser[(int) $r['user_id']] = [
                'tenant_name'  => $r['tenant_name']  !== null ? (string) $r['tenant_name']  : null,
                'teacher_name' => $r['teacher_name'] !== null ? (string) $r['teacher_name'] : null,
            ];
        }
        foreach ($accounts as $i => $acc) {
            $info = $byUser[(int) $acc['user_id']] ?? ['tenant_name' => null, 'teacher_name' => null];
            $accounts[$i]['tenant_name']  = $info['tenant_name'];
            $accounts[$i]['teacher_name'] = $info['teacher_name'];
        }
        return $accounts;
    }

    /**
     * Retorna o user_id se o token for válido (existe, não expirou, não foi usado);
     * null caso contrário. NÃO marca como usado — isso é feito em consume().
     */
    public static function validateResetToken(string $token): ?int
    {
        if ($token === '' || strlen($token) !== 64 || !ctype_xdigit($token)) {
            return null;
        }
        $tokenHash = hash('sha256', $token);
        $stmt = Database::pdo()->prepare(
            'SELECT user_id FROM password_resets
              WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW()
              LIMIT 1'
        );
        $stmt->execute([$tokenHash]);
        $userId = $stmt->fetchColumn();
        return $userId !== false ? (int) $userId : null;
    }

    /**
     * Aplica a nova senha e marca o token como usado. Invalida também
     * quaisquer outros tokens pendentes do mesmo usuário.
     * Retorna true se o token era válido e a senha foi atualizada.
     */
    public static function consumePasswordResetToken(string $token, string $newPassword): bool
    {
        if (strlen($newPassword) < 8) {
            return false;
        }

        return (bool) Database::tx(static function (PDO $pdo) use ($token, $newPassword): bool {
            $tokenHash = hash('sha256', $token);

            // Lock da linha impede race com outra request consumindo o mesmo token
            $stmt = $pdo->prepare(
                'SELECT id, user_id FROM password_resets
                  WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW()
                  LIMIT 1 FOR UPDATE'
            );
            $stmt->execute([$tokenHash]);
            $row = $stmt->fetch();
            if (!$row) {
                return false;
            }

            $hash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);

            $pdo->prepare(
                'UPDATE users SET password_hash = ?, password_changed_at = CURRENT_TIMESTAMP
                  WHERE id = ?'
            )->execute([$hash, $row['user_id']]);

            $pdo->prepare(
                'UPDATE password_resets SET used_at = CURRENT_TIMESTAMP WHERE id = ?'
            )->execute([$row['id']]);

            // Invalida outros tokens pendentes do mesmo usuário (defesa em profundidade).
            $pdo->prepare(
                'UPDATE password_resets SET used_at = CURRENT_TIMESTAMP
                  WHERE user_id = ? AND used_at IS NULL'
            )->execute([$row['user_id']]);

            return true;
        });
    }

    /**
     * Monta o email (HTML + texto) no idioma do destinatário (ADR-014)
     * e entrega ao Mailer. URL absoluta montada a partir de APP_BASE_URL.
     */
    /**
     * Renderiza e envia o email de reset (E22-02 — F13). Aceita lista de
     * accounts; quando lista tem 1 elemento, formato é idêntico ao pré-E22.
     * Quando 2+, renderiza intro multi-conta + um card por conta (tenant
     * name + link próprio).
     *
     * Idioma do email: usa o language da primeira conta (defensivo —
     * accounts em múltiplos tenants podem ter idiomas diferentes; uma
     * escolha consistente mantém o template renderizável).
     *
     * @param list<array{user_id:int, name:string, language:string, token:string, tenant_name:?string, teacher_name:?string}> $accounts
     */
    private static function sendResetEmail(string $to, array $accounts): void
    {
        if ($accounts === []) {
            return;
        }
        $primary = $accounts[0];
        $lang = in_array($primary['language'], ['pt', 'en'], true) ? $primary['language'] : 'pt';
        $base = rtrim((string) ($GLOBALS['__ENV']['APP_BASE_URL'] ?? ''), '/');
        $isMulti = count($accounts) > 1;

        $subject = __t('email.reset.subject', [], $lang);

        // Pré-monta os links + textos por conta (usado em ambos text/html paths).
        $cards = [];
        foreach ($accounts as $acc) {
            $url   = $base . '/reset?token=' . urlencode((string) $acc['token']);
            $label = $acc['tenant_name'] !== null
                ? (string) $acc['tenant_name']
                : __t('email.reset.account_default_label', [], $lang);
            $teacherLine = $acc['teacher_name'] !== null
                ? __t('email.reset.teacher_label', ['name' => (string) $acc['teacher_name']], $lang)
                : '';
            $cards[] = [
                'label'       => $label,
                'teacher'     => $teacherLine,
                'url'         => $url,
            ];
        }

        // ---------------- TEXT ----------------
        $text = __t('email.reset.greeting', ['name' => (string) $primary['name']], $lang) . "\n\n"
              . __t($isMulti ? 'email.reset.multi_intro' : 'email.reset.intro', [], $lang) . "\n\n";
        foreach ($cards as $c) {
            if ($isMulti) {
                $text .= '— ' . $c['label'] . "\n";
                if ($c['teacher'] !== '') {
                    $text .= '  ' . $c['teacher'] . "\n";
                }
                $text .= '  ' . $c['url'] . "\n\n";
            } else {
                $text .= $c['url'] . "\n\n";
            }
        }
        $text .= __t('email.reset.expires', ['hours' => 1], $lang) . "\n"
              .  __t('email.reset.disregard', [], $lang) . "\n\n"
              .  __t('email.reset.signature', [], $lang) . "\n";

        // ---------------- HTML ----------------
        $html = '<!doctype html><html><body style="font-family:system-ui,sans-serif;max-width:560px;margin:0 auto;padding:1rem">'
              . '<p>' . htmlspecialchars(__t('email.reset.greeting', ['name' => (string) $primary['name']], $lang), ENT_QUOTES, 'UTF-8') . '</p>'
              . '<p>' . htmlspecialchars(__t($isMulti ? 'email.reset.multi_intro' : 'email.reset.intro', [], $lang), ENT_QUOTES, 'UTF-8') . '</p>';

        foreach ($cards as $c) {
            if ($isMulti) {
                $html .= '<div style="border:1px solid #dee2e6;border-radius:.4rem;padding:.75rem 1rem;margin-bottom:.75rem">'
                       . '<strong style="display:block;margin-bottom:.25rem">' . htmlspecialchars($c['label'], ENT_QUOTES, 'UTF-8') . '</strong>';
                if ($c['teacher'] !== '') {
                    $html .= '<small style="color:#6c757d;display:block;margin-bottom:.5rem">' . htmlspecialchars($c['teacher'], ENT_QUOTES, 'UTF-8') . '</small>';
                }
                $html .= '<a href="' . htmlspecialchars($c['url'], ENT_QUOTES, 'UTF-8') . '" style="display:inline-block;padding:.5rem 1rem;background:#0d6efd;color:#fff;border-radius:.4rem;text-decoration:none;font-size:.9rem">'
                       . htmlspecialchars(__t('email.reset.cta', [], $lang), ENT_QUOTES, 'UTF-8') . '</a>'
                       . '</div>';
            } else {
                $html .= '<p><a href="' . htmlspecialchars($c['url'], ENT_QUOTES, 'UTF-8') . '" style="display:inline-block;padding:.6rem 1.2rem;background:#0d6efd;color:#fff;border-radius:.4rem;text-decoration:none">'
                       . htmlspecialchars(__t('email.reset.cta', [], $lang), ENT_QUOTES, 'UTF-8') . '</a></p>';
            }
        }

        $html .= '<p style="color:#6c757d;font-size:.9rem">' . htmlspecialchars(__t('email.reset.expires', ['hours' => 1], $lang), ENT_QUOTES, 'UTF-8') . '</p>'
              .  '<p style="color:#6c757d;font-size:.9rem">' . htmlspecialchars(__t('email.reset.disregard', [], $lang), ENT_QUOTES, 'UTF-8') . '</p>'
              .  '<hr><p style="color:#6c757d;font-size:.85rem">' . htmlspecialchars(__t('email.reset.signature', [], $lang), ENT_QUOTES, 'UTF-8') . '</p>'
              .  '</body></html>';

        Mailer::send($to, $subject, $html, $text);
    }
}
