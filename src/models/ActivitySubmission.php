<?php
declare(strict_types=1);

/**
 * Model de submissão de atividade (E6-03).
 *
 * 1:1 com (activity_id, student_user_id) via UK. Aluno pode editar/remover
 * enquanto `feedback_at IS NULL` (ADR-027). Após feedback, readonly.
 *
 * XP é creditado no momento da entrega (ADR-002) via `XpEvents::awardActivity`.
 * Se o aluno remove antes do feedback, XP é revogado.
 */
final class ActivitySubmission
{
    /**
     * Retorna submissão + dados da atividade + CU + curso se existir E o
     * aluno tem matrícula ativa no curso. Retorna contexto mesmo quando
     * não há submissão ainda (para a tela do aluno renderizar a atividade).
     *
     * @return array{
     *   activity: array<string,mixed>,
     *   submission: array<string,mixed>|null
     * }|null — null se atividade alheia ou aluno não matriculado
     */
    public static function findForStudentActivity(int $activityId, int $studentId): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT a.id, a.title, a.instruction, a.type, a.xp_value,
                    a.submission_open, a.allow_online_code_run,
                    cu.id AS cu_id, cu.name AS cu_name,
                    c.id  AS course_id, c.name AS course_name, c.archived AS course_archived
               FROM activities a
               JOIN competence_units cu   ON cu.id = a.competence_unit_id
               JOIN core_competencies cc  ON cc.id = cu.core_competency_id
               JOIN courses c             ON c.id  = cc.course_id
               JOIN enrollments e         ON e.course_id = c.id
                                         AND e.student_user_id = ?
              WHERE a.id = ?
              LIMIT 1'
        );
        $stmt->execute([$studentId, $activityId]);
        $activity = $stmt->fetch();
        if ($activity === false) {
            return null;
        }

        $stmt = Database::pdo()->prepare(
            'SELECT id, filename, stored_path, code_text, feedback, feedback_at,
                    created_at, updated_at
               FROM activity_submissions
              WHERE activity_id = ? AND student_user_id = ?
              LIMIT 1'
        );
        $stmt->execute([$activityId, $studentId]);
        $submission = $stmt->fetch();

        return [
            'activity'   => $activity,
            'submission' => $submission === false ? null : $submission,
        ];
    }

    /**
     * UPSERT da submissão. `filename` / `stored_path` viram NULL quando
     * o aluno só envia código (type=codigo). `code_text` NULL quando envia
     * só arquivo. Atualiza `updated_at` automaticamente.
     *
     * Usa o UK (activity_id, student_user_id) pra detectar se é insert ou
     * update — retorna 'created' ou 'updated'.
     */
    public static function upsert(
        int $activityId,
        int $studentId,
        ?string $filename,
        ?string $storedPath,
        ?string $codeText
    ): string {
        $pdo = Database::pdo();

        $stmt = $pdo->prepare(
            'SELECT id FROM activity_submissions
              WHERE activity_id = ? AND student_user_id = ?
              LIMIT 1'
        );
        $stmt->execute([$activityId, $studentId]);
        $exists = $stmt->fetchColumn() !== false;

        if ($exists) {
            $pdo->prepare(
                'UPDATE activity_submissions
                    SET filename = ?, stored_path = ?, code_text = ?
                  WHERE activity_id = ? AND student_user_id = ?'
            )->execute([$filename, $storedPath, $codeText, $activityId, $studentId]);
            return 'updated';
        }

        $pdo->prepare(
            'INSERT INTO activity_submissions
                (activity_id, student_user_id, filename, stored_path, code_text)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$activityId, $studentId, $filename, $storedPath, $codeText]);
        return 'created';
    }

    /**
     * Remove submissão do aluno. Caller validou que `feedback_at IS NULL`
     * (ADR-027 — professor ainda não deu feedback).
     */
    public static function delete(int $activityId, int $studentId): void
    {
        Database::pdo()
            ->prepare(
                'DELETE FROM activity_submissions
                  WHERE activity_id = ? AND student_user_id = ?'
            )
            ->execute([$activityId, $studentId]);
    }

    /**
     * Verifica se aluno pode editar/remover: true enquanto `feedback_at IS NULL`
     * (ADR-027). Usado pelas páginas e pelos handlers pra gatear ações.
     */
    public static function isMutable(?array $submission): bool
    {
        if ($submission === null) {
            return true; // ainda não existe, aluno pode criar
        }
        return $submission['feedback_at'] === null;
    }

    /**
     * Lista submissões de uma atividade para o professor avaliar (E6-04).
     * JOIN com users traz nome/email do aluno. Valida tenant via cadeia
     * activity → cu → cc → course. Pendentes de feedback aparecem primeiro.
     *
     * @return list<array<string,mixed>>
     */
    public static function listForActivity(int $activityId, int $tenantId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT s.id, s.student_user_id, s.filename, s.stored_path,
                    s.code_text, s.feedback, s.feedback_at,
                    s.created_at, s.updated_at,
                    u.name AS student_name, u.email AS student_email
               FROM activity_submissions s
               JOIN activities a          ON a.id = s.activity_id
               JOIN competence_units cu   ON cu.id = a.competence_unit_id
               JOIN core_competencies cc  ON cc.id = cu.core_competency_id
               JOIN courses c             ON c.id  = cc.course_id AND c.tenant_id = ?
               JOIN users u               ON u.id  = s.student_user_id
              WHERE s.activity_id = ?
              ORDER BY (s.feedback_at IS NULL) DESC, s.updated_at DESC, s.id DESC'
        );
        $stmt->execute([$tenantId, $activityId]);
        return $stmt->fetchAll();
    }

    /**
     * Retorna submissão específica (activity + student) com contexto
     * completo se a atividade pertence ao tenant do professor. Usado na
     * tela de correção/feedback (E6-04).
     *
     * @return array{
     *   activity: array<string,mixed>,
     *   submission: array<string,mixed>,
     *   student: array<string,mixed>
     * }|null
     */
    public static function findForTeacher(int $activityId, int $studentId, int $tenantId): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT s.id, s.filename, s.stored_path, s.code_text,
                    s.feedback, s.feedback_at, s.created_at, s.updated_at,
                    a.id AS activity_id, a.title AS activity_title,
                    a.type AS activity_type, a.instruction,
                    a.xp_value, cu.id AS cu_id, cu.name AS cu_name,
                    u.id AS student_id, u.name AS student_name, u.email AS student_email
               FROM activity_submissions s
               JOIN activities a          ON a.id = s.activity_id
               JOIN competence_units cu   ON cu.id = a.competence_unit_id
               JOIN core_competencies cc  ON cc.id = cu.core_competency_id
               JOIN courses c             ON c.id  = cc.course_id AND c.tenant_id = ?
               JOIN users u               ON u.id  = s.student_user_id
              WHERE s.activity_id = ? AND s.student_user_id = ?
              LIMIT 1'
        );
        $stmt->execute([$tenantId, $activityId, $studentId]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }
        return [
            'activity' => [
                'id' => (int) $row['activity_id'], 'title' => (string) $row['activity_title'],
                'type' => (string) $row['activity_type'], 'instruction' => (string) $row['instruction'],
                'xp_value' => (int) $row['xp_value'], 'cu_id' => (int) $row['cu_id'],
                'cu_name' => (string) $row['cu_name'],
            ],
            'submission' => [
                'id' => (int) $row['id'], 'filename' => $row['filename'],
                'stored_path' => $row['stored_path'], 'code_text' => $row['code_text'],
                'feedback' => $row['feedback'], 'feedback_at' => $row['feedback_at'],
                'created_at' => $row['created_at'], 'updated_at' => $row['updated_at'],
            ],
            'student' => [
                'id' => (int) $row['student_id'], 'name' => (string) $row['student_name'],
                'email' => (string) $row['student_email'],
            ],
        ];
    }

    /**
     * Grava feedback + feedback_at=NOW() no momento em que o professor salva.
     * Não altera XP nem arquivos da submissão. Após este save, aluno perde
     * capacidade de editar/remover (ADR-027 — `isMutable` vira false).
     */
    public static function saveFeedback(int $activityId, int $studentId, string $feedback): void
    {
        Database::pdo()
            ->prepare(
                'UPDATE activity_submissions
                    SET feedback = ?, feedback_at = NOW()
                  WHERE activity_id = ? AND student_user_id = ?'
            )
            ->execute([$feedback, $activityId, $studentId]);
    }

    /**
     * Lista atividades de uma CU com status da entrega do aluno (E6-06).
     * LEFT JOIN em activity_submissions: null quando não há entrega.
     * Só retorna se o aluno tem matrícula ativa no curso.
     *
     * @return list<array<string,mixed>>
     */
    public static function listForStudentInCu(int $cuId, int $studentId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT a.id, a.title, a.type, a.xp_value, a.submission_open,
                    a.position,
                    s.id AS submission_id, s.feedback_at
               FROM activities a
               JOIN competence_units cu   ON cu.id = a.competence_unit_id
               JOIN core_competencies cc  ON cc.id = cu.core_competency_id
               JOIN courses c             ON c.id  = cc.course_id
               JOIN enrollments e         ON e.course_id = c.id
                                         AND e.student_user_id = ?
               LEFT JOIN activity_submissions s
                                         ON s.activity_id = a.id
                                        AND s.student_user_id = ?
              WHERE a.competence_unit_id = ?
              ORDER BY a.position ASC, a.id ASC'
        );
        $stmt->execute([$studentId, $studentId, $cuId]);
        return $stmt->fetchAll();
    }
}
