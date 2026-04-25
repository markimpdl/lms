<?php
declare(strict_types=1);

/**
 * Histórico de conexões bem-sucedidas (E16-04).
 *
 * Cada login bem-sucedido gera 1 row em `user_logins` via
 * `AuthController::completeLogin`. Visível APENAS pelo professor no
 * detalhe do aluno (`/teacher/students/{id}`) — últimas 10. Retenção
 * 180 dias via cron `scripts/cron/purge-old-logins.php`.
 *
 * Spec consolidada em `doc/15-roadmap-pos-mvp.md` F10. LGPD note em `doc/14`.
 */
final class UserLogin
{
    public const RECENT_LIMIT     = 10;
    public const RETENTION_DAYS   = 180;

    /**
     * Insere uma row de login bem-sucedido. Engole Throwable graciosamente
     * — registro de histórico jamais derruba o login (mesmo padrão de
     * tracking silencioso de E14).
     */
    public static function recordLogin(
        int $userId,
        ?int $tenantId,
        string $ip,
        ?string $location,
        ?string $userAgent
    ): void {
        try {
            Database::pdo()
                ->prepare(
                    'INSERT INTO user_logins
                        (user_id, tenant_id, ip, location, user_agent)
                     VALUES (?, ?, ?, ?, ?)'
                )
                ->execute([
                    $userId,
                    $tenantId,
                    $ip,
                    $location,
                    $userAgent !== null ? mb_substr($userAgent, 0, 255) : null,
                ]);
        } catch (\Throwable) {
            // Histórico é best-effort — falha no INSERT não pode quebrar o login.
        }
    }

    /**
     * Últimas N conexões do usuário, mais recentes primeiro.
     *
     * @return list<array<string,mixed>>
     */
    public static function findRecentForUser(int $userId, int $limit = self::RECENT_LIMIT): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT ip, location, user_agent, logged_in_at
               FROM user_logins
              WHERE user_id = ?
              ORDER BY logged_in_at DESC
              LIMIT ?'
        );
        $stmt->bindValue(1, $userId, PDO::PARAM_INT);
        $stmt->bindValue(2, max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Apaga rows com `logged_in_at < NOW() - INTERVAL N DAY`. Idempotente.
     * Usado pelo cron de retenção. Retorna o número de linhas removidas.
     */
    public static function purgeOlderThan(int $days = self::RETENTION_DAYS): int
    {
        $stmt = Database::pdo()->prepare(
            'DELETE FROM user_logins WHERE logged_in_at < (NOW() - INTERVAL ? DAY)'
        );
        $stmt->bindValue(1, max(1, $days), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->rowCount();
    }
}
