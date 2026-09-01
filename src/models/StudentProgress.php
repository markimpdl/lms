<?php
declare(strict_types=1);

/**
 * Cálculo de progresso do aluno (E6-06 + E7-03).
 *
 * Implementação real do que os helpers `student_cu_status` / `student_course_status`
 * só faziam como placeholder até agora.
 *
 * Fórmula da CU (doc/10):
 *   percent = (licoes_concluidas + entregues + avaliacao_aprovada)
 *             / (N_licoes_publicadas + N_atividades + tem_avaliacao) × 100
 *
 * **Esta formula tem uma copia em SQL agregado em `CourseMatrix`** (que evita
 * N*M round-trips na matriz do professor). As duas mudam SEMPRE JUNTAS — se
 * divergirem, o mesmo aluno mostra percentuais diferentes no dashboard e na
 * matriz. Foi o que aconteceu entre a v0.31.0 e o E36-06.
 *
 * E36-06: licoes entram no calculo. **Nao precisa ramificar por formato de
 * curso**: em V1 nao existem licoes, as duas contagens dao 0 e a formula
 * resulta exatamente no que era antes. Licao em rascunho nao conta — o aluno
 * nao a ve, entao ela nao pode pesar no denominador dele.
 *
 * "Avaliação aprovada" = existe submissão (única por par eval/student desde
 * v0.29.0) com `grade >= 6` (nota que aprova a CU — E7-03). `tem_avaliacao` = existe
 * linha em `evaluations` para a CU (ADR-007 garante no máximo 1).
 *
 * Uma CU sem atividades e sem avaliação retorna 0% e **conta na média
 * do curso** (puxa pra baixo). Decisão consolidada com o PO em 2026-04-25
 * sobre o reporte de divergência entre dashboard e banner do curso —
 * banner já incluía todas as CUs no average; dashboard agora alinha.
 * Substitui a regra anterior de doc/10 que excluía "não avaliáveis"
 * da média (regra original gerava % otimista vs realidade percebida).
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
        // manual_completion conta como "slot avaliativo" mutuamente exclusivo
        // com evaluation (UI gateia), entrando no denominador como +1 e no
        // numerador quando o aluno clicou em "Mark as completed" (v0.31.0).
        $stmt = Database::pdo()->prepare(
            'SELECT
                (SELECT COUNT(*) FROM lessons
                  WHERE competence_unit_id = ? AND published = 1) AS lessons_total,
                (SELECT COUNT(*) FROM lesson_completions lc
                   JOIN lessons l ON l.id = lc.lesson_id
                  WHERE l.competence_unit_id = ?
                    AND l.published = 1
                    AND lc.student_user_id = ?) AS lessons_done,
                (SELECT COUNT(*) FROM activities WHERE competence_unit_id = ?) AS activities_total,
                (SELECT COUNT(*) FROM activity_submissions s
                   JOIN activities a ON a.id = s.activity_id
                  WHERE a.competence_unit_id = ? AND s.student_user_id = ?) AS activities_done,
                (SELECT COUNT(*) FROM evaluations WHERE competence_unit_id = ?) AS has_evaluation,
                (SELECT COUNT(*) FROM evaluation_submissions es
                   JOIN evaluations e ON e.id = es.evaluation_id
                  WHERE e.competence_unit_id = ?
                    AND es.student_user_id = ?
                    AND es.grade IS NOT NULL
                    AND es.grade >= 6.0) AS evaluation_approved,
                (SELECT manual_completion_enabled FROM competence_units WHERE id = ?) AS has_manual_completion,
                (SELECT COUNT(*) FROM cu_manual_completions
                  WHERE cu_id = ? AND student_user_id = ?) AS manual_completed'
        );
        // A ordem dos placeholders segue exatamente a ordem das subqueries.
        $stmt->execute([
            $cuId, $cuId, $studentId,   // lessons_total, lessons_done
            $cuId, $cuId, $studentId,   // activities_total, activities_done
            $cuId, $cuId, $studentId,   // has_evaluation, evaluation_approved
            $cuId, $cuId, $studentId,   // has_manual_completion, manual_completed
        ]);
        $row = $stmt->fetch();

        // Disable retroativo do manual_completion zera a contribuicao do
        // slot inteiro (denominador + numerador) — caso contrario denominador
        // dropa mas linha em cu_manual_completions sobra no numerador,
        // gerando percent > 100.
        $hasManual    = (int) ($row['has_manual_completion'] ?? 0);
        $manualDone   = (int) ($row['manual_completed']      ?? 0) * $hasManual;
        $total = (int) ($row['lessons_total']    ?? 0)
               + (int) ($row['activities_total'] ?? 0)
               + (int) ($row['has_evaluation']   ?? 0)
               + $hasManual;
        $done  = (int) ($row['lessons_done']        ?? 0)
               + (int) ($row['activities_done']     ?? 0)
               + (int) ($row['evaluation_approved'] ?? 0)
               + $manualDone;

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
     * % do curso pro aluno. Média simples das % de **todas** as CUs do
     * curso. CUs sem atividades e sem avaliação retornam 0% via
     * `cuPercent` e contam na média (puxam pra baixo). Decisão consolidada
     * com o PO em 2026-04-26 alinhando o dashboard ao banner do curso —
     * ver comentário do header da classe.
     */
    public static function coursePercent(int $courseId, int $studentId): int
    {
        $stmt = Database::pdo()->prepare(
            'SELECT cu.id AS cu_id
               FROM competence_units cu
               JOIN core_competencies cc ON cc.id = cu.core_competency_id
              WHERE cc.course_id = ?'
        );
        $stmt->execute([$courseId]);

        $sum   = 0;
        $count = 0;
        foreach ($stmt->fetchAll() as $row) {
            $sum += self::cuPercent((int) $row['cu_id'], $studentId);
            $count++;
        }
        if ($count === 0) {
            return 0;
        }
        return (int) round($sum / $count);
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
