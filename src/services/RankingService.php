<?php
declare(strict_types=1);

/**
 * RankingService — leitura agregada de `xp_events` para o ranking (E9-02).
 *
 * Single source of truth pras telas de ranking (aluno, professor, por-curso,
 * `#posição` no ProfileSidebar). Filtros opcionais combináveis por janela
 * rolante (geral / 7d / 30d), grupo, ano civil e curso. Sem cache no MVP —
 * volume aceita queries vivas.
 *
 * Desempate consolidado: `xp DESC, last_event_at DESC, name ASC`. Posição é
 * linear (1, 2, 3...) — após o tiebreaker o resultado é determinístico, sem
 * `RANK()`/`DENSE_RANK()`.
 *
 * Janela "all" sem filtros temporais inclui aluno sem nenhum xp_event
 * (LEFT JOIN, xp = 0). Demais janelas listam só quem pontuou no escopo.
 *
 * Spec: `doc/08-gamificacao-e-ranking.md`. ADRs 002 (XP B) e 003 (janelas).
 */
final class RankingService
{
    public const WINDOWS         = ['all', '7d', '30d'];
    public const DEFAULT_PER_PAGE = 50;
    private const MAX_PER_PAGE    = 500;

    /**
     * Calcula o ranking paginado.
     *
     * @param array{group_id?:int, year?:int, course_id?:int} $filters
     * @return array{
     *   rows: list<array{
     *     position:int, student_id:int, name:string,
     *     group_names:string, xp:int, last_event_at:?string
     *   }>,
     *   total:int
     * }
     *
     * IMPORTANTE: Caller deve escapar `name` e `group_names` com `e()` antes de
     * renderizá-las em HTML (responsabilidade do controller/view, não do service).
     */
    public static function compute(
        int $tenantId,
        string $window,
        array $filters = [],
        int $page = 1,
        int $perPage = self::DEFAULT_PER_PAGE
    ): array {
        if ($tenantId <= 0) {
            throw new InvalidArgumentException('tenantId must be positive');
        }
        if (!in_array($window, self::WINDOWS, true)) {
            throw new InvalidArgumentException('invalid window: must be one of ' . implode(', ', self::WINDOWS));
        }

        $page    = max(1, $page);
        $perPage = max(1, min(self::MAX_PER_PAGE, $perPage));
        $offset  = ($page - 1) * $perPage;

        $f = self::normaliseFilters($filters);
        [$xpJoinSql, $xpParams] = self::xpJoinClause($window, $f);
        $groupJoinSql = $f['group_id'] !== null
            ? 'INNER JOIN group_members gm ON gm.student_user_id = u.id AND gm.group_id = :group_id'
            : '';
        $havingSql = self::havingClause($window, $f);

        $pdo = Database::pdo();

        $countSql = "SELECT COUNT(*) FROM (
                       SELECT u.id
                         FROM users u
                         {$groupJoinSql}
                         LEFT JOIN xp_events x
                                ON x.student_user_id = u.id
                                {$xpJoinSql}
                        WHERE u.tenant_id = :tenant_id
                          AND u.role      = 'student'
                          AND u.active    = 1
                        GROUP BY u.id
                        {$havingSql}
                     ) c";
        $stmtTotal = $pdo->prepare($countSql);
        self::bindFilters($stmtTotal, $f, $xpParams);
        $stmtTotal->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
        $stmtTotal->execute();
        $total = (int) $stmtTotal->fetchColumn();

        $rowsSql = "SELECT u.id   AS student_id,
                           u.name AS name,
                           COALESCE(SUM(x.value), 0) AS xp,
                           MAX(x.created_at)         AS last_event_at,
                           (SELECT GROUP_CONCAT(g.name ORDER BY g.name SEPARATOR ', ')
                              FROM group_members gm2
                              INNER JOIN `groups` g ON g.id = gm2.group_id
                             WHERE gm2.student_user_id = u.id) AS group_names
                      FROM users u
                      {$groupJoinSql}
                      LEFT JOIN xp_events x
                             ON x.student_user_id = u.id
                             {$xpJoinSql}
                     WHERE u.tenant_id = :tenant_id
                       AND u.role      = 'student'
                       AND u.active    = 1
                     GROUP BY u.id, u.name
                     {$havingSql}
                     ORDER BY xp DESC, last_event_at DESC, u.name ASC
                     LIMIT :lim OFFSET :off";
        $stmt = $pdo->prepare($rowsSql);
        self::bindFilters($stmt, $f, $xpParams);
        $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':lim',       $perPage,  PDO::PARAM_INT);
        $stmt->bindValue(':off',       $offset,   PDO::PARAM_INT);
        $stmt->execute();

        $rows     = [];
        $position = $offset;
        foreach ($stmt->fetchAll() as $r) {
            $position++;
            $rows[] = [
                'position'      => $position,
                'student_id'    => (int) $r['student_id'],
                'name'          => (string) $r['name'],
                'group_names'   => (string) ($r['group_names'] ?? ''),
                'xp'            => (int) $r['xp'],
                'last_event_at' => $r['last_event_at'] !== null ? (string) $r['last_event_at'] : null,
            ];
        }

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * Posição linear do aluno no ranking sob os mesmos filtros. Retorna null
     * quando o aluno não está no ranking (ex.: sem evento na janela 7d, ou
     * fora do grupo filtrado, ou desativado).
     *
     * Usa ROW_NUMBER() pra calcular sem precisar trazer toda a lista pro PHP.
     *
     * @param array{group_id?:int, year?:int, course_id?:int} $filters
     */
    public static function myPosition(int $studentId, int $tenantId, string $window, array $filters = []): ?int
    {
        if ($studentId <= 0 || $tenantId <= 0) {
            return null;
        }
        if (!in_array($window, self::WINDOWS, true)) {
            throw new InvalidArgumentException('invalid window: must be one of ' . implode(', ', self::WINDOWS));
        }

        $f = self::normaliseFilters($filters);
        [$xpJoinSql, $xpParams] = self::xpJoinClause($window, $f);
        $groupJoinSql = $f['group_id'] !== null
            ? 'INNER JOIN group_members gm ON gm.student_user_id = u.id AND gm.group_id = :group_id'
            : '';
        $havingSql = self::havingClause($window, $f);

        $sql = "SELECT r.position FROM (
                  SELECT u.id,
                         ROW_NUMBER() OVER (
                             ORDER BY COALESCE(SUM(x.value), 0) DESC,
                                      MAX(x.created_at)         DESC,
                                      u.name                    ASC
                         ) AS position
                    FROM users u
                    {$groupJoinSql}
                    LEFT JOIN xp_events x
                           ON x.student_user_id = u.id
                           {$xpJoinSql}
                   WHERE u.tenant_id = :tenant_id
                     AND u.role      = 'student'
                     AND u.active    = 1
                   GROUP BY u.id, u.name
                   {$havingSql}
                ) r
                WHERE r.id = :student_id
                LIMIT 1";

        $stmt = Database::pdo()->prepare($sql);
        self::bindFilters($stmt, $f, $xpParams);
        $stmt->bindValue(':tenant_id',  $tenantId,  PDO::PARAM_INT);
        $stmt->bindValue(':student_id', $studentId, PDO::PARAM_INT);
        $stmt->execute();

        $pos = $stmt->fetchColumn();
        return $pos === false ? null : (int) $pos;
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{group_id:?int, year:?int, course_id:?int}
     */
    private static function normaliseFilters(array $filters): array
    {
        $groupId  = isset($filters['group_id'])  && (int) $filters['group_id']  > 0 ? (int) $filters['group_id']  : null;
        $year     = isset($filters['year'])      && (int) $filters['year']      > 0 ? (int) $filters['year']      : null;
        $courseId = isset($filters['course_id']) && (int) $filters['course_id'] > 0 ? (int) $filters['course_id'] : null;
        return ['group_id' => $groupId, 'year' => $year, 'course_id' => $courseId];
    }

    /**
     * Cláusulas adicionais do `LEFT JOIN xp_events` por janela/ano/curso.
     * Janela é interpolada como literal int (constante de servidor); ano e
     * curso vão como placeholders nomeados.
     *
     * @param array{group_id:?int, year:?int, course_id:?int} $f
     * @return array{0:string, 1:array<string,int>}
     */
    private static function xpJoinClause(string $window, array $f): array
    {
        $clauses = [];
        $params  = [];

        if ($window === '7d') {
            $clauses[] = 'x.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)';
        } elseif ($window === '30d') {
            $clauses[] = 'x.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)';
        }
        if ($f['year'] !== null) {
            $clauses[]       = 'YEAR(x.created_at) = :year';
            $params[':year'] = $f['year'];
        }
        if ($f['course_id'] !== null) {
            $clauses[]            = 'x.course_id = :course_id';
            $params[':course_id'] = $f['course_id'];
        }

        $sql = $clauses === [] ? '' : 'AND ' . implode(' AND ', $clauses);
        return [$sql, $params];
    }

    /**
     * Janela "all" sem filtros temporais → preserva aluno zero XP (LEFT JOIN
     * sem HAVING). Caso contrário, exclui (consistente com spec).
     *
     * @param array{group_id:?int, year:?int, course_id:?int} $f
     */
    private static function havingClause(string $window, array $f): string
    {
        return $window !== 'all' || $f['year'] !== null || $f['course_id'] !== null
            ? 'HAVING COALESCE(SUM(x.value), 0) > 0'
            : '';
    }

    /**
     * @param array{group_id:?int, year:?int, course_id:?int} $f
     * @param array<string,int>                                $xpParams
     */
    private static function bindFilters(PDOStatement $stmt, array $f, array $xpParams): void
    {
        if ($f['group_id'] !== null) {
            $stmt->bindValue(':group_id', $f['group_id'], PDO::PARAM_INT);
        }
        foreach ($xpParams as $k => $v) {
            $stmt->bindValue($k, $v, PDO::PARAM_INT);
        }
    }
}
