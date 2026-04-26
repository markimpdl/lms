<?php
declare(strict_types=1);

/**
 * Métricas agregadas pro professor (E11-04). Usado inline em 3 pages:
 *  - `/teacher/activity/{id}/submissions` (forActivity)
 *  - `/teacher/evaluation/{id}/submissions` (forEvaluation)
 *  - `/teacher/courses/{id}` (forCourse)
 *
 * Cada método retorna uma struct consistente `{count, pct_*, avg_*}` —
 * callers fazem switch por chave presente. Todos os SELECTs filtram
 * tenant via JOIN em courses ou pela coluna redundante em evaluations.
 *
 * Tempo médio retornado em **minutos** pra flexibilidade de render;
 * `format_duration_minutes($min)` (em helpers.php) converte pra label
 * humana ("Xh Ymin" / "X dias").
 */
final class CourseMetrics
{
    /**
     * % entregues (pelos alunos ativos matriculados) + tempo médio de
     * correção pra atividade.
     *
     * @return array{
     *   enrolled:int,
     *   submitted:int,
     *   pct_submitted:int,
     *   avg_feedback_minutes:?int
     * }|null
     */
    public static function forActivity(int $activityId, int $tenantId): ?array
    {
        $pdo = Database::pdo();

        // Valida tenant + pega course_id da atividade.
        $stmt = $pdo->prepare(
            'SELECT cc.course_id
               FROM activities a
               JOIN competence_units cu  ON cu.id = a.competence_unit_id
               JOIN core_competencies cc ON cc.id = cu.core_competency_id
               JOIN courses c            ON c.id  = cc.course_id AND c.tenant_id = ?
              WHERE a.id = ?
              LIMIT 1'
        );
        $stmt->execute([$tenantId, $activityId]);
        $courseId = $stmt->fetchColumn();
        if ($courseId === false) {
            return null;
        }
        $courseId = (int) $courseId;

        // Enrolled ativos + submitted count + avg feedback time numa só query.
        $stmt = $pdo->prepare(
            'SELECT
               (SELECT COUNT(*) FROM enrollments e
                  JOIN users u ON u.id = e.student_user_id
                 WHERE e.course_id = ? AND u.role = \'student\' AND u.active = 1
               ) AS enrolled,
               (SELECT COUNT(*) FROM activity_submissions WHERE activity_id = ?) AS submitted,
               (SELECT AVG(TIMESTAMPDIFF(MINUTE, created_at, feedback_at))
                  FROM activity_submissions
                 WHERE activity_id = ? AND feedback_at IS NOT NULL
                   AND feedback_at >= created_at
               ) AS avg_minutes'
        );
        $stmt->execute([$courseId, $activityId, $activityId]);
        $row = $stmt->fetch();

        $enrolled  = (int) $row['enrolled'];
        $submitted = (int) $row['submitted'];
        $avgMin    = $row['avg_minutes'] === null ? null : (int) round((float) $row['avg_minutes']);

        return [
            'enrolled'             => $enrolled,
            'submitted'            => $submitted,
            'pct_submitted'        => $enrolled > 0 ? (int) round(($submitted / $enrolled) * 100) : 0,
            'avg_feedback_minutes' => $avgMin,
        ];
    }

    /**
     * % aprovados (grade ≥ 6 na tentativa corrente) + nota média + tempo
     * médio de correção pra avaliação.
     *
     * @return array{
     *   enrolled:int,
     *   approved:int,
     *   pct_approved:int,
     *   avg_grade:?float,
     *   avg_feedback_minutes:?int
     * }|null
     */
    public static function forEvaluation(int $evaluationId, int $tenantId): ?array
    {
        $pdo = Database::pdo();

        // Valida tenant + pega course_id.
        $stmt = $pdo->prepare(
            'SELECT cc.course_id
               FROM evaluations e
               JOIN competence_units cu  ON cu.id = e.competence_unit_id
               JOIN core_competencies cc ON cc.id = cu.core_competency_id
               JOIN courses c            ON c.id  = cc.course_id
              WHERE e.id = ? AND e.tenant_id = ?
              LIMIT 1'
        );
        $stmt->execute([$evaluationId, $tenantId]);
        $courseId = $stmt->fetchColumn();
        if ($courseId === false) {
            return null;
        }
        $courseId = (int) $courseId;

        $stmt = $pdo->prepare(
            'SELECT
               (SELECT COUNT(*) FROM enrollments e
                  JOIN users u ON u.id = e.student_user_id
                 WHERE e.course_id = ? AND u.role = \'student\' AND u.active = 1
               ) AS enrolled,
               (SELECT COUNT(*) FROM evaluation_submissions
                 WHERE evaluation_id = ? AND is_current = 1
                   AND grade IS NOT NULL AND grade >= 6.0
               ) AS approved,
               (SELECT AVG(grade) FROM evaluation_submissions
                 WHERE evaluation_id = ? AND is_current = 1 AND grade IS NOT NULL
               ) AS avg_grade,
               (SELECT AVG(TIMESTAMPDIFF(MINUTE, created_at, feedback_at))
                  FROM evaluation_submissions
                 WHERE evaluation_id = ? AND is_current = 1 AND feedback_at IS NOT NULL
                   AND feedback_at >= created_at
               ) AS avg_minutes'
        );
        $stmt->execute([$courseId, $evaluationId, $evaluationId, $evaluationId]);
        $row = $stmt->fetch();

        $enrolled = (int) $row['enrolled'];
        $approved = (int) $row['approved'];
        $avgGrade = $row['avg_grade']   === null ? null : (float) $row['avg_grade'];
        $avgMin   = $row['avg_minutes'] === null ? null : (int) round((float) $row['avg_minutes']);

        return [
            'enrolled'             => $enrolled,
            'approved'             => $approved,
            'pct_approved'         => $enrolled > 0 ? (int) round(($approved / $enrolled) * 100) : 0,
            'avg_grade'            => $avgGrade,
            'avg_feedback_minutes' => $avgMin,
        ];
    }

    /**
     * Métricas agregadas do curso.
     *
     * - activities_count, evaluations_count: totais
     * - pct_completion: alunos que concluíram o curso / alunos matriculados
     *   ("concluído" = status 'completed' via fórmula doc/10)
     * - pct_approved_avg: % aprovação agregado entre todas avaliações do
     *   curso (count(grade>=6) / count(enrolled × evaluations))
     * - avg_feedback_minutes: tempo médio global (activities + evaluations)
     *
     * @return array{
     *   activities_count:int,
     *   evaluations_count:int,
     *   enrolled:int,
     *   completed:int,
     *   pct_completion:int,
     *   pct_approved_avg:?int,
     *   avg_feedback_minutes:?int
     * }|null
     */
    public static function forCourse(int $courseId, int $tenantId): ?array
    {
        $pdo = Database::pdo();

        // Valida tenant
        $stmt = $pdo->prepare(
            'SELECT id FROM courses WHERE id = ? AND tenant_id = ? LIMIT 1'
        );
        $stmt->execute([$courseId, $tenantId]);
        if ($stmt->fetchColumn() === false) {
            return null;
        }

        // Totais + avg feedback time agregado
        $stmt = $pdo->prepare(
            'SELECT
               (SELECT COUNT(*) FROM activities a
                  JOIN competence_units cu  ON cu.id = a.competence_unit_id
                  JOIN core_competencies cc ON cc.id = cu.core_competency_id
                 WHERE cc.course_id = ?
               ) AS activities_count,
               (SELECT COUNT(*) FROM evaluations e
                 WHERE e.tenant_id = ?
                   AND e.competence_unit_id IN (
                        SELECT cu.id FROM competence_units cu
                        JOIN core_competencies cc ON cc.id = cu.core_competency_id
                       WHERE cc.course_id = ?)
               ) AS evaluations_count,
               (SELECT COUNT(*) FROM enrollments en
                  JOIN users u ON u.id = en.student_user_id
                 WHERE en.course_id = ? AND u.role = \'student\' AND u.active = 1
               ) AS enrolled,
               (SELECT AVG(t.m) FROM (
                   SELECT TIMESTAMPDIFF(MINUTE, s.created_at, s.feedback_at) AS m
                     FROM activity_submissions s
                     JOIN activities a ON a.id = s.activity_id
                     JOIN competence_units cu  ON cu.id = a.competence_unit_id
                     JOIN core_competencies cc ON cc.id = cu.core_competency_id
                    WHERE cc.course_id = ? AND s.feedback_at IS NOT NULL
                      AND s.feedback_at >= s.created_at
                   UNION ALL
                   SELECT TIMESTAMPDIFF(MINUTE, s.created_at, s.feedback_at) AS m
                     FROM evaluation_submissions s
                     JOIN evaluations e ON e.id = s.evaluation_id AND e.tenant_id = ?
                     JOIN competence_units cu  ON cu.id = e.competence_unit_id
                     JOIN core_competencies cc ON cc.id = cu.core_competency_id
                    WHERE cc.course_id = ? AND s.is_current = 1 AND s.feedback_at IS NOT NULL
                      AND s.feedback_at >= s.created_at
               ) t) AS avg_minutes'
        );
        $stmt->execute([
            $courseId,           // activities_count
            $tenantId, $courseId, // evaluations_count (tenant + course filter)
            $courseId,           // enrolled
            $courseId,           // activity feedback
            $tenantId, $courseId, // evaluation feedback
        ]);
        $row = $stmt->fetch();

        $activitiesCount  = (int) $row['activities_count'];
        $evaluationsCount = (int) $row['evaluations_count'];
        $enrolled         = (int) $row['enrolled'];
        $avgMin           = $row['avg_minutes'] === null ? null : (int) round((float) $row['avg_minutes']);

        // Completion: quantos alunos com status='completed' (via reuso do
        // StudentProgress::courseStatus — N round-trips mas N ≤ ~30 alunos).
        $completed = 0;
        if ($enrolled > 0) {
            $stmt = $pdo->prepare(
                'SELECT e.student_user_id
                   FROM enrollments e
                   JOIN users u ON u.id = e.student_user_id
                  WHERE e.course_id = ? AND u.role = \'student\' AND u.active = 1'
            );
            $stmt->execute([$courseId]);
            $studentIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
            foreach ($studentIds as $sid) {
                $s = StudentProgress::courseStatus($courseId, $sid);
                if (($s['status'] ?? '') === 'completed') {
                    $completed++;
                }
            }
        }

        // pct_approved_avg: agregado entre todas avaliações do curso
        $pctApprovedAvg = null;
        if ($evaluationsCount > 0 && $enrolled > 0) {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM evaluation_submissions s
                   JOIN evaluations e ON e.id = s.evaluation_id AND e.tenant_id = ?
                   JOIN competence_units cu  ON cu.id = e.competence_unit_id
                   JOIN core_competencies cc ON cc.id = cu.core_competency_id
                  WHERE cc.course_id = ? AND s.is_current = 1
                    AND s.grade IS NOT NULL AND s.grade >= 6.0'
            );
            $stmt->execute([$tenantId, $courseId]);
            $approvedTotal = (int) $stmt->fetchColumn();
            $denom = $evaluationsCount * $enrolled;
            $pctApprovedAvg = (int) round(($approvedTotal / $denom) * 100);
        }

        return [
            'activities_count'     => $activitiesCount,
            'evaluations_count'    => $evaluationsCount,
            'enrolled'             => $enrolled,
            'completed'            => $completed,
            'pct_completion'       => $enrolled > 0 ? (int) round(($completed / $enrolled) * 100) : 0,
            'pct_approved_avg'     => $pctApprovedAvg,
            'avg_feedback_minutes' => $avgMin,
        ];
    }
}
