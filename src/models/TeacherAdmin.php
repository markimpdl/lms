<?php
declare(strict_types=1);

/**
 * Listagem administrativa de professores (E2-01).
 *
 * Agrega, a partir da tabela `users`, o tenant do professor (join por
 * `tenants.owner_user_id = u.id`), cursos ativos e alunos únicos matriculados
 * nesses cursos. Filtros, ordenação e paginação são todos parametrizados; a
 * direção e a coluna de ordenação vêm de listas fechadas para evitar SQLi.
 */
final class TeacherAdmin
{
    public const PER_PAGE = 20;

    /** Whitelist de colunas de ORDER BY: chave pública → expressão SQL. */
    private const SORT_COLUMNS = [
        'name'            => 'u.name',
        'email'           => 'u.email',
        'language'        => 'u.language',
        'active'          => 'u.active',
        'created_at'      => 'u.created_at',
        'last_login_at'   => 'u.last_login_at',
        'active_courses'  => 'active_courses',
        'unique_students' => 'unique_students',
    ];

    /**
     * @param array<string,string> $filters Chaves: q, status, language, sort, dir.
     * @param int                  $page    1-based.
     *
     * @return array{
     *   rows: list<array<string,mixed>>,
     *   total: int,
     *   page: int,
     *   total_pages: int,
     *   per_page: int,
     * }
     */
    public static function list(array $filters, int $page): array
    {
        $q      = trim((string) ($filters['q']        ?? ''));
        $status = (string)      ($filters['status']   ?? '');
        $lang   = (string)      ($filters['language'] ?? '');

        $sortKey = (string) ($filters['sort'] ?? 'created_at');
        $sortCol = self::SORT_COLUMNS[$sortKey] ?? self::SORT_COLUMNS['created_at'];
        $dir     = strtoupper((string) ($filters['dir'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';

        $where  = ['u.role = :role'];
        $params = [':role' => 'teacher'];

        if ($q !== '') {
            // Nomes distintos: com ATTR_EMULATE_PREPARES=false o PDO exige
            // um valor por ocorrencia, e reusar `:q` derruba a busca.
            $where[] = '(u.name LIKE :q_name OR u.email LIKE :q_email)';
            $like = '%' . $q . '%';
            $params[':q_name']  = $like;
            $params[':q_email'] = $like;
        }
        if ($status === 'active') {
            $where[] = 'u.active = 1';
        } elseif ($status === 'inactive') {
            $where[] = 'u.active = 0';
        }
        if ($lang === 'pt' || $lang === 'en') {
            $where[] = 'u.language = :lang';
            $params[':lang'] = $lang;
        }
        $whereSql = implode(' AND ', $where);

        $pdo = Database::pdo();

        // COUNT total — todos os filtros atuais batem só em colunas de `users`.
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
                t.is_actvet,
                COUNT(DISTINCT CASE WHEN c.archived = 0 THEN c.id END) AS active_courses,
                COUNT(DISTINCT e.student_user_id)                    AS unique_students
            FROM users u
            LEFT JOIN tenants     t ON t.owner_user_id = u.id
            LEFT JOIN courses     c ON c.tenant_id     = t.id
            LEFT JOIN enrollments e ON e.course_id     = c.id
            WHERE {$whereSql}
            GROUP BY u.id, u.name, u.email, u.language, u.active, u.created_at, u.last_login_at, t.is_actvet
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
     * Retorna um único professor + métricas + dados do tenant dele. Usado pela
     * tela de edição (E2-03). Null se não existir professor com esse id.
     *
     * @return array<string,mixed>|null
     */
    public static function findById(int $userId): ?array
    {
        $sql = <<<SQL
            SELECT
                u.id, u.name, u.email, u.language, u.active,
                u.created_at, u.last_login_at,
                t.id   AS tenant_id,
                t.name AS tenant_name,
                t.is_actvet,
                COUNT(DISTINCT CASE WHEN c.archived = 0 THEN c.id END) AS active_courses,
                COUNT(DISTINCT e.student_user_id)                    AS unique_students
            FROM users u
            LEFT JOIN tenants     t ON t.owner_user_id = u.id
            LEFT JOIN courses     c ON c.tenant_id     = t.id
            LEFT JOIN enrollments e ON e.course_id     = c.id
            WHERE u.id = :id AND u.role = 'teacher'
            GROUP BY u.id, u.name, u.email, u.language, u.active,
                     u.created_at, u.last_login_at, t.id, t.name, t.is_actvet
            LIMIT 1
            SQL;

        $stmt = Database::pdo()->prepare($sql);
        $stmt->bindValue(':id', $userId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }
}
