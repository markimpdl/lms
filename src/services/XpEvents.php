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
               JOIN users u              ON u.id  = ? AND u.tenant_id = c.tenant_id
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
}
