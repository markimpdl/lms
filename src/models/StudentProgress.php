<?php
declare(strict_types=1);

/**
 * Cálculo de progresso do aluno (E6-06 + E7-03).
 *
 * Implementação real do que os helpers `student_cu_status` / `student_course_status`
 * só faziam como placeholder até agora.
 *
 * Fórmula da CU (doc/10):
 *   percent = (entregues + avaliacao_aprovada) / (N_atividades + tem_avaliacao) × 100
 *
 * "Avaliação aprovada" = existe submissão corrente (is_current=1) com
 * `grade >= 6` (nota que aprova a CU — E7-03). `tem_avaliacao` = existe
 * linha em `evaluations` para a CU (ADR-007 garante no máximo 1).
 *
 * Uma CU sem atividades e sem avaliação é "não avaliável" e conta 0% mas
 * também NÃO é incluída na média do curso (ver `coursePercent`).
 */
final class StudentProgress
{
    /**
     * % de conclusão de UMA CU pro aluno. Retorna 0-100.
     *
     * Não valida matrícula — o caller (página do aluno ou helper) já fez
     * isso antes de renderizar. Aqui é só cálculo.
     */
    public static function cuPercent(int $cuId, int $studentId): int
    {
        $stmt = Database::pdo()->prepare(
            'SELECT
                (SELECT COUNT(*) FROM activities WHERE competence_unit_id = ?) AS activities_total,
                (SELECT COUNT(*) FROM activity_submissions s
                   JOIN activities a ON a.id = s.activity_id
                  WHERE a.competence_unit_id = ? AND s.student_user_id = ?) AS activities_done,
                (SELECT COUNT(*) FROM evaluations WHERE competence_unit_id = ?) AS has_evaluation,
                (SELECT COUNT(*) FROM evaluation_submissions es
                   JOIN evaluations e ON e.id = es.evaluation_id
                  WHERE e.competence_unit_id = ?
                    AND es.student_user_id = ?
                    AND es.is_current = 1
                    AND es.grade IS NOT NULL
                    AND es.grade >= 6.0) AS evaluation_approved'
        );
        $stmt->execute([$cuId, $cuId, $studentId, $cuId, $cuId, $studentId]);
        $row = $stmt->fetch();

        $total = (int) ($row['activities_total']    ?? 0) + (int) ($row['has_evaluation']      ?? 0);
        $done  = (int) ($row['activities_done']     ?? 0) + (int) ($row['evaluation_approved'] ?? 0);

        if ($total === 0) {
            return 0;
        }
        return (int) round($done / $total * 100);
    }

    /**
     * Status derivado do percent. not_started (0) / in_progress (1-99) /
     * completed (100). Estrutura compatível com helpers/dashboard.
     *
     * @return array{status:string, percent:int}
     */
    public static function cuStatus(int $cuId, int $studentId): array
    {
        $p = self::cuPercent($cuId, $studentId);
        return ['status' => self::statusFromPercent($p), 'percent' => $p];
    }

    /**
     * % do curso pro aluno. Média das % das CUs "avaliáveis" (as que têm
     * atividades ou avaliação). CUs sem atividades e sem avaliação são
     * ignoradas (doc/10).
     */
    public static function coursePercent(int $courseId, int $studentId): int
    {
        $pdo = Database::pdo();

        $stmt = $pdo->prepare(
            'SELECT cu.id AS cu_id,
                    (SELECT COUNT(*) FROM activities a
                      WHERE a.competence_unit_id = cu.id) AS activities_total,
                    (SELECT COUNT(*) FROM evaluations ev
                      WHERE ev.competence_unit_id = cu.id) AS has_evaluation
               FROM competence_units cu
               JOIN core_competencies cc ON cc.id = cu.core_competency_id
              WHERE cc.course_id = ?'
        );
        $stmt->execute([$courseId]);

        $sum       = 0;
        $evaluable = 0;
        foreach ($stmt->fetchAll() as $row) {
            if ((int) $row['activities_total'] === 0 && (int) $row['has_evaluation'] === 0) {
                continue;
            }
            $sum += self::cuPercent((int) $row['cu_id'], $studentId);
            $evaluable++;
        }
        if ($evaluable === 0) {
            return 0;
        }
        return (int) round($sum / $evaluable);
    }

    /**
     * @return array{status:string, percent:int}
     */
    public static function courseStatus(int $courseId, int $studentId): array
    {
        $p = self::coursePercent($courseId, $studentId);
        return ['status' => self::statusFromPercent($p), 'percent' => $p];
    }

    private static function statusFromPercent(int $percent): string
    {
        return match (true) {
            $percent >= 100 => 'completed',
            $percent > 0    => 'in_progress',
            default         => 'not_started',
        };
    }
}
