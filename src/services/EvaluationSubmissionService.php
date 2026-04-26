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

    /**
     * Corrige uma submissão (E7-03). Roda em transação:
     *
     *   1. Valida que a submissão pertence ao tenant e é `is_current=1`
     *      (não deixa corrigir tentativa arquivada).
     *   2. Clamp: se `grade >= 6`, força `retry_allowed = 0` — a regra de
     *      negócio é "aprovado não reenvia", independente do que veio do
     *      form (ADR-002 + regra PO E7).
     *   3. UPDATE `grade`, `feedback`, `feedback_at=NOW()`, `retry_allowed`.
     *   4. Se `grade >= 8`, dispara `XpEvents::awardEvaluation` — idempotente
     *      via UK composite. Re-correção com mesma grade ≥ 8 não duplica XP.
     *      Re-correção com grade ≥ 8 que antes era < 8: credita na segunda.
     *
     * Códigos de retorno:
     *   'not_found'  — submissão não existe ou não pertence ao tenant
     *   'not_current' — submissão existe mas é histórica (is_current=0)
     *   'ok'         — gravado; retorna `retry_effective` (após clamp) e
     *                  `xp_awarded` (true quando o INSERT IGNORE gerou linha).
     *
     * @return array{status:string, retry_effective?:int, xp_awarded?:bool}
     */
    public static function grade(
        int $submissionId,
        int $tenantId,
        float $grade,
        string $feedback,
        bool $retryAllowed
    ): array {
        return Database::tx(
            static function (PDO $pdo) use (
                $submissionId, $tenantId, $grade, $feedback, $retryAllowed
            ): array {
                return self::applyGrade($pdo, $submissionId, $tenantId, $grade, $feedback, $retryAllowed);
            }
        );
    }

    /**
     * Variante por Learning Outcome (E25-03). Recebe nota 0-10 por LO, grava
     * em `evaluation_submission_lo_grades` (REPLACE — refazer correção é
     * idempotente), calcula média e delega pro mesmo `applyGrade` que o
     * fluxo `grade()` clássico — preserva todas as regras (clamp ≥ 6 desativa
     * retry, ≥ 8 credita XP idempotente).
     *
     * Caller responsável por:
     *  - Garantir que `$loGrades` tem exatamente 5 entradas (regra do épico)
     *  - Garantir que cada `loId` pertence à CU da avaliação (gate na page)
     *  - Garantir tenant Actvet + curso LO mode (gate na page)
     *
     * @param array<int,float> $loGrades mapa loId => grade (0..10)
     *
     * @return array{status:string, retry_effective?:int, xp_awarded?:bool, average?:float}
     */
    public static function gradeByLo(
        int $submissionId,
        int $tenantId,
        array $loGrades,
        string $feedback,
        bool $retryAllowed
    ): array {
        return Database::tx(
            static function (PDO $pdo) use (
                $submissionId, $tenantId, $loGrades, $feedback, $retryAllowed
            ): array {
                // REPLACE INTO simplifica: refazer correção sobrescreve sem
                // precisar DELETE explícito. PK composite garante 1 row por LO.
                $insert = $pdo->prepare(
                    'REPLACE INTO evaluation_submission_lo_grades (submission_id, lo_id, grade)
                          VALUES (?, ?, ?)'
                );
                $sum = 0.0;
                foreach ($loGrades as $loId => $g) {
                    $insert->execute([$submissionId, (int) $loId, (float) $g]);
                    $sum += (float) $g;
                }

                // Média com 1 decimal (DECIMAL(3,1) na tabela). PHP round()
                // half-up — consistente com o que MySQL faria pra DECIMAL.
                $average = round($sum / max(1, count($loGrades)), 1);

                return self::applyGrade(
                    $pdo, $submissionId, $tenantId, $average, $feedback, $retryAllowed
                ) + ['average' => $average];
            }
        );
    }

    /**
     * Lógica core comum a `grade()` e `gradeByLo()`: valida ownership,
     * aplica clamp do retry, UPDATE da submissão, credita XP se ≥ 8.
     * Roda dentro de uma transação aberta pelo caller — não abre `tx`
     * própria.
     *
     * @return array{status:string, retry_effective?:int, xp_awarded?:bool}
     */
    private static function applyGrade(
        PDO $pdo,
        int $submissionId,
        int $tenantId,
        float $grade,
        string $feedback,
        bool $retryAllowed
    ): array {
        $stmt = $pdo->prepare(
            'SELECT s.id, s.evaluation_id, s.student_user_id, s.is_current
               FROM evaluation_submissions s
               JOIN evaluations e ON e.id = s.evaluation_id
              WHERE s.id = ? AND e.tenant_id = ?
              LIMIT 1'
        );
        $stmt->execute([$submissionId, $tenantId]);
        $sub = $stmt->fetch();
        if ($sub === false) {
            return ['status' => 'not_found'];
        }
        if ((int) $sub['is_current'] !== 1) {
            return ['status' => 'not_current'];
        }

        $retryEffective = ($grade >= 6.0) ? 0 : ($retryAllowed ? 1 : 0);

        $pdo->prepare(
            'UPDATE evaluation_submissions
                SET grade = ?, feedback = ?,
                    feedback_at = CURRENT_TIMESTAMP,
                    retry_allowed = ?
              WHERE id = ?'
        )->execute([$grade, $feedback, $retryEffective, $submissionId]);

        $xpAwarded = false;
        if ($grade >= 8.0) {
            $xpAwarded = XpEvents::awardEvaluation(
                (int) $sub['student_user_id'],
                (int) $sub['evaluation_id']
            );
        }

        return [
            'status'          => 'ok',
            'retry_effective' => $retryEffective,
            'xp_awarded'      => $xpAwarded,
        ];
    }
}
