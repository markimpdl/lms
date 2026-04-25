<?php
declare(strict_types=1);

/**
 * Lógica de autenticação: rate limit por IP, verificação de senha,
 * regeneração de sessão no login e invalidação no logout.
 */
final class AuthController
{
    public const MAX_ATTEMPTS   = 5;
    public const WINDOW_MINUTES = 15;

    /**
     * True se o IP tem ≥ MAX_ATTEMPTS falhas nos últimos WINDOW_MINUTES minutos.
     */
    public static function isIpBlocked(string $ip): bool
    {
        if ($ip === '') {
            return false;
        }
        $stmt = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM login_attempts
              WHERE ip_address = ?
                AND success = 0
                AND created_at > (NOW() - INTERVAL ? MINUTE)'
        );
        $stmt->execute([$ip, self::WINDOW_MINUTES]);
        return (int) $stmt->fetchColumn() >= self::MAX_ATTEMPTS;
    }

    public static function recordAttempt(string $email, string $ip, bool $success): void
    {
        Database::pdo()->prepare(
            'INSERT INTO login_attempts (email, ip_address, success) VALUES (?, ?, ?)'
        )->execute([$email, $ip, $success ? 1 : 0]);
    }

    /**
     * Retorna o registro do usuário se email+senha baterem e active=1;
     * null caso contrário (não distingue entre email errado/senha errada/inativo).
     * Re-hash oportunista se o cost do bcrypt mudou.
     */
    public static function authenticate(string $email, string $password): ?array
    {
        $pdo  = Database::pdo();
        $stmt = $pdo->prepare(
            'SELECT u.id,
                    COALESCE(t.id, u.tenant_id) AS tenant_id,
                    u.email, u.password_hash, u.password_changed_at,
                    u.name, u.role, u.language, u.active
               FROM users u
               LEFT JOIN tenants t ON t.owner_user_id = u.id AND t.active = 1
              WHERE u.email = ? LIMIT 1'
        );
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || (int) $user['active'] !== 1) {
            return null;
        }

        if (!password_verify($password, (string) $user['password_hash'])) {
            return null;
        }

        if (password_needs_rehash($user['password_hash'], PASSWORD_BCRYPT, ['cost' => 12])) {
            $newHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                ->execute([$newHash, $user['id']]);
        }

        return $user;
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
        $stmt = $pdo->prepare(
            'SELECT id, name, language, active FROM users WHERE email = ? LIMIT 1'
        );
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        if (!$user || (int) $user['active'] !== 1) {
            return;
        }

        // Rate limit: 3 tokens por usuário por hora
        $count = $pdo->prepare(
            'SELECT COUNT(*) FROM password_resets
              WHERE user_id = ? AND created_at > (NOW() - INTERVAL 1 HOUR)'
        );
        $count->execute([$user['id']]);
        if ((int) $count->fetchColumn() >= self::RESET_MAX_PER_HOUR) {
            return;
        }

        $token     = bin2hex(random_bytes(32));          // 64 chars hex
        $tokenHash = hash('sha256', $token);

        $pdo->prepare(
            'INSERT INTO password_resets (user_id, token_hash, expires_at)
             VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))'
        )->execute([$user['id'], $tokenHash, self::RESET_TTL_SECONDS]);

        self::sendResetEmail((string) $email, (string) $user['name'], (string) $user['language'], $token);
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
    private static function sendResetEmail(string $to, string $name, string $language, string $token): void
    {
        $lang = in_array($language, ['pt', 'en'], true) ? $language : 'pt';
        $base = rtrim((string) ($GLOBALS['__ENV']['APP_BASE_URL'] ?? ''), '/');
        $url  = $base . '/reset?token=' . urlencode($token);

        $subject = __t('email.reset.subject', [], $lang);

        $text = __t('email.reset.greeting', ['name' => $name], $lang) . "\n\n"
              . __t('email.reset.intro', [], $lang) . "\n\n"
              . $url . "\n\n"
              . __t('email.reset.expires', ['hours' => 1], $lang) . "\n"
              . __t('email.reset.disregard', [], $lang) . "\n\n"
              . __t('email.reset.signature', [], $lang) . "\n";

        $html = '<!doctype html><html><body style="font-family:system-ui,sans-serif;max-width:560px;margin:0 auto;padding:1rem">'
              . '<p>' . htmlspecialchars(__t('email.reset.greeting', ['name' => $name], $lang), ENT_QUOTES, 'UTF-8') . '</p>'
              . '<p>' . htmlspecialchars(__t('email.reset.intro', [], $lang), ENT_QUOTES, 'UTF-8') . '</p>'
              . '<p><a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" style="display:inline-block;padding:.6rem 1.2rem;background:#0d6efd;color:#fff;border-radius:.4rem;text-decoration:none">'
              . htmlspecialchars(__t('email.reset.cta', [], $lang), ENT_QUOTES, 'UTF-8') . '</a></p>'
              . '<p style="color:#6c757d;font-size:.9rem">' . htmlspecialchars(__t('email.reset.expires', ['hours' => 1], $lang), ENT_QUOTES, 'UTF-8') . '</p>'
              . '<p style="color:#6c757d;font-size:.9rem">' . htmlspecialchars(__t('email.reset.disregard', [], $lang), ENT_QUOTES, 'UTF-8') . '</p>'
              . '<hr><p style="color:#6c757d;font-size:.85rem">' . htmlspecialchars(__t('email.reset.signature', [], $lang), ENT_QUOTES, 'UTF-8') . '</p>'
              . '</body></html>';

        Mailer::send($to, $subject, $html, $text);
    }
}
