<?php
declare(strict_types=1);

/**
 * Model de submissão de avaliação (E7-02).
 *
 * Diferente de `activity_submissions` (1:1 por aluno, mutável até feedback),
 * aqui temos histórico: cada tentativa é uma linha própria com `attempt`
 * incrementando, `is_current=1` só na mais recente, `retry_allowed` setado
 * pelo professor na correção (E7-03) quando permite reenvio.
 *
 * Toda query do aluno valida matrícula ativa via JOIN em `enrollments`.
 */
final class EvaluationSubmission
{
    /**
     * Retorna avaliação + tentativa corrente (se houver) + contexto CU/curso,
     * validando matrícula do aluno no curso. null se avaliação não existe
     * ou aluno sem matrícula.
     *
     * @return array{
     *   evaluation: array<string,mixed>,
     *   current:    array<string,mixed>|null
     * }|null
     */
    public static function findForStudentEvaluation(int $evaluationId, int $studentId): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT e.id, e.tenant_id, e.title, e.instructions, e.type, e.pdf_path,
                    e.xp_value, e.submission_open,
                    cu.id AS cu_id, cu.name AS cu_name,
                    c.id  AS course_id, c.name AS course_name, c.grading_mode
               FROM evaluations e
               JOIN competence_units cu   ON cu.id = e.competence_unit_id
               JOIN core_competencies cc  ON cc.id = cu.core_competency_id
               JOIN courses c             ON c.id  = cc.course_id
               JOIN enrollments enr       ON enr.course_id = c.id
                                         AND enr.student_user_id = ?
              WHERE e.id = ?
              LIMIT 1'
        );
        $stmt->execute([$studentId, $evaluationId]);
        $evaluation = $stmt->fetch();
        if ($evaluation === false) {
            return null;
        }

        $stmt = Database::pdo()->prepare(
            'SELECT id, attempt, filename, stored_path, quiz_snapshot, grade, feedback,
                    feedback_at, retry_allowed, is_current, created_at
               FROM evaluation_submissions
              WHERE evaluation_id = ? AND student_user_id = ? AND is_current = 1
              LIMIT 1'
        );
        $stmt->execute([$evaluationId, $studentId]);
        $current = $stmt->fetch();

        return [
            'evaluation' => $evaluation,
            'current'    => $current === false ? null : $current,
        ];
    }

    /**
     * Histórico completo do aluno para esta avaliação, ordenado da tentativa
     * mais recente pra mais antiga. Não valida matrícula — chamador já
     * validou via `findForStudentEvaluation`.
     *
     * @return list<array<string,mixed>>
     */
    public static function listHistoryForStudent(int $evaluationId, int $studentId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT id, attempt, filename, stored_path, grade, feedback,
                    feedback_at, retry_allowed, is_current, created_at
               FROM evaluation_submissions
              WHERE evaluation_id = ? AND student_user_id = ?
              ORDER BY attempt DESC'
        );
        $stmt->execute([$evaluationId, $studentId]);
        return $stmt->fetchAll();
    }

    /**
     * Visão do professor (E7-03): avaliação + aluno + submissão corrente +
     * histórico + contexto de CU/curso. Valida que a avaliação pertence ao
     * tenant do professor E que o aluno tem matrícula ativa. Retorna null
     * quando algum dos dois falha.
     *
     * @return array{
     *   evaluation: array<string,mixed>,
     *   student:    array<string,mixed>,
     *   current:    array<string,mixed>|null,
     *   history:    list<array<string,mixed>>
     * }|null
     */
    public static function findForGrading(int $evaluationId, int $studentId, int $tenantId): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT e.id, e.tenant_id, e.title, e.instructions, e.type, e.pdf_path,
                    e.xp_value, e.submission_open,
                    cu.id AS cu_id, cu.name AS cu_name,
                    c.id  AS course_id, c.name AS course_name, c.grading_mode
               FROM evaluations e
               JOIN competence_units cu   ON cu.id = e.competence_unit_id
               JOIN core_competencies cc  ON cc.id = cu.core_competency_id
               JOIN courses c             ON c.id  = cc.course_id AND c.tenant_id = ?
               JOIN enrollments enr       ON enr.course_id = c.id
                                         AND enr.student_user_id = ?
              WHERE e.id = ?
              LIMIT 1'
        );
        $stmt->execute([$tenantId, $studentId, $evaluationId]);
        $evaluation = $stmt->fetch();
        if ($evaluation === false) {
            return null;
        }

        $stmt = Database::pdo()->prepare(
            'SELECT id, name, email FROM users
              WHERE id = ? AND tenant_id = ? AND role = \'student\'
              LIMIT 1'
        );
        $stmt->execute([$studentId, $tenantId]);
        $student = $stmt->fetch();
        if ($student === false) {
            return null;
        }

        $stmt = Database::pdo()->prepare(
            'SELECT id, attempt, filename, stored_path, quiz_snapshot, grade, feedback,
                    feedback_at, retry_allowed, is_current, created_at
               FROM evaluation_submissions
              WHERE evaluation_id = ? AND student_user_id = ?
              ORDER BY attempt DESC'
        );
        $stmt->execute([$evaluationId, $studentId]);
        $rows = $stmt->fetchAll();

        $current = null;
        $history = [];
        foreach ($rows as $row) {
            if ((int) $row['is_current'] === 1 && $current === null) {
                $current = $row;
            } else {
                $history[] = $row;
            }
        }

        return [
            'evaluation' => $evaluation,
            'student'    => $student,
            'current'    => $current,
            'history'    => $history,
        ];
    }

    /**
     * Listagem pro professor (E7-04): todos os alunos matriculados no curso
     * da CU + LEFT JOIN com a tentativa corrente (is_current=1). Alunos sem
     * submissão aparecem com `attempt/filename/grade/...` NULL.
     *
     * Valida tenant via JOIN em courses. Ordena por nome do aluno (asc).
     *
     * @return list<array<string,mixed>>
     */
    public static function listForEvaluation(int $evaluationId, int $tenantId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT u.id AS student_id, u.name AS student_name, u.email AS student_email,
                    s.id AS submission_id, s.attempt, s.filename, s.grade,
                    s.feedback_at, s.retry_allowed, s.created_at AS submitted_at,
                    (SELECT COUNT(*) FROM evaluation_submissions s2
                      WHERE s2.evaluation_id = e.id
                        AND s2.student_user_id = u.id) AS attempts_count
               FROM evaluations e
               JOIN competence_units cu   ON cu.id = e.competence_unit_id
               JOIN core_competencies cc  ON cc.id = cu.core_competency_id
               JOIN courses c             ON c.id  = cc.course_id AND c.tenant_id = ?
               JOIN enrollments enr       ON enr.course_id = c.id
               JOIN users u               ON u.id  = enr.student_user_id
                                         AND u.role = \'student\'
                                         AND u.active = 1
               LEFT JOIN evaluation_submissions s
                      ON s.evaluation_id = e.id
                     AND s.student_user_id = u.id
                     AND s.is_current = 1
              WHERE e.id = ?
              ORDER BY u.name ASC, u.id ASC'
        );
        $stmt->execute([$tenantId, $evaluationId]);
        return $stmt->fetchAll();
    }

    /**
     * Conta submissões correntes (is_current=1) da avaliação ainda sem
     * feedback. Usado no CTA do edit.php pra indicar quantas correções
     * estão pendentes. Não conta tentativas arquivadas.
     */
    public static function countPendingForEvaluation(int $evaluationId): int
    {
        $stmt = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM evaluation_submissions
              WHERE evaluation_id = ?
                AND is_current = 1
                AND feedback_at IS NULL'
        );
        $stmt->execute([$evaluationId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Busca 1 submissão específica pro professor baixar o arquivo. Valida
     * que a avaliação pertence ao tenant. null se alheia.
     *
     * @return array<string,mixed>|null
     */
    public static function findForTeacher(int $submissionId, int $tenantId): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT s.id, s.evaluation_id, s.student_user_id, s.filename,
                    s.stored_path, s.attempt
               FROM evaluation_submissions s
               JOIN evaluations e ON e.id = s.evaluation_id
              WHERE s.id = ? AND e.tenant_id = ?
              LIMIT 1'
        );
        $stmt->execute([$submissionId, $tenantId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Busca 1 submissão específica do aluno (para download autenticado).
     * Valida matrícula via JOIN em enrollments. null se alheia.
     *
     * @return array<string,mixed>|null
     */
    public static function findForStudent(int $submissionId, int $studentId): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT s.id, s.evaluation_id, s.filename, s.stored_path, s.attempt
               FROM evaluation_submissions s
               JOIN evaluations e          ON e.id  = s.evaluation_id
               JOIN competence_units cu    ON cu.id = e.competence_unit_id
               JOIN core_competencies cc   ON cc.id = cu.core_competency_id
               JOIN courses c              ON c.id  = cc.course_id
               JOIN enrollments enr        ON enr.course_id = c.id
                                          AND enr.student_user_id = ?
              WHERE s.id = ? AND s.student_user_id = ?
              LIMIT 1'
        );
        $stmt->execute([$studentId, $submissionId, $studentId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }
}
