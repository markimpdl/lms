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
            'SELECT id, tenant_id, email, password_hash, password_changed_at,
                    name, role, language, active
               FROM users WHERE email = ? LIMIT 1'
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
     * Regenera o ID de sessão e grava o payload do usuário autenticado.
     */
    public static function completeLogin(array $user): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $_SESSION['user'] = [
            'id'                  => (int) $user['id'],
            'tenant_id'           => $user['tenant_id'] !== null ? (int) $user['tenant_id'] : null,
            'email'               => (string) $user['email'],
            'name'                => (string) $user['name'],
            'role'                => (string) $user['role'],
            'language'            => (string) ($user['language'] ?? 'pt'),
            'password_changed_at' => $user['password_changed_at'] ?? null,
        ];
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
}
