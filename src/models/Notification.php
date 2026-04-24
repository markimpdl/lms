<?php
declare(strict_types=1);

/**
 * Model da tabela `notifications` (E10-00).
 *
 * Uma notificação é 1:1 com destinatário — a mesma informação enviada pra N
 * usuários gera N linhas. O model é agnóstico de evento; quem decide
 * `type` / `title` / `body` / `link` é o `NotificationService::fanout`.
 *
 * `notifications` não tem `tenant_id` — o isolamento é por `user_id` do
 * destinatário (usuário logado), validado em todo read/update via
 * `WHERE user_id = ?`.
 */
final class Notification
{
    /** Insere uma notificação. Retorna o id criado. */
    public static function create(
        int $userId,
        string $type,
        string $title,
        ?string $body,
        ?string $link
    ): int {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO notifications (user_id, type, title, body, link)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$userId, $type, $title, $body, $link]);
        return (int) Database::pdo()->lastInsertId();
    }

    /** Conta quantas não-lidas o usuário tem. Alimenta o badge do sino (E10-01). */
    public static function countUnread(int $userId): int
    {
        $stmt = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM notifications
              WHERE user_id = ? AND read_at IS NULL'
        );
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    }

    /** Conta total (lidas + não-lidas) do usuário. Usado na paginação. */
    public static function countForUser(int $userId): int
    {
        $stmt = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM notifications WHERE user_id = ?'
        );
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Lista as notificações do usuário, mais recente primeiro.
     *
     * LIMIT/OFFSET são concatenados como int (seguro): PDO com
     * `ATTR_EMULATE_PREPARES=false` não aceita placeholder pra LIMIT.
     *
     * @return list<array<string,mixed>>
     */
    public static function findForUser(int $userId, int $limit, int $offset = 0): array
    {
        $limit  = max(0, $limit);
        $offset = max(0, $offset);
        $stmt = Database::pdo()->prepare(
            'SELECT id, type, title, body, link, read_at, created_at
               FROM notifications
              WHERE user_id = ?
              ORDER BY created_at DESC, id DESC
              LIMIT ' . $limit . ' OFFSET ' . $offset
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    /**
     * Busca 1 notificação validando ownership. Usado no mark-read antes do
     * redirect (E10-01) — precisa do `link` pra saber pra onde mandar.
     *
     * @return array<string,mixed>|null
     */
    public static function findByIdForUser(int $id, int $userId): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT id, type, title, body, link, read_at, created_at
               FROM notifications
              WHERE id = ? AND user_id = ?
              LIMIT 1'
        );
        $stmt->execute([$id, $userId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Marca 1 notificação como lida. Idempotente (só atualiza se ainda não
     * estiver lida). Valida ownership via `user_id`. Retorna true quando o
     * status mudou — false se não encontrou, não era do usuário, ou já lida.
     */
    public static function markRead(int $id, int $userId): bool
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE notifications
                SET read_at = NOW()
              WHERE id = ? AND user_id = ? AND read_at IS NULL'
        );
        $stmt->execute([$id, $userId]);
        return $stmt->rowCount() > 0;
    }

    /** Marca todas as não-lidas do usuário. Retorna quantas foram afetadas. */
    public static function markAllRead(int $userId): int
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE notifications
                SET read_at = NOW()
              WHERE user_id = ? AND read_at IS NULL'
        );
        $stmt->execute([$userId]);
        return $stmt->rowCount();
    }
}
