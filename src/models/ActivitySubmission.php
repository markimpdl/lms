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
                    a.submission_open, a.allow_online_code_run, a.code_language,
                    a.pdf_path,
                    cu.id AS cu_id, cu.name AS cu_name,
                    c.id  AS course_id, c.name AS course_name, c.archived AS course_archived,
                    c.tenant_id AS content_tenant_id
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
            'SELECT id, filename, stored_path, code_text, quiz_snapshot,
                    feedback, feedback_at, returned_at, created_at, updated_at
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
     * Grava a entrega (cria ou atualiza) com seus arquivos, em transação.
     *
     * - `$newFiles`: arquivos já gravados no disco (via SubmissionFiles::storeAll)
     *   — viram linhas em `activity_submission_files`.
     * - `$removeIds`: ids de `activity_submission_files` que o aluno tirou;
     *   `$dropLegacy` limpa o arquivo legado (filename/stored_path da linha).
     * - Entrega devolvida pelo professor (`returned_at`): o reenvio zera
     *   `returned_at` e `feedback_at` — volta pra fila de pendentes. O texto
     *   do feedback anterior fica (o professor o vê ao corrigir de novo).
     *
     * Retorna 'created' | 'updated' | 'resubmitted' | 'locked'. 'locked' =
     * o professor corrigiu entre o GET e o POST (gate refeito sob o lock da
     * linha) — nada é gravado e o caller apaga os arquivos novos do disco.
     *
     * @param list<array{filename:string,stored_path:string,size_bytes:int}> $newFiles
     * @param list<int> $removeIds
     */
    public static function save(
        int $activityId,
        int $studentId,
        ?string $codeText,
        array $newFiles,
        array $removeIds,
        bool $dropLegacy
    ): string {
        return Database::tx(static function (PDO $pdo) use (
            $activityId, $studentId, $codeText, $newFiles, $removeIds, $dropLegacy
        ): string {
            $stmt = $pdo->prepare(
                'SELECT id, feedback_at, returned_at FROM activity_submissions
                  WHERE activity_id = ? AND student_user_id = ?
                  LIMIT 1 FOR UPDATE'
            );
            $stmt->execute([$activityId, $studentId]);
            $row = $stmt->fetch();

            if ($row === false) {
                $pdo->prepare(
                    'INSERT INTO activity_submissions
                        (activity_id, student_user_id, code_text)
                     VALUES (?, ?, ?)'
                )->execute([$activityId, $studentId, $codeText]);
                $submissionId = (int) $pdo->lastInsertId();
                SubmissionFiles::insert($pdo, SubmissionFiles::KIND_ACTIVITY, $submissionId, $newFiles);
                return 'created';
            }

            $submissionId = (int) $row['id'];
            $wasReturned  = $row['returned_at'] !== null;
            if (!self::isMutable($row)) {
                return 'locked';
            }

            $set = 'code_text = ?';
            if ($dropLegacy) {
                $set .= ', filename = NULL, stored_path = NULL';
            }
            if ($wasReturned) {
                $set .= ', returned_at = NULL, feedback_at = NULL';
            }
            $pdo->prepare(
                'UPDATE activity_submissions SET ' . $set . ' WHERE id = ?'
            )->execute([$codeText, $submissionId]);

            SubmissionFiles::deleteRows($pdo, SubmissionFiles::KIND_ACTIVITY, $submissionId, $removeIds);
            SubmissionFiles::insert($pdo, SubmissionFiles::KIND_ACTIVITY, $submissionId, $newFiles);

            return $wasReturned ? 'resubmitted' : 'updated';
        });
    }

    /**
     * Remove submissão do aluno. Caller validou `isDeletable` (ADR-027 —
     * professor ainda não deu feedback). As linhas de arquivo saem pelo
     * CASCADE; o caller apaga os arquivos do disco (listar ANTES do delete).
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
     * Aluno pode editar a entrega: enquanto `feedback_at IS NULL` (ADR-027)
     * ou quando o professor a devolveu pra correção (`returned_at`).
     */
    public static function isMutable(?array $submission): bool
    {
        if ($submission === null) {
            return true; // ainda não existe, aluno pode criar
        }
        return $submission['feedback_at'] === null || self::isReturned($submission);
    }

    /**
     * Aluno pode REMOVER a entrega inteira só se o professor nunca a viu
     * corrigida: sem feedback_at E sem texto de feedback. Entrega devolvida
     * (ou já reenviada depois de devolvida) se corrige — remover revogaria o
     * XP e apagaria o histórico de correção.
     */
    public static function isDeletable(?array $submission): bool
    {
        return $submission !== null
            && $submission['feedback_at'] === null
            && ($submission['feedback'] ?? null) === null;
    }

    public static function isReturned(?array $submission): bool
    {
        return $submission !== null && ($submission['returned_at'] ?? null) !== null;
    }

    /**
     * Professor devolve a entrega pro aluno corrigir e reenviar. Grava o
     * feedback (o que precisa corrigir) + feedback_at + returned_at. Só
     * atividades código/projeto — quiz é auto-corrigido.
     */
    public static function returnForCorrection(int $activityId, int $studentId, string $feedback): void
    {
        Database::pdo()
            ->prepare(
                'UPDATE activity_submissions
                    SET feedback = ?, feedback_at = NOW(), returned_at = NOW()
                  WHERE activity_id = ? AND student_user_id = ?'
            )
            ->execute([$feedback, $activityId, $studentId]);
    }

    /**
     * Lista submissões de uma atividade para o professor avaliar (E6-04).
     * JOIN com users traz nome/email do aluno. Valida tenant via cadeia
     * activity → cu → cc → course. Pendentes de feedback aparecem primeiro.
     *
     * @return list<array<string,mixed>>
     */
    public static function listForActivity(int $activityId, int $tenantId, bool $showAll = false): array
    {
        // E32: por padrão só os alunos do tenant do professor agindo. E34/ADR-040:
        // com $showAll (toggle em curso compartilhado) todos os alunos do curso;
        // `is_own` marca os meus. Acesso à atividade gateado na página.
        $tf = $showAll ? '' : ' AND u.tenant_id = ?';
        $stmt = Database::pdo()->prepare(
            'SELECT s.id, s.student_user_id, s.filename, s.stored_path,
                    s.code_text, s.feedback, s.feedback_at, s.returned_at,
                    s.created_at, s.updated_at,
                    ((SELECT COUNT(*) FROM activity_submission_files f
                       WHERE f.submission_id = s.id)
                     + (s.stored_path IS NOT NULL)) AS file_count,
                    u.name AS student_name, u.email AS student_email,
                    (u.tenant_id = ?) AS is_own
               FROM activity_submissions s
               JOIN activities a          ON a.id = s.activity_id
               JOIN competence_units cu   ON cu.id = a.competence_unit_id
               JOIN core_competencies cc  ON cc.id = cu.core_competency_id
               JOIN courses c             ON c.id  = cc.course_id
               JOIN users u               ON u.id  = s.student_user_id' . $tf . '
              WHERE s.activity_id = ?
              ORDER BY (s.feedback_at IS NULL) DESC, s.updated_at DESC, s.id DESC'
        );
        $stmt->execute($showAll ? [$tenantId, $activityId] : [$tenantId, $tenantId, $activityId]);
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
            'SELECT s.id, s.filename, s.stored_path, s.code_text, s.quiz_snapshot,
                    s.feedback, s.feedback_at, s.returned_at, s.created_at, s.updated_at,
                    a.id AS activity_id, a.title AS activity_title,
                    a.type AS activity_type, a.instruction,
                    a.xp_value, cu.id AS cu_id, cu.name AS cu_name,
                    u.id AS student_id, u.name AS student_name, u.email AS student_email
               FROM activity_submissions s
               JOIN activities a          ON a.id = s.activity_id
               JOIN competence_units cu   ON cu.id = a.competence_unit_id
               JOIN core_competencies cc  ON cc.id = cu.core_competency_id
               JOIN courses c             ON c.id  = cc.course_id
               JOIN users u               ON u.id  = s.student_user_id AND u.tenant_id = ?
              WHERE s.activity_id = ? AND s.student_user_id = ?
              LIMIT 1'
        );
        // $tenantId = tenant do ALUNO, resolvido por teacher_grading_student_tenant
        // na página (ADR-040) — é lá que mora a autorização do professor.
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
                'quiz_snapshot' => $row['quiz_snapshot'],
                'feedback' => $row['feedback'], 'feedback_at' => $row['feedback_at'],
                'returned_at' => $row['returned_at'],
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
                    SET feedback = ?, feedback_at = NOW(), returned_at = NULL
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
                    s.id AS submission_id, s.feedback_at, s.returned_at
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
