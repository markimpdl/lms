<?php
declare(strict_types=1);

/**
 * Model de Aluno (E4-01).
 *
 * Aluno = `users.role = 'student'` + `users.tenant_id = <tenant do professor>`
 * (CHECK constraint `chk_users_role_tenant` força tenant_id NOT NULL para
 * students — espelha ADR-026: aluno é exclusivo do tenant).
 *
 * Toda query filtra por `tenant_id = :tid`; o professor nunca enxerga aluno
 * de outro tenant. Unicidade por `(tenant_id, email)` é garantida pela UK
 * gerada `uk_users_email_tenant` no schema.
 */
final class Student
{
    public const PER_PAGE = 20;

    /** Whitelist de colunas de ORDER BY. */
    private const SORT_COLUMNS = [
        'name'          => 'u.name',
        'email'         => 'u.email',
        'language'      => 'u.language',
        'active'        => 'u.active',
        'created_at'    => 'u.created_at',
        'last_login_at' => 'u.last_login_at',
    ];

    /**
     * @param array<string,string> $filters Chaves: q, status ('active'|'inactive'|'all'), sort, dir.
     * @return array{
     *   rows: list<array<string,mixed>>,
     *   total: int,
     *   page: int,
     *   total_pages: int,
     *   per_page: int,
     * }
     */
    public static function listByTenant(int $tenantId, array $filters, int $page): array
    {
        $q       = trim((string) ($filters['q']      ?? ''));
        $status  = (string)      ($filters['status'] ?? 'active');
        $sortKey = (string)      ($filters['sort']   ?? 'created_at');
        $sortCol = self::SORT_COLUMNS[$sortKey] ?? self::SORT_COLUMNS['created_at'];
        $dir     = strtoupper((string) ($filters['dir'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';

        $where  = ['u.role = :role', 'u.tenant_id = :tid'];
        $params = [':role' => 'student', ':tid' => $tenantId];

        if ($status === 'active') {
            $where[] = 'u.active = 1';
        } elseif ($status === 'inactive') {
            $where[] = 'u.active = 0';
        }
        // 'all' não filtra por active.

        if ($q !== '') {
            $where[] = '(u.name LIKE :q OR u.email LIKE :q)';
            $params[':q'] = '%' . $q . '%';
        }
        $whereSql = implode(' AND ', $where);

        $pdo = Database::pdo();

        $stmtTotal = $pdo->prepare('SELECT COUNT(*) FROM users u WHERE ' . $whereSql);
        $stmtTotal->execute($params);
        $total = (int) $stmtTotal->fetchColumn();

        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));
        $page       = max(1, min($page, $totalPages));
        $offset     = ($page - 1) * self::PER_PAGE;

        $sql = <<<SQL
            SELECT
                u.id, u.name, u.email, u.language, u.active,
                u.created_at, u.last_login_at,
                COUNT(DISTINCT e.course_id) AS enrollments_count,
                COUNT(DISTINCT gm.group_id) AS groups_count
            FROM users u
            LEFT JOIN enrollments   e  ON e.student_user_id  = u.id
            LEFT JOIN group_members gm ON gm.student_user_id = u.id
            WHERE {$whereSql}
            GROUP BY u.id, u.name, u.email, u.language, u.active,
                     u.created_at, u.last_login_at
            ORDER BY {$sortCol} {$dir}, u.id DESC
            LIMIT :limit OFFSET :offset
            SQL;

        $stmt = $pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit',  self::PER_PAGE, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset,        PDO::PARAM_INT);
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
     * Retorna aluno do tenant + contagens, ou null se não pertence.
     *
     * @return array<string,mixed>|null
     */
    public static function findForTenant(int $id, int $tenantId): ?array
    {
        $sql = <<<SQL
            SELECT
                u.id, u.tenant_id, u.name, u.email, u.language, u.active,
                u.created_at, u.last_login_at,
                COUNT(DISTINCT e.course_id) AS enrollments_count,
                COUNT(DISTINCT gm.group_id) AS groups_count
            FROM users u
            LEFT JOIN enrollments   e  ON e.student_user_id  = u.id
            LEFT JOIN group_members gm ON gm.student_user_id = u.id
            WHERE u.id = ? AND u.tenant_id = ? AND u.role = 'student'
            GROUP BY u.id, u.tenant_id, u.name, u.email, u.language, u.active,
                     u.created_at, u.last_login_at
            LIMIT 1
            SQL;
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute([$id, $tenantId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Lista enxuta de alunos ATIVOS do tenant — id + name + email — para
     * popular multi-selects (ex.: atribuir a grupo, E4-04). Ordenada por
     * nome asc.
     *
     * @return list<array{id:int,name:string,email:string}>
     */
    public static function listActiveForSelect(int $tenantId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT id, name, email
               FROM users
              WHERE tenant_id = ? AND role = "student" AND active = 1
              ORDER BY name ASC, id ASC'
        );
        $stmt->execute([$tenantId]);
        $rows = $stmt->fetchAll();
        return array_map(static fn(array $r): array => [
            'id'    => (int) $r['id'],
            'name'  => (string) $r['name'],
            'email' => (string) $r['email'],
        ], $rows);
    }

    /**
     * Insere aluno já com hash pronto. Retorna o id. Caller (service) garante
     * validações de input e colisão de email com teacher/super_admin (ADR-026).
     *
     * @param array{name:string, email:string, password_hash:string, language:string} $data
     */
    public static function create(int $tenantId, array $data): int
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO users (tenant_id, email, password_hash, name, role, language, active)
                  VALUES (?, ?, ?, ?, "student", ?, 1)'
        );
        $stmt->execute([
            $tenantId,
            $data['email'],
            $data['password_hash'],
            $data['name'],
            $data['language'],
        ]);
        return (int) Database::pdo()->lastInsertId();
    }

    /**
     * Atualiza nome/idioma. Email é imutável (ADR-021) — não aceitar no input.
     *
     * @param array{name:string, language:string} $data
     */
    public static function update(int $id, int $tenantId, array $data): bool
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE users SET name = ?, language = ?
              WHERE id = ? AND tenant_id = ? AND role = "student"'
        );
        $stmt->execute([$data['name'], $data['language'], $id, $tenantId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Alterna `active` 0↔1. Middleware de E1-05 expulsa sessões vivas quando
     * vira 0. Retorna o novo valor ou null se aluno não pertence ao tenant.
     */
    public static function toggleActive(int $id, int $tenantId): ?int
    {
        $student = self::findForTenant($id, $tenantId);
        if ($student === null) {
            return null;
        }
        $next = (int) $student['active'] === 1 ? 0 : 1;
        Database::pdo()
            ->prepare('UPDATE users SET active = ? WHERE id = ? AND tenant_id = ?')
            ->execute([$next, $id, $tenantId]);
        return $next;
    }

    /**
     * Exclui aluno após revalidar o email informado (confirmação por digitação,
     * padrão E3-05). Cascade cuida de enrollments, submissions, xp_events,
     * group_members.
     *
     * Retorna: 'ok' | 'not_found' | 'email_mismatch'.
     */
    public static function delete(int $id, int $tenantId, string $expectedEmail): string
    {
        $student = self::findForTenant($id, $tenantId);
        if ($student === null) {
            return 'not_found';
        }
        if ((string) $student['email'] !== $expectedEmail) {
            return 'email_mismatch';
        }
        Database::pdo()
            ->prepare('DELETE FROM users WHERE id = ? AND tenant_id = ? AND role = "student"')
            ->execute([$id, $tenantId]);
        return 'ok';
    }
}
