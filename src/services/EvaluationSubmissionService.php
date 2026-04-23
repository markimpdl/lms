<?php
declare(strict_types=1);

/**
 * Orquestra a submissão de avaliação pelo aluno (E7-02).
 *
 * submit() roda em transação:
 *   1. Valida gates (submission_open, retry_allowed na tentativa corrente)
 *   2. Calcula próximo attempt (MAX+1)
 *   3. Flipa a corrente pra is_current=0, retry_allowed=0
 *   4. Insere nova linha is_current=1
 *
 * O arquivo no disco já foi salvo antes pelo handler via
 * `EvaluationSubmissionStorage::store($file, $evalId, $studentId, $attempt, $tenantId)`.
 * Se o UPSERT falhar a transação é revertida — o arquivo no disco fica
 * órfão mas é inofensivo (caminho inclui attempt, não colide).
 */
final class EvaluationSubmissionService
{
    /**
     * Códigos de retorno:
     *   'closed'       — submission_open=0 e sem corrente (primeira tentativa bloqueada)
     *   'no_retry'     — já tem corrente e retry_allowed=0
     *   'ok'           — inseriu (campo `attempt` no retorno)
     *
     * @return array{status:string, attempt?:int}
     */
    public static function submit(
        int $evaluationId,
        int $tenantId,
        int $studentId,
        string $filename,
        string $storedPath
    ): array {
        return Database::tx(
            static function (PDO $pdo) use (
                $evaluationId, $tenantId, $studentId, $filename, $storedPath
            ): array {
                $stmt = $pdo->prepare(
                    'SELECT submission_open FROM evaluations WHERE id = ? LIMIT 1'
                );
                $stmt->execute([$evaluationId]);
                $evalRow = $stmt->fetch();
                if ($evalRow === false) {
                    return ['status' => 'not_found'];
                }
                $isOpen = (int) $evalRow['submission_open'] === 1;

                $stmt = $pdo->prepare(
                    'SELECT id, attempt, retry_allowed
                       FROM evaluation_submissions
                      WHERE evaluation_id = ? AND student_user_id = ? AND is_current = 1
                      LIMIT 1'
                );
                $stmt->execute([$evaluationId, $studentId]);
                $current = $stmt->fetch();

                if ($current === false) {
                    if (!$isOpen) {
                        return ['status' => 'closed'];
                    }
                    $nextAttempt = 1;
                } else {
                    if ((int) $current['retry_allowed'] !== 1) {
                        return ['status' => 'no_retry'];
                    }
                    $nextAttempt = (int) $current['attempt'] + 1;

                    $pdo->prepare(
                        'UPDATE evaluation_submissions
                            SET is_current = 0, retry_allowed = 0
                          WHERE id = ?'
                    )->execute([$current['id']]);
                }

                $pdo->prepare(
                    'INSERT INTO evaluation_submissions
                        (tenant_id, evaluation_id, student_user_id, attempt,
                         filename, stored_path, is_current)
                     VALUES (?, ?, ?, ?, ?, ?, 1)'
                )->execute([
                    $tenantId,
                    $evaluationId,
                    $studentId,
                    $nextAttempt,
                    $filename,
                    $storedPath,
                ]);

                return ['status' => 'ok', 'attempt' => $nextAttempt];
            }
        );
    }
}
