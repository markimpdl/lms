<?php
declare(strict_types=1);

/**
 * Serviço de eventos de XP (E6-03).
 *
 * Contrato:
 *  - Atividade entregue → 1 linha em `xp_events` com
 *    source_type='activity', source_id=activity_id, value=activity.xp_value.
 *  - Aluno remove entrega antes do feedback (ADR-027) → revoga evento.
 *  - Idempotência via UK composite (student_user_id, source_type, source_id) —
 *    múltiplos saves da mesma submissão não duplicam XP.
 *
 * Avaliação final (E7) vai reusar este serviço com source_type='evaluation'.
 *
 * E32 (ADR-033): `xp_events.tenant_id` recebe o tenant do ALUNO (`u.tenant_id`),
 * não o do curso. Em cursos compartilhados o aluno pode ser de tenant diferente
 * do dono do curso — o XP/ranking tem de contar no tenant do aluno. O JOIN em
 * users não acopla mais `u.tenant_id = c.tenant_id` (esse acoplamento zerava o
 * XP de alunos cross-tenant). Para aluno do mesmo tenant o resultado é idêntico.
 */
final class XpEvents
{
    /**
     * Credita XP por entrega de atividade. Idempotente — se já existe evento
     * pra esse (aluno, atividade), não cria outro. Retorna true se uma linha
     * nova foi criada.
     */
    public static function awardActivity(int $studentId, int $activityId): bool
    {
        $stmt = Database::pdo()->prepare(
            'INSERT IGNORE INTO xp_events
                (student_user_id, tenant_id, course_id, source_type, source_id, value)
             SELECT ?, u.tenant_id, c.id, ?, ?, a.xp_value
               FROM activities a
               JOIN competence_units cu  ON cu.id = a.competence_unit_id
               JOIN core_competencies cc ON cc.id = cu.core_competency_id
               JOIN courses c            ON c.id  = cc.course_id
               JOIN users u              ON u.id  = ?
              WHERE a.id = ?
              LIMIT 1'
        );
        $stmt->execute([$studentId, 'activity', $activityId, $studentId, $activityId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Revoga o XP creditado por uma entrega removida. Usado quando o aluno
     * apaga sua própria submissão antes do feedback (ADR-027).
     */
    public static function revokeActivity(int $studentId, int $activityId): void
    {
        Database::pdo()
            ->prepare(
                'DELETE FROM xp_events
                  WHERE student_user_id = ?
                    AND source_type     = \'activity\'
                    AND source_id       = ?'
            )
            ->execute([$studentId, $activityId]);
    }

    /**
     * Credita XP por avaliação aprovada com nota ≥ 8 (ADR-002). Idempotente
     * via UK composite — re-correção da mesma avaliação não duplica XP.
     * Retorna true se uma linha nova foi criada. Como `grade >= 6` impede
     * reenvio (E7-03), não existe cenário de XP ser revogado retroativamente.
     */
    public static function awardEvaluation(int $studentId, int $evaluationId): bool
    {
        $stmt = Database::pdo()->prepare(
            'INSERT IGNORE INTO xp_events
                (student_user_id, tenant_id, course_id, source_type, source_id, value)
             SELECT ?, u.tenant_id, c.id, ?, ?, e.xp_value
               FROM evaluations e
               JOIN competence_units cu  ON cu.id = e.competence_unit_id
               JOIN core_competencies cc ON cc.id = cu.core_competency_id
               JOIN courses c            ON c.id  = cc.course_id
               JOIN users u              ON u.id  = ?
              WHERE e.id = ?
              LIMIT 1'
        );
        $stmt->execute([$studentId, 'evaluation', $evaluationId, $studentId, $evaluationId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Revoga XP de avaliação. Não usado no MVP (nota ≥ 6 impede reenvio, e
     * a única forma de perder XP já creditado é o professor apagar a
     * avaliação inteira, que já passa pelo cascade polimórfico). Fica aqui
     * por simetria com `revokeActivity` caso um caminho futuro precise.
     */
    public static function revokeEvaluation(int $studentId, int $evaluationId): void
    {
        Database::pdo()
            ->prepare(
                'DELETE FROM xp_events
                  WHERE student_user_id = ?
                    AND source_type     = \'evaluation\'
                    AND source_id       = ?'
            )
            ->execute([$studentId, $evaluationId]);
    }

    /**
     * Credita XP por conclusao manual de CU (v0.31.0). XP definido em
     * competence_units.manual_completion_xp. Idempotente via UK composite —
     * caller pode chamar quantas vezes quiser sem duplicar.
     */
    public static function awardCuManual(int $studentId, int $cuId): bool
    {
        $stmt = Database::pdo()->prepare(
            'INSERT IGNORE INTO xp_events
                (student_user_id, tenant_id, course_id, source_type, source_id, value)
             SELECT ?, u.tenant_id, c.id, ?, ?, cu.manual_completion_xp
               FROM competence_units cu
               JOIN core_competencies cc ON cc.id = cu.core_competency_id
               JOIN courses c            ON c.id  = cc.course_id
               JOIN users u              ON u.id  = ?
              WHERE cu.id = ?
              LIMIT 1'
        );
        $stmt->execute([$studentId, 'cu_manual', $cuId, $studentId, $cuId]);
        return $stmt->rowCount() > 0;
    }

    /** Revoga XP de conclusao manual — usado quando o professor desabilita o botao. */
    public static function revokeCuManual(int $studentId, int $cuId): void
    {
        Database::pdo()
            ->prepare(
                'DELETE FROM xp_events
                  WHERE student_user_id = ?
                    AND source_type     = \'cu_manual\'
                    AND source_id       = ?'
            )
            ->execute([$studentId, $cuId]);
    }
}
