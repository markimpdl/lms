<?php
declare(strict_types=1);

/**
 * Model de submissão de avaliação (E7-02).
 *
 * 1 linha por (evaluation_id, student_id) — desde v0.29.0 não guardamos
 * histórico de tentativas reprovadas (PO: economia de espaço; só a última
 * importa). O `submit()` no service faz DELETE da tentativa antiga + apaga
 * arquivos físicos antes de inserir a nova. Coluna `attempt` segue
 * incrementando como contador histórico ("aluno está na 2ª tentativa") mesmo
 * sem o registro antigo. Coluna `is_current` removida em v0.30.0 (era sempre
 * = 1, virou dead weight). Queries que filtravam `AND is_current = 1` foram
 * limpas — a UK garante a unicidade.
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
                    feedback_at, retry_allowed, created_at
               FROM evaluation_submissions
              WHERE evaluation_id = ? AND student_user_id = ?
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
     * Visão do professor (E7-03): avaliação + aluno + submissão corrente +
     * contexto de CU/curso. Valida que a avaliação pertence ao tenant do
     * professor E que o aluno tem matrícula ativa. Retorna null quando algum
     * dos dois falha.
     *
     * Desde v0.29.0 sem histórico de tentativas (`history` removido —
     * só existe 1 linha por (eval, student) no banco). Templates devem ler
     * apenas `current`.
     *
     * @return array{
     *   evaluation: array<string,mixed>,
     *   student:    array<string,mixed>,
     *   current:    array<string,mixed>|null
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
               JOIN courses c             ON c.id  = cc.course_id
               JOIN enrollments enr       ON enr.course_id = c.id
                                         AND enr.student_user_id = ?
              WHERE e.id = ?
              LIMIT 1'
        );
        // E32 (ADR-033): sem gate por tenant do curso (a página valida acesso
        // via effective_authoring_tenant). O aluno é validado pelo MEU tenant
        // abaixo — colaborador só corrige os próprios alunos.
        $stmt->execute([$studentId, $evaluationId]);
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
            'SELECT id, attempt, filename, stored_path, quiz_snapshot, report_pdf_path,
                    grade, feedback, feedback_at, retry_allowed, created_at
               FROM evaluation_submissions
              WHERE evaluation_id = ? AND student_user_id = ?
              LIMIT 1'
        );
        $stmt->execute([$evaluationId, $studentId]);
        $current = $stmt->fetch();

        return [
            'evaluation' => $evaluation,
            'student'    => $student,
            'current'    => $current === false ? null : $current,
        ];
    }

    /**
     * Listagem pro professor (E7-04): todos os alunos matriculados no curso
     * da CU + LEFT JOIN com a única tentativa que existe por (eval, student)
     * desde v0.29.0. Alunos sem submissão aparecem com `attempt/filename/...` NULL.
     *
     * Valida tenant via JOIN em courses. Ordena por nome do aluno (asc).
     *
     * `attempts_count` continua refletindo o `attempt` atual (1 quando aluno
     * só tentou uma vez, 2+ quando reenviou — o contador é incrementado mesmo
     * sem o registro antigo).
     *
     * @return list<array<string,mixed>>
     */
    public static function listForEvaluation(int $evaluationId, int $tenantId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT u.id AS student_id, u.name AS student_name, u.email AS student_email,
                    s.id AS submission_id, s.attempt, s.filename, s.grade,
                    s.feedback_at, s.retry_allowed, s.created_at AS submitted_at,
                    COALESCE(s.attempt, 0) AS attempts_count
               FROM evaluations e
               JOIN competence_units cu   ON cu.id = e.competence_unit_id
               JOIN core_competencies cc  ON cc.id = cu.core_competency_id
               JOIN courses c             ON c.id  = cc.course_id
               JOIN enrollments enr       ON enr.course_id = c.id
               JOIN users u               ON u.id  = enr.student_user_id
                                         AND u.role = \'student\'
                                         AND u.active = 1
                                         AND u.tenant_id = ?
               LEFT JOIN evaluation_submissions s
                      ON s.evaluation_id = e.id
                     AND s.student_user_id = u.id
              WHERE e.id = ?
              ORDER BY u.name ASC, u.id ASC'
        );
        // E32: filtra alunos pelo tenant do PROFESSOR agindo (cada um só os
        // seus). Acesso à avaliação é gateado na página. Dono: idêntico.
        $stmt->execute([$tenantId, $evaluationId]);
        return $stmt->fetchAll();
    }

    /**
     * Lista todos os reports PDF gerados de um aluno (POLISH-01),
     * com contexto Curso › CC › CU + nota + data do feedback.
     * Usado no card "Reports" da tela de detalhes do aluno (E4-01).
     *
     * Filtra `report_pdf_path IS NOT NULL` (só submissões com PDF gerado)
     * e tenant via JOIN em courses (mesmo padrão de findForGrading).
     * Ordenado por nome do curso (asc) e data do feedback (desc) — caller
     * agrupa por course_id em PHP.
     *
     * @return list<array<string,mixed>>
     */
    public static function listReportsByStudent(int $studentUserId, int $tenantId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT s.evaluation_id, s.grade, s.feedback_at,
                    e.title AS evaluation_title,
                    cu.id AS cu_id, cu.name AS cu_name,
                    cc.id AS cc_id, cc.name AS cc_name,
                    c.id  AS course_id, c.name AS course_name
               FROM evaluation_submissions s
               JOIN evaluations e         ON e.id  = s.evaluation_id
               JOIN competence_units cu   ON cu.id = e.competence_unit_id
               JOIN core_competencies cc  ON cc.id = cu.core_competency_id
               JOIN courses c             ON c.id  = cc.course_id AND c.tenant_id = ?
              WHERE s.student_user_id = ?
                AND s.report_pdf_path IS NOT NULL
              ORDER BY c.name ASC, s.feedback_at DESC'
        );
        $stmt->execute([$tenantId, $studentUserId]);
        return $stmt->fetchAll();
    }

    /**
     * Conta submissões da avaliação ainda sem feedback. Usado no CTA do
     * edit.php pra indicar quantas correções estão pendentes.
     */
    public static function countPendingForEvaluation(int $evaluationId): int
    {
        $stmt = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM evaluation_submissions
              WHERE evaluation_id = ?
                AND feedback_at IS NULL'
        );
        $stmt->execute([$evaluationId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Busca 1 submissão específica pro professor baixar o arquivo. Valida
     * que a submissão pertence a aluno do MEU tenant (E32-05). null se alheia.
     *
     * @return array<string,mixed>|null
     */
    public static function findForTeacher(int $submissionId, int $tenantId): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT s.id, s.evaluation_id, s.student_user_id, s.filename,
                    s.stored_path, s.attempt
               FROM evaluation_submissions s
               JOIN users u ON u.id = s.student_user_id AND u.tenant_id = ?
              WHERE s.id = ?
              LIMIT 1'
        );
        $stmt->execute([$tenantId, $submissionId]);
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
