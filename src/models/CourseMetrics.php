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
        // E32 (ADR-033): conta só os alunos do tenant do professor (não infla
        // com alunos cross-tenant de cursos compartilhados). Dono: idêntico.
        $stmt = $pdo->prepare(
            'SELECT
               (SELECT COUNT(*) FROM enrollments e
                  JOIN users u ON u.id = e.student_user_id
                 WHERE e.course_id = ? AND u.role = \'student\' AND u.active = 1
                   AND u.tenant_id = ?
               ) AS enrolled,
               (SELECT COUNT(*) FROM activity_submissions s
                  JOIN users su ON su.id = s.student_user_id AND su.tenant_id = ?
                 WHERE s.activity_id = ?) AS submitted,
               (SELECT AVG(TIMESTAMPDIFF(MINUTE, s.created_at, s.feedback_at))
                  FROM activity_submissions s
                  JOIN users su ON su.id = s.student_user_id AND su.tenant_id = ?
                 WHERE s.activity_id = ? AND s.feedback_at IS NOT NULL
                   AND s.feedback_at >= s.created_at
               ) AS avg_minutes'
        );
        $stmt->execute([$courseId, $tenantId, $tenantId, $activityId, $tenantId, $activityId]);
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

        // E32 (ADR-033): conta só os alunos do tenant do professor (não infla
        // com alunos cross-tenant de cursos compartilhados). Dono: idêntico.
        $stmt = $pdo->prepare(
            'SELECT
               (SELECT COUNT(*) FROM enrollments e
                  JOIN users u ON u.id = e.student_user_id
                 WHERE e.course_id = ? AND u.role = \'student\' AND u.active = 1
                   AND u.tenant_id = ?
               ) AS enrolled,
               (SELECT COUNT(*) FROM evaluation_submissions s
                  JOIN users su ON su.id = s.student_user_id AND su.tenant_id = ?
                 WHERE s.evaluation_id = ?
                   AND s.grade IS NOT NULL AND s.grade >= 6.0
               ) AS approved,
               (SELECT AVG(s.grade) FROM evaluation_submissions s
                  JOIN users su ON su.id = s.student_user_id AND su.tenant_id = ?
                 WHERE s.evaluation_id = ? AND s.grade IS NOT NULL
               ) AS avg_grade,
               (SELECT AVG(TIMESTAMPDIFF(MINUTE, s.created_at, s.feedback_at))
                  FROM evaluation_submissions s
                  JOIN users su ON su.id = s.student_user_id AND su.tenant_id = ?
                 WHERE s.evaluation_id = ? AND s.feedback_at IS NOT NULL
                   AND s.feedback_at >= s.created_at
               ) AS avg_minutes'
        );
        $stmt->execute([$courseId, $tenantId, $tenantId, $evaluationId, $tenantId, $evaluationId, $tenantId, $evaluationId]);
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
    public static function forCourse(int $courseId, int $tenantId, bool $showAll = false): ?array
    {
        $pdo = Database::pdo();

        // Acesso: gate por tenant do dono (o card de métricas do curso é
        // owner-only; colaborador não o vê — limitação herdada do E32-05).
        $stmt = $pdo->prepare(
            'SELECT id FROM courses WHERE id = ? AND tenant_id = ? LIMIT 1'
        );
        $stmt->execute([$courseId, $tenantId]);
        if ($stmt->fetchColumn() === false) {
            return null;
        }

        // E34 (F25/ADR-036): com $showAll (toggle em curso compartilhado), conta
        // TODOS os alunos do curso (sem filtro por tenant do aluno). Sem ele,
        // só os do tenant do professor (E32). Avaliações (conteúdo) seguem
        // sempre por e.tenant_id (= tenant do dono).
        $uf = $showAll ? '' : ' AND u.tenant_id = ?';   // enrollments (alias u)
        $sf = $showAll ? '' : ' AND su.tenant_id = ?';  // submissions  (alias su)

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
                 WHERE en.course_id = ? AND u.role = \'student\' AND u.active = 1' . $uf . '
               ) AS enrolled,
               (SELECT AVG(t.m) FROM (
                   SELECT TIMESTAMPDIFF(MINUTE, s.created_at, s.feedback_at) AS m
                     FROM activity_submissions s
                     JOIN users su ON su.id = s.student_user_id' . $sf . '
                     JOIN activities a ON a.id = s.activity_id
                     JOIN competence_units cu  ON cu.id = a.competence_unit_id
                     JOIN core_competencies cc ON cc.id = cu.core_competency_id
                    WHERE cc.course_id = ? AND s.feedback_at IS NOT NULL
                      AND s.feedback_at >= s.created_at
                   UNION ALL
                   SELECT TIMESTAMPDIFF(MINUTE, s.created_at, s.feedback_at) AS m
                     FROM evaluation_submissions s
                     JOIN users su ON su.id = s.student_user_id' . $sf . '
                     JOIN evaluations e ON e.id = s.evaluation_id AND e.tenant_id = ?
                     JOIN competence_units cu  ON cu.id = e.competence_unit_id
                     JOIN core_competencies cc ON cc.id = cu.core_competency_id
                    WHERE cc.course_id = ? AND s.feedback_at IS NOT NULL
                      AND s.feedback_at >= s.created_at
               ) t) AS avg_minutes'
        );
        $params = [$courseId, $tenantId, $courseId, $courseId];      // act_count, eval_count(tenant,course), enrolled course
        if (!$showAll) { $params[] = $tenantId; }                    // enrolled u.tenant_id
        if (!$showAll) { $params[] = $tenantId; }                    // activity feedback su.tenant_id
        $params[] = $courseId;                                       // activity feedback course
        if (!$showAll) { $params[] = $tenantId; }                    // eval feedback su.tenant_id
        $params[] = $tenantId;                                       // eval feedback e.tenant_id
        $params[] = $courseId;                                       // eval feedback course
        $stmt->execute($params);
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
                  WHERE e.course_id = ? AND u.role = \'student\' AND u.active = 1' . $uf
            );
            $stmt->execute($showAll ? [$courseId] : [$courseId, $tenantId]);
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
                   JOIN users su ON su.id = s.student_user_id' . $sf . '
                   JOIN evaluations e ON e.id = s.evaluation_id AND e.tenant_id = ?
                   JOIN competence_units cu  ON cu.id = e.competence_unit_id
                   JOIN core_competencies cc ON cc.id = cu.core_competency_id
                  WHERE cc.course_id = ?
                    AND s.grade IS NOT NULL AND s.grade >= 6.0'
            );
            $stmt->execute($showAll ? [$tenantId, $courseId] : [$tenantId, $tenantId, $courseId]);
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
