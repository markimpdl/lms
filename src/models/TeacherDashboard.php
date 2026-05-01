<?php
declare(strict_types=1);

/**
 * Agregações pra home do professor (E11-01).
 *
 * Três métodos separados porque cada um tem cardinalidade e filtros
 * diferentes — compor numa query só não compensaria. Pra 1-30 alunos
 * e 1-20 cursos, o custo é desprezível.
 *
 * Tenant isolation sempre via `tenant_id = ?` — courses diretamente
 * ou evaluation_submissions via coluna redundante plantada em E7-00.
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
    public static function totalsForTenant(int $tenantId): array
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
        // numa tacada via UNION ALL.
        $stmt = $pdo->prepare(
            '(SELECT COUNT(*)
                FROM activity_submissions s
                JOIN activities a          ON a.id  = s.activity_id
                JOIN competence_units cu   ON cu.id = a.competence_unit_id
                JOIN core_competencies cc  ON cc.id = cu.core_competency_id
                JOIN courses c             ON c.id  = cc.course_id AND c.tenant_id = ?
               WHERE s.feedback_at IS NULL)
             UNION ALL
             (SELECT COUNT(*)
                FROM evaluation_submissions s
                JOIN evaluations e         ON e.id  = s.evaluation_id AND e.tenant_id = ?
               WHERE s.feedback_at IS NULL)'
        );
        $stmt->execute([$tenantId, $tenantId]);
        $counts = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $pending = array_sum(array_map('intval', $counts));

        return [
            'courses'              => $courses,
            'students'             => $students,
            'pending_submissions'  => $pending,
        ];
    }

    /**
     * Últimas submissões do tenant — mix de activities + evaluations
     * (tentativa corrente), ordenadas por `created_at DESC`. Cada row
     * traz o suficiente pra linkar direto na correção.
     *
     * @return list<array{
     *   src:'activity'|'evaluation',
     *   ref_id:int,
     *   ref_title:string,
     *   student_id:int,
     *   student_name:string,
     *   created_at:string,
     *   feedback_at:?string
     * }>
     */
    public static function recentSubmissions(int $tenantId, int $limit = 10): array
    {
        $limit = max(1, min(50, $limit));
        $stmt = Database::pdo()->prepare(
            '(SELECT \'activity\' AS src, s.activity_id AS ref_id,
                     a.title AS ref_title,
                     s.student_user_id AS student_id, u.name AS student_name,
                     s.created_at, s.feedback_at
                FROM activity_submissions s
                JOIN activities a          ON a.id  = s.activity_id
                JOIN competence_units cu   ON cu.id = a.competence_unit_id
                JOIN core_competencies cc  ON cc.id = cu.core_competency_id
                JOIN courses c             ON c.id  = cc.course_id AND c.tenant_id = ?
                JOIN users u               ON u.id  = s.student_user_id)
             UNION ALL
             (SELECT \'evaluation\' AS src, s.evaluation_id AS ref_id,
                     e.title AS ref_title,
                     s.student_user_id AS student_id, u.name AS student_name,
                     s.created_at, s.feedback_at
                FROM evaluation_submissions s
                JOIN evaluations e         ON e.id  = s.evaluation_id AND e.tenant_id = ?
                JOIN users u               ON u.id  = s.student_user_id)
             ORDER BY created_at DESC
             LIMIT ' . $limit
        );
        $stmt->execute([$tenantId, $tenantId]);
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
     *   feedback_at:?string
     * }>
     */
    public static function findAllSubmissions(
        int $tenantId,
        bool $pendingOnly,
        int $perPage,
        int $offset
    ): array {
        $perPage = max(1, min(100, $perPage));
        $offset  = max(0, $offset);

        $pendingFilter = $pendingOnly ? ' AND s.feedback_at IS NULL' : '';

        $stmt = Database::pdo()->prepare(
            '(SELECT \'activity\' AS src, s.activity_id AS ref_id,
                     a.title AS ref_title,
                     s.student_user_id AS student_id, u.name AS student_name,
                     s.created_at, s.feedback_at
                FROM activity_submissions s
                JOIN activities a          ON a.id  = s.activity_id
                JOIN competence_units cu   ON cu.id = a.competence_unit_id
                JOIN core_competencies cc  ON cc.id = cu.core_competency_id
                JOIN courses c             ON c.id  = cc.course_id AND c.tenant_id = ?
                JOIN users u               ON u.id  = s.student_user_id
               WHERE 1=1' . $pendingFilter . ')
             UNION ALL
             (SELECT \'evaluation\' AS src, s.evaluation_id AS ref_id,
                     e.title AS ref_title,
                     s.student_user_id AS student_id, u.name AS student_name,
                     s.created_at, s.feedback_at
                FROM evaluation_submissions s
                JOIN evaluations e         ON e.id  = s.evaluation_id AND e.tenant_id = ?
                JOIN users u               ON u.id  = s.student_user_id
               WHERE 1=1' . $pendingFilter . ')
             ORDER BY created_at DESC
             LIMIT ' . $perPage . ' OFFSET ' . $offset
        );
        $stmt->execute([$tenantId, $tenantId]);
        return $stmt->fetchAll();
    }

    /**
     * Total de submissões pra paginação (`/teacher/submissions`).
     */
    public static function countAllSubmissions(int $tenantId, bool $pendingOnly): int
    {
        $pendingFilter = $pendingOnly ? ' AND s.feedback_at IS NULL' : '';

        $stmt = Database::pdo()->prepare(
            '(SELECT COUNT(*)
                FROM activity_submissions s
                JOIN activities a          ON a.id  = s.activity_id
                JOIN competence_units cu   ON cu.id = a.competence_unit_id
                JOIN core_competencies cc  ON cc.id = cu.core_competency_id
                JOIN courses c             ON c.id  = cc.course_id AND c.tenant_id = ?
               WHERE 1=1' . $pendingFilter . ')
             UNION ALL
             (SELECT COUNT(*)
                FROM evaluation_submissions s
                JOIN evaluations e         ON e.id  = s.evaluation_id AND e.tenant_id = ?
               WHERE 1=1' . $pendingFilter . ')'
        );
        $stmt->execute([$tenantId, $tenantId]);
        $counts = $stmt->fetchAll(PDO::FETCH_COLUMN);
        return (int) array_sum(array_map('intval', $counts));
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
              LIMIT ' . $limit
        );
        $stmt->execute([$tenantId]);
        return $stmt->fetchAll();
    }
}
