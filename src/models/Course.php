<?php
declare(strict_types=1);

/**
 * Model de Curso (E3-01).
 *
 * Todas as queries filtram por `tenant_id` — o professor logado nunca consulta
 * dados de outro tenant. `findForTenant()` devolve `null` se o curso não pertence
 * ao tenant informado, para o caller traduzir em 404 amigável.
 */
final class Course
{
    public const PER_PAGE = 20;

    /** Whitelist de colunas de ORDER BY: chave pública → expressão SQL. */
    private const SORT_COLUMNS = [
        'name'       => 'c.name',
        'year'       => 'c.year',
        'created_at' => 'c.created_at',
    ];

    /**
     * @param array<string,string> $filters Chaves: q, status ('active'|'archived'|'all'), sort, dir.
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

        $where  = ['c.tenant_id = :tid'];
        $params = [':tid' => $tenantId];

        if ($status === 'active') {
            $where[] = 'c.archived = 0';
        } elseif ($status === 'archived') {
            $where[] = 'c.archived = 1';
        }
        // 'all' não adiciona filtro.

        if ($q !== '') {
            $where[] = '(c.name LIKE :q OR c.description LIKE :q)';
            $params[':q'] = '%' . $q . '%';
        }
        $whereSql = implode(' AND ', $where);

        $pdo = Database::pdo();

        $stmtTotal = $pdo->prepare('SELECT COUNT(*) FROM courses c WHERE ' . $whereSql);
        $stmtTotal->execute($params);
        $total = (int) $stmtTotal->fetchColumn();

        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));
        $page       = max(1, min($page, $totalPages));
        $offset     = ($page - 1) * self::PER_PAGE;

        $sql = <<<SQL
            SELECT
                c.id, c.name, c.description, c.year, c.language,
                c.archived, c.archived_at, c.created_at, c.updated_at,
                COUNT(DISTINCT cc.id) AS cc_count,
                COUNT(DISTINCT cu.id) AS cu_count
            FROM courses c
            LEFT JOIN core_competencies cc ON cc.course_id = c.id
            LEFT JOIN competence_units  cu ON cu.core_competency_id = cc.id
            WHERE {$whereSql}
            GROUP BY c.id, c.name, c.description, c.year, c.language,
                     c.archived, c.archived_at, c.created_at, c.updated_at
            ORDER BY {$sortCol} {$dir}, c.id DESC
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
     * Retorna curso do tenant (com contagens), ou null se não pertence.
     * @return array<string,mixed>|null
     */
    public static function findForTenant(int $courseId, int $tenantId): ?array
    {
        $sql = <<<SQL
            SELECT
                c.id, c.tenant_id, c.name, c.description, c.year, c.language,
                c.archived, c.archived_at, c.created_at, c.updated_at,
                COUNT(DISTINCT cc.id) AS cc_count,
                COUNT(DISTINCT cu.id) AS cu_count
            FROM courses c
            LEFT JOIN core_competencies cc ON cc.course_id = c.id
            LEFT JOIN competence_units  cu ON cu.core_competency_id = cc.id
            WHERE c.id = :id AND c.tenant_id = :tid
            GROUP BY c.id, c.tenant_id, c.name, c.description, c.year, c.language,
                     c.archived, c.archived_at, c.created_at, c.updated_at
            LIMIT 1
            SQL;
        $stmt = Database::pdo()->prepare($sql);
        $stmt->bindValue(':id',  $courseId, PDO::PARAM_INT);
        $stmt->bindValue(':tid', $tenantId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * @param array{name:string, description:?string, year:int, language:string} $data
     */
    public static function create(int $tenantId, array $data): int
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO courses (tenant_id, name, description, year, language) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $tenantId,
            $data['name'],
            $data['description'] !== '' ? $data['description'] : null,
            $data['year'],
            $data['language'],
        ]);
        return (int) Database::pdo()->lastInsertId();
    }

    /**
     * @param array{name:string, description:?string, year:int, language:string} $data
     */
    public static function update(int $courseId, int $tenantId, array $data): bool
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE courses SET name = ?, description = ?, year = ?, language = ?
              WHERE id = ? AND tenant_id = ?'
        );
        $stmt->execute([
            $data['name'],
            $data['description'] !== '' ? $data['description'] : null,
            $data['year'],
            $data['language'],
            $courseId,
            $tenantId,
        ]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Arquiva (archived=1, archived_at=NOW). No-op se já arquivado.
     * Usa timestamp PHP para consistência com o padrão do projeto.
     */
    public static function archive(int $courseId, int $tenantId): bool
    {
        $now = date('Y-m-d H:i:s');
        $stmt = Database::pdo()->prepare(
            'UPDATE courses SET archived = 1, archived_at = ?
              WHERE id = ? AND tenant_id = ? AND archived = 0'
        );
        $stmt->execute([$now, $courseId, $tenantId]);
        return $stmt->rowCount() > 0;
    }

    public static function restore(int $courseId, int $tenantId): bool
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE courses SET archived = 0, archived_at = NULL
              WHERE id = ? AND tenant_id = ? AND archived = 1'
        );
        $stmt->execute([$courseId, $tenantId]);
        return $stmt->rowCount() > 0;
    }
}
