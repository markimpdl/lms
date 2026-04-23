<?php
declare(strict_types=1);

/**
 * Model de membership de grupo (E4-04).
 *
 * Liga `users.role='student'` a `groups.id` via tabela `group_members`,
 * com PK composta `(group_id, student_user_id)` que torna a adição
 * idempotente — re-adicionar o mesmo par é no-op silencioso (INSERT IGNORE
 * absorve a PK).
 *
 * Toda operação valida tenant: aluno e grupo precisam pertencer ao mesmo
 * tenant do professor logado. Cross-tenant é bloqueado a seco (retorno
 * 'wrong_tenant') — nunca vaza presença cross-tenant.
 *
 * Tabela `groups` é palavra reservada no MySQL 8 → backticks obrigatórios.
 */
final class GroupMember
{
    public const PER_PAGE = 20;

    /**
     * Lista grupos em que o aluno é membro, ordenados pelo nome do grupo.
     * Limita ao tenant informado (aluno e grupo precisam pertencer a ele).
     *
     * @return list<array<string,mixed>>
     */
    public static function listByStudent(int $studentId, int $tenantId): array
    {
        $sql = <<<SQL
            SELECT gm.group_id, gm.joined_at,
                   g.name
              FROM group_members gm
              JOIN `groups` g ON g.id = gm.group_id
              JOIN users    u ON u.id = gm.student_user_id
             WHERE gm.student_user_id = ?
               AND u.tenant_id = ?
               AND g.tenant_id = ?
               AND u.role = "student"
             ORDER BY g.name ASC, g.id ASC
            SQL;
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute([$studentId, $tenantId, $tenantId]);
        return $stmt->fetchAll();
    }

    /**
     * Lista membros de um grupo, paginado. Só retorna quando o grupo
     * pertence ao tenant do professor.
     *
     * @return array{
     *   rows: list<array<string,mixed>>,
     *   total: int,
     *   page: int,
     *   total_pages: int,
     *   per_page: int,
     * }
     */
    public static function listByGroup(int $groupId, int $tenantId, int $page): array
    {
        $pdo = Database::pdo();

        $stmtTotal = $pdo->prepare(
            'SELECT COUNT(*)
               FROM group_members gm
               JOIN users    u ON u.id = gm.student_user_id
               JOIN `groups` g ON g.id = gm.group_id
              WHERE gm.group_id = ?
                AND g.tenant_id = ?
                AND u.tenant_id = ?
                AND u.role = "student"'
        );
        $stmtTotal->execute([$groupId, $tenantId, $tenantId]);
        $total = (int) $stmtTotal->fetchColumn();

        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));
        $page       = max(1, min($page, $totalPages));
        $offset     = ($page - 1) * self::PER_PAGE;

        // Placeholders posicionais em tudo (ver fix do E4-02): PDO com
        // ATTR_EMULATE_PREPARES=false não aceita mistura de `?` e `:nome`.
        $sql = <<<SQL
            SELECT u.id AS student_id, u.name, u.email, u.language, u.active,
                   gm.joined_at
              FROM group_members gm
              JOIN users    u ON u.id = gm.student_user_id
              JOIN `groups` g ON g.id = gm.group_id
             WHERE gm.group_id = ?
               AND g.tenant_id = ?
               AND u.tenant_id = ?
               AND u.role = "student"
             ORDER BY u.name ASC, u.id ASC
             LIMIT ? OFFSET ?
            SQL;

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(1, $groupId,        PDO::PARAM_INT);
        $stmt->bindValue(2, $tenantId,       PDO::PARAM_INT);
        $stmt->bindValue(3, $tenantId,       PDO::PARAM_INT);
        $stmt->bindValue(4, self::PER_PAGE,  PDO::PARAM_INT);
        $stmt->bindValue(5, $offset,         PDO::PARAM_INT);
        $stmt->execute();

        return [
            'rows'        => $stmt->fetchAll(),
            'total'       => $total,
            'page'        => $page,
            'total_pages' => $totalPages,
            'per_page'    => self::PER_PAGE,
        ];
    }

    /**
     * Adiciona aluno ao grupo após validações de tenant e estado. Retorna:
     *   'ok'                — adicionado (ou já era membro: PK absorve o INSERT IGNORE)
     *   'wrong_tenant'      — aluno ou grupo não pertence ao tenant
     *   'student_inactive'  — aluno existe no tenant mas está desativado
     */
    public static function add(int $groupId, int $studentId, int $tenantId): string
    {
        $pdo = Database::pdo();

        $stmt = $pdo->prepare(
            'SELECT u.active AS s_active
               FROM users u
               JOIN `groups` g ON g.tenant_id = u.tenant_id
              WHERE u.id = ?
                AND g.id = ?
                AND u.tenant_id = ?
                AND u.role = "student"
              LIMIT 1'
        );
        $stmt->execute([$studentId, $groupId, $tenantId]);
        $row = $stmt->fetch();
        if ($row === false) {
            return 'wrong_tenant';
        }
        if ((int) $row['s_active'] === 0) {
            return 'student_inactive';
        }

        // INSERT IGNORE absorve a PK composta (group_id, student_user_id) e
        // torna a operação idempotente: re-adicionar é no-op silencioso.
        $pdo->prepare(
            'INSERT IGNORE INTO group_members (group_id, student_user_id)
                  VALUES (?, ?)'
        )->execute([$groupId, $studentId]);

        return 'ok';
    }

    /**
     * Remove membership. Valida apenas que aluno e grupo pertencem ao tenant
     * informado. Retorna true se alguma linha foi removida.
     */
    public static function remove(int $groupId, int $studentId, int $tenantId): bool
    {
        $stmt = Database::pdo()->prepare(
            'DELETE gm FROM group_members gm
               JOIN users    u ON u.id = gm.student_user_id
               JOIN `groups` g ON g.id = gm.group_id
              WHERE gm.group_id = ?
                AND gm.student_user_id = ?
                AND u.tenant_id = ?
                AND g.tenant_id = ?
                AND u.role = "student"'
        );
        $stmt->execute([$groupId, $studentId, $tenantId, $tenantId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Ids de alunos que JÁ são membros de um grupo — usado para esconder
     * opções repetidas no multi-select de adicionar.
     *
     * @return list<int>
     */
    public static function memberIds(int $groupId, int $tenantId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT gm.student_user_id
               FROM group_members gm
               JOIN users    u ON u.id = gm.student_user_id
               JOIN `groups` g ON g.id = gm.group_id
              WHERE gm.group_id = ?
                AND u.tenant_id = ?
                AND g.tenant_id = ?
                AND u.role = "student"'
        );
        $stmt->execute([$groupId, $tenantId, $tenantId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Ids de grupos em que um aluno já é membro — para esconder no
     * multi-select de atribuir da tela do aluno.
     *
     * @return list<int>
     */
    public static function groupIdsForStudent(int $studentId, int $tenantId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT gm.group_id
               FROM group_members gm
               JOIN users    u ON u.id = gm.student_user_id
               JOIN `groups` g ON g.id = gm.group_id
              WHERE gm.student_user_id = ?
                AND u.tenant_id = ?
                AND g.tenant_id = ?
                AND u.role = "student"'
        );
        $stmt->execute([$studentId, $tenantId, $tenantId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}
