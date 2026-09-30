<?php
declare(strict_types=1);

/**
 * Agregações pra home do professor (E11-01).
 *
 * Três métodos separados porque cada um tem cardinalidade e filtros
 * diferentes — compor numa query só não compensaria. Pra 1-30 alunos
 * e 1-20 cursos, o custo é desprezível.
 *
 * Tenant isolation via `tenant_id = ?`. Entregas: escopo por tenant do
 * ALUNO (+ cursos acessíveis com o toggle E34) — ver `submissionsUnion`.
 */
final class TeacherDashboard
{
    /**
     * Totalizadores pra cards do topo.
     *
     * @return array{
     *   courses:int,
     *   students:int,
     *   pending_submissions:int
     * }
     */
    public static function totalsForTenant(int $tenantId, array $showAllCourseIds = []): array
    {
        $pdo = Database::pdo();

        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM courses WHERE tenant_id = ? AND archived = 0'
        );
        $stmt->execute([$tenantId]);
        $courses = (int) $stmt->fetchColumn();

        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM users
              WHERE tenant_id = ? AND role = \'student\' AND active = 1'
        );
        $stmt->execute([$tenantId]);
        $students = (int) $stmt->fetchColumn();

        // Pending = sem feedback_at. Conta atividades + avaliações (corrente)
        // no mesmo escopo das listas (ver `submissionsUnion`).
        $pending = self::countAllSubmissions($tenantId, true, $showAllCourseIds);

        return [
            'courses'              => $courses,
            'students'             => $students,
            'pending_submissions'  => $pending,
        ];
    }

    /**
     * Últimas submissões do tenant — mix de activities + evaluations
     * (tentativa corrente). Pendentes (sem feedback) vem primeiro;
     * dentro de cada bucket, mais recente primeiro. Cada row traz o
     * suficiente pra linkar direto na correção e identificar o contexto
     * (curso › módulo › unidade) sem clicar — caso contrário o professor
     * com muitos cursos não sabe de qual unidade veio a entrega.
     *
     * @return list<array{
     *   src:'activity'|'evaluation',
     *   ref_id:int,
     *   ref_title:string,
     *   student_id:int,
     *   student_name:string,
     *   created_at:string,
     *   feedback_at:?string,
     *   course_name:string,
     *   cc_name:string,
     *   cu_name:string
     * }>
     */
    public static function recentSubmissions(int $tenantId, int $limit = 10, array $showAllCourseIds = []): array
    {
        $limit = max(1, min(50, $limit));
        [$sql, $params] = self::submissionsUnion($tenantId, false, $showAllCourseIds);
        $stmt = Database::pdo()->prepare(
            $sql . ' ORDER BY (feedback_at IS NOT NULL), created_at DESC LIMIT ?'
        );
        $stmt->execute([...$params, $limit]);
        return $stmt->fetchAll();
    }

    /**
     * Listagem paginada de TODAS as submissões do tenant — página dedicada
     * /teacher/submissions. Igual ao recent, mas com offset + filtro opcional
     * por pendentes (sem feedback).
     *
     * @return list<array{
     *   src:'activity'|'evaluation',
     *   ref_id:int,
     *   ref_title:string,
     *   student_id:int,
     *   student_name:string,
     *   created_at:string,
     *   feedback_at:?string,
     *   course_name:string,
     *   cc_name:string,
     *   cu_name:string
     * }>
     */
    public static function findAllSubmissions(
        int $tenantId,
        bool $pendingOnly,
        int $perPage,
        int $offset,
        array $showAllCourseIds = []
    ): array {
        $perPage = max(1, min(100, $perPage));
        $offset  = max(0, $offset);

        [$sql, $params] = self::submissionsUnion($tenantId, $pendingOnly, $showAllCourseIds);
        $stmt = Database::pdo()->prepare($sql . ' ORDER BY created_at DESC LIMIT ? OFFSET ?');
        $stmt->execute([...$params, $perPage, $offset]);
        return $stmt->fetchAll();
    }

    /**
     * Total de submissões pra paginação (`/teacher/submissions`).
     */
    public static function countAllSubmissions(int $tenantId, bool $pendingOnly, array $showAllCourseIds = []): int
    {
        [$sql, $params] = self::submissionsUnion($tenantId, $pendingOnly, $showAllCourseIds);
        $stmt = Database::pdo()->prepare('SELECT COUNT(*) FROM (' . $sql . ') t');
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /**
     * UNION ALL de atividades + avaliações (tentativa corrente) no escopo do
     * professor, sem ORDER/LIMIT (o caller completa).
     *
     * Escopo (ADR-040): entregas dos alunos do MEU tenant, em qualquer curso
     * (inclui curso compartilhado comigo e colaborador revogado); mais, quando
     * o toggle "ver todos" (E34) está ligado, as de TODOS os alunos dos cursos
     * em `$showAllCourseIds` (os que eu acesso) — em curso compartilhado os dois
     * professores corrigem a turma inteira. Antes filtrava pelo tenant do DONO
     * do curso: o dono via as entregas dos alunos do colaborador (e caía em 404
     * ao abrir) e o colaborador não via nem as dos próprios alunos.
     *
     * @param list<int> $showAllCourseIds
     * @return array{0:string, 1:list<int>}
     */
    private static function submissionsUnion(int $tenantId, bool $pendingOnly, array $showAllCourseIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $showAllCourseIds)));
        $scope  = $ids === []
            ? 'u.tenant_id = ?'
            : '(u.tenant_id = ? OR c.id IN (' . implode(',', array_fill(0, count($ids), '?')) . '))';
        $scopeParams = [$tenantId, ...$ids];
        $pending = $pendingOnly ? ' AND s.feedback_at IS NULL' : '';

        $sql = '(SELECT \'activity\' AS src, s.activity_id AS ref_id,
                        a.title AS ref_title,
                        s.student_user_id AS student_id, u.name AS student_name,
                        s.created_at, s.feedback_at,
                        c.name AS course_name, cc.name AS cc_name, cu.name AS cu_name
                   FROM activity_submissions s
                   JOIN activities a          ON a.id  = s.activity_id
                   JOIN competence_units cu   ON cu.id = a.competence_unit_id
                   JOIN core_competencies cc  ON cc.id = cu.core_competency_id
                   JOIN courses c             ON c.id  = cc.course_id
                   JOIN users u               ON u.id  = s.student_user_id
                  WHERE ' . $scope . $pending . ')
                UNION ALL
                (SELECT \'evaluation\' AS src, s.evaluation_id AS ref_id,
                        e.title AS ref_title,
                        s.student_user_id AS student_id, u.name AS student_name,
                        s.created_at, s.feedback_at,
                        c.name AS course_name, cc.name AS cc_name, cu.name AS cu_name
                   FROM evaluation_submissions s
                   JOIN evaluations e         ON e.id  = s.evaluation_id
                   JOIN competence_units cu   ON cu.id = e.competence_unit_id
                   JOIN core_competencies cc  ON cc.id = cu.core_competency_id
                   JOIN courses c             ON c.id  = cc.course_id
                   JOIN users u               ON u.id  = s.student_user_id
                  WHERE ' . $scope . $pending . ')';

        return [$sql, [...$scopeParams, ...$scopeParams]];
    }

    /**
     * Alunos sem acesso em 14+ dias OU que nunca acessaram.
     * Ordena NULLS primeiro (nunca acessou) depois pelos mais antigos.
     *
     * @return list<array{
     *   id:int,
     *   name:string,
     *   email:string,
     *   last_access_at:?string
     * }>
     */
    public static function inactiveStudents(int $tenantId, int $limit = 10): array
    {
        $limit = max(1, min(50, $limit));
        $stmt = Database::pdo()->prepare(
            'SELECT u.id, u.name, u.email,
                    MAX(e.last_access_at) AS last_access_at
               FROM users u
               LEFT JOIN enrollments e ON e.student_user_id = u.id
              WHERE u.tenant_id = ?
                AND u.role   = \'student\'
                AND u.active = 1
              GROUP BY u.id, u.name, u.email
             HAVING MAX(e.last_access_at) IS NULL
                 OR MAX(e.last_access_at) < (NOW() - INTERVAL 14 DAY)
              ORDER BY last_access_at ASC
              LIMIT ?'
        );
        $stmt->execute([$tenantId, $limit]);
        return $stmt->fetchAll();
    }
}
