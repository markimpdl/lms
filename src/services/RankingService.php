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
     * Cada linha traz a patente atual do aluno (`rank_*`) calculada sobre o XP
     * **total acumulado** do tenant (não filtrado pela janela) — patente reflete
     * progressão histórica, não atividade na janela. NULL quando aluno não tem XP
     * suficiente pra entrar em nenhuma patente OU tenant não cadastrou patentes.
     *
     * @param array{group_id?:int, year?:int, course_id?:int} $filters
     * @return array{
     *   rows: list<array{
     *     position:int, student_id:int, name:string,
     *     group_names:string, xp:int, last_event_at:?string,
     *     rank_id:?int, rank_name:?string, rank_color_hex:?string,
     *     online_seconds:int
     *   }>,
     *   total:int
     * }
     *
     * IMPORTANTE: Caller deve escapar `name`, `group_names` e `rank_name` com
     * `e()` antes de renderizá-los em HTML (responsabilidade do controller/view,
     * não do service).
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
        [$xpJoinSql, $xpParams]    = self::xpJoinClause($window, $f);
        [$enrollSql, $enrollParams] = self::enrollmentYearClause($f);
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
                          {$enrollSql}
                        GROUP BY u.id
                        {$havingSql}
                     ) c";
        $stmtTotal = $pdo->prepare($countSql);
        self::bindFilters($stmtTotal, $f, $xpParams, $enrollParams);
        $stmtTotal->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
        $stmtTotal->execute();
        $total = (int) $stmtTotal->fetchColumn();

        // online_seconds: total acumulado historico (sem janela). Sessao
        // ativa entra via TIMESTAMPDIFF (mesmo padrao de StudentSession::statsForStudent).
        $rowsSql = "SELECT u.id   AS student_id,
                           u.name AS name,
                           COALESCE(SUM(x.value), 0) AS xp,
                           MAX(x.created_at)         AS last_event_at,
                           (SELECT GROUP_CONCAT(g.name ORDER BY g.name SEPARATOR ', ')
                              FROM group_members gm2
                              INNER JOIN `groups` g ON g.id = gm2.group_id
                             WHERE gm2.student_user_id = u.id) AS group_names,
                           MAX(rk.id)        AS rank_id,
                           MAX(rk.name)      AS rank_name,
                           MAX(rk.color_hex) AS rank_color_hex,
                           COALESCE(MAX(ss.total_seconds), 0) AS online_seconds
                      FROM users u
                      {$groupJoinSql}
                      LEFT JOIN xp_events x
                             ON x.student_user_id = u.id
                             {$xpJoinSql}
                      LEFT JOIN (
                          SELECT student_user_id, SUM(value) AS total_xp
                            FROM xp_events
                           WHERE tenant_id = :tenant_id_total
                           GROUP BY student_user_id
                      ) ta ON ta.student_user_id = u.id
                      LEFT JOIN ranks rk
                             ON rk.tenant_id = :tenant_id_rank
                            AND rk.xp_min   <= COALESCE(ta.total_xp, 0)
                            AND (rk.xp_max IS NULL OR rk.xp_max > COALESCE(ta.total_xp, 0))
                      LEFT JOIN (
                          SELECT user_id,
                                 SUM(COALESCE(duration_seconds,
                                              TIMESTAMPDIFF(SECOND, started_at, NOW()))) AS total_seconds
                            FROM student_sessions
                           WHERE tenant_id = :tenant_id_sessions
                           GROUP BY user_id
                      ) ss ON ss.user_id = u.id
                     WHERE u.tenant_id = :tenant_id
                       AND u.role      = 'student'
                       AND u.active    = 1
                       {$enrollSql}
                     GROUP BY u.id, u.name
                     {$havingSql}
                     ORDER BY xp DESC, last_event_at DESC, u.name ASC
                     LIMIT :lim OFFSET :off";
        $stmt = $pdo->prepare($rowsSql);
        self::bindFilters($stmt, $f, $xpParams, $enrollParams);
        $stmt->bindValue(':tenant_id',          $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':tenant_id_total',    $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':tenant_id_rank',     $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':tenant_id_sessions', $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':lim',                $perPage,  PDO::PARAM_INT);
        $stmt->bindValue(':off',                $offset,   PDO::PARAM_INT);
        $stmt->execute();

        $rows     = [];
        $position = $offset;
        foreach ($stmt->fetchAll() as $r) {
            $position++;
            $rows[] = [
                'position'       => $position,
                'student_id'     => (int) $r['student_id'],
                'name'           => (string) $r['name'],
                'group_names'    => (string) ($r['group_names'] ?? ''),
                'xp'             => (int) $r['xp'],
                'last_event_at'  => $r['last_event_at'] !== null ? (string) $r['last_event_at'] : null,
                'rank_id'        => $r['rank_id']        !== null ? (int) $r['rank_id']           : null,
                'rank_name'      => $r['rank_name']      !== null ? (string) $r['rank_name']      : null,
                'rank_color_hex' => $r['rank_color_hex'] !== null ? (string) $r['rank_color_hex'] : null,
                'online_seconds' => (int) ($r['online_seconds'] ?? 0),
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
        [$xpJoinSql, $xpParams]    = self::xpJoinClause($window, $f);
        [$enrollSql, $enrollParams] = self::enrollmentYearClause($f);
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
                     {$enrollSql}
                   GROUP BY u.id, u.name
                   {$havingSql}
                ) r
                WHERE r.id = :student_id
                LIMIT 1";

        $stmt = Database::pdo()->prepare($sql);
        self::bindFilters($stmt, $f, $xpParams, $enrollParams);
        $stmt->bindValue(':tenant_id',  $tenantId,  PDO::PARAM_INT);
        $stmt->bindValue(':student_id', $studentId, PDO::PARAM_INT);
        $stmt->execute();

        $pos = $stmt->fetchColumn();
        return $pos === false ? null : (int) $pos;
    }

    /**
     * Ranking UNIFICADO de um curso (E32-05 / ADR-033): lista todos os alunos
     * MATRICULADOS no curso, de qualquer tenant, juntos numa só classificação.
     * Um curso compartilhado é uma turma só. XP restrito ao curso (`course_id`);
     * patente e tempo online são do tenant do PRÓPRIO aluno (cada aluno tem a
     * sua patente no seu tenant).
     *
     * Para curso NÃO compartilhado o resultado é idêntico ao `compute()` com
     * filtro de curso (alunos enrolados = alunos do único tenant).
     *
     * Mesmo shape de saída do `compute()`. Placeholders nomeados distintos por
     * ocorrência (emulação off não aceita reuso do mesmo `:nome`).
     *
     * @return array{rows: list<array<string,mixed>>, total:int}
     */
    public static function computeForCourse(
        int $courseId,
        string $window,
        int $page = 1,
        int $perPage = self::DEFAULT_PER_PAGE
    ): array {
        if ($courseId <= 0) {
            throw new InvalidArgumentException('courseId must be positive');
        }
        if (!in_array($window, self::WINDOWS, true)) {
            throw new InvalidArgumentException('invalid window: must be one of ' . implode(', ', self::WINDOWS));
        }

        $page    = max(1, $page);
        $perPage = max(1, min(self::MAX_PER_PAGE, $perPage));
        $offset  = ($page - 1) * $perPage;

        $win = '';
        if ($window === '7d') {
            $win = 'AND x.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)';
        } elseif ($window === '30d') {
            $win = 'AND x.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)';
        }
        // "all" preserva alunos com 0 XP (LEFT JOIN sem HAVING); janelas listam
        // só quem pontuou no curso na janela.
        $having = $window !== 'all' ? 'HAVING COALESCE(SUM(x.value), 0) > 0' : '';

        $pdo = Database::pdo();

        $countSql = "SELECT COUNT(*) FROM (
                       SELECT u.id
                         FROM enrollments en
                         JOIN users u ON u.id = en.student_user_id
                                     AND u.role = 'student' AND u.active = 1
                         LEFT JOIN xp_events x
                                ON x.student_user_id = u.id
                               AND x.course_id = :cid_xp {$win}
                        WHERE en.course_id = :cid_w
                        GROUP BY u.id
                        {$having}
                     ) c";
        $stmtTotal = $pdo->prepare($countSql);
        $stmtTotal->bindValue(':cid_xp', $courseId, PDO::PARAM_INT);
        $stmtTotal->bindValue(':cid_w',  $courseId, PDO::PARAM_INT);
        $stmtTotal->execute();
        $total = (int) $stmtTotal->fetchColumn();

        $rowsSql = "SELECT u.id   AS student_id,
                           u.name AS name,
                           COALESCE(SUM(x.value), 0) AS xp,
                           MAX(x.created_at)         AS last_event_at,
                           '' AS group_names,
                           MAX(rk.id)        AS rank_id,
                           MAX(rk.name)      AS rank_name,
                           MAX(rk.color_hex) AS rank_color_hex,
                           COALESCE(MAX(ss.total_seconds), 0) AS online_seconds
                      FROM enrollments en
                      JOIN users u ON u.id = en.student_user_id
                                  AND u.role = 'student' AND u.active = 1
                      LEFT JOIN xp_events x
                             ON x.student_user_id = u.id
                            AND x.course_id = :cid_xp {$win}
                      LEFT JOIN (
                          SELECT student_user_id, tenant_id, SUM(value) AS total_xp
                            FROM xp_events
                           GROUP BY student_user_id, tenant_id
                      ) ta ON ta.student_user_id = u.id AND ta.tenant_id = u.tenant_id
                      LEFT JOIN ranks rk
                             ON rk.tenant_id = u.tenant_id
                            AND rk.xp_min   <= COALESCE(ta.total_xp, 0)
                            AND (rk.xp_max IS NULL OR rk.xp_max > COALESCE(ta.total_xp, 0))
                      LEFT JOIN (
                          SELECT user_id, tenant_id,
                                 SUM(COALESCE(duration_seconds,
                                              TIMESTAMPDIFF(SECOND, started_at, NOW()))) AS total_seconds
                            FROM student_sessions
                           GROUP BY user_id, tenant_id
                      ) ss ON ss.user_id = u.id AND ss.tenant_id = u.tenant_id
                     WHERE en.course_id = :cid_w
                     GROUP BY u.id, u.name, u.tenant_id
                     {$having}
                     ORDER BY xp DESC, last_event_at DESC, u.name ASC
                     LIMIT :lim OFFSET :off";
        $stmt = $pdo->prepare($rowsSql);
        $stmt->bindValue(':cid_xp', $courseId, PDO::PARAM_INT);
        $stmt->bindValue(':cid_w',  $courseId, PDO::PARAM_INT);
        $stmt->bindValue(':lim',    $perPage,  PDO::PARAM_INT);
        $stmt->bindValue(':off',    $offset,   PDO::PARAM_INT);
        $stmt->execute();

        $rows     = [];
        $position = $offset;
        foreach ($stmt->fetchAll() as $r) {
            $position++;
            $rows[] = [
                'position'       => $position,
                'student_id'     => (int) $r['student_id'],
                'name'           => (string) $r['name'],
                'group_names'    => (string) ($r['group_names'] ?? ''),
                'xp'             => (int) $r['xp'],
                'last_event_at'  => $r['last_event_at'] !== null ? (string) $r['last_event_at'] : null,
                'rank_id'        => $r['rank_id']        !== null ? (int) $r['rank_id']           : null,
                'rank_name'      => $r['rank_name']      !== null ? (string) $r['rank_name']      : null,
                'rank_color_hex' => $r['rank_color_hex'] !== null ? (string) $r['rank_color_hex'] : null,
                'online_seconds' => (int) ($r['online_seconds'] ?? 0),
            ];
        }

        return ['rows' => $rows, 'total' => $total];
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
     * Cláusulas adicionais do `LEFT JOIN xp_events` por janela/curso.
     * Janela é interpolada como literal int (constante de servidor); curso
     * vai como placeholder nomeado. **Year não entra aqui** — desde 2026-04-27,
     * filtro de ano restringe alunos por `enrollments.enrolled_at`, não pela
     * data do XP (ver `enrollmentJoinClause`).
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
        if ($f['course_id'] !== null) {
            $clauses[]            = 'x.course_id = :course_id';
            $params[':course_id'] = $f['course_id'];
        }

        $sql = $clauses === [] ? '' : 'AND ' . implode(' AND ', $clauses);
        return [$sql, $params];
    }

    /**
     * INNER JOIN com `enrollments` quando filtro `year` está setado, restringindo
     * o ranking aos alunos matriculados em qualquer curso daquele ano. XP exibido
     * permanece cumulativo (não filtrado por ano). EXISTS evita duplicar a linha
     * do aluno quando ele tem 2+ matrículas no mesmo ano.
     *
     * @param array{year:?int} $f
     * @return array{0:string, 1:array<string,int>}
     */
    private static function enrollmentYearClause(array $f): array
    {
        if ($f['year'] === null) {
            return ['', []];
        }
        $sql = ' AND EXISTS (
                    SELECT 1 FROM enrollments enr
                     WHERE enr.student_user_id = u.id
                       AND YEAR(enr.enrolled_at) = :year
                ) ';
        return [$sql, [':year' => $f['year']]];
    }

    /**
     * Janela "all" sem filtros temporais/curso → preserva aluno zero XP
     * (LEFT JOIN sem HAVING). Caso contrário, exclui (consistente com spec).
     * Filtro de `year` (matrícula) não exige HAVING — alunos matriculados em
     * `:year` mas sem XP ainda devem aparecer com 0.
     *
     * @param array{group_id:?int, year:?int, course_id:?int} $f
     */
    private static function havingClause(string $window, array $f): string
    {
        return $window !== 'all' || $f['course_id'] !== null
            ? 'HAVING COALESCE(SUM(x.value), 0) > 0'
            : '';
    }

    /**
     * @param array{group_id:?int, year:?int, course_id:?int} $f
     * @param array<string,int>                                $xpParams
     * @param array<string,int>                                $enrollParams
     */
    private static function bindFilters(PDOStatement $stmt, array $f, array $xpParams, array $enrollParams = []): void
    {
        if ($f['group_id'] !== null) {
            $stmt->bindValue(':group_id', $f['group_id'], PDO::PARAM_INT);
        }
        foreach ($enrollParams as $k => $v) {
            $stmt->bindValue($k, $v, PDO::PARAM_INT);
        }
        foreach ($xpParams as $k => $v) {
            $stmt->bindValue($k, $v, PDO::PARAM_INT);
        }
    }
}
