<?php
declare(strict_types=1);

/**
 * Visão "Alunos" da CU pro professor (E11-03).
 *
 * Monta uma matriz alunos × atividades + coluna da avaliação numa só
 * chamada. Usa 3 queries enxutas em vez de N+1:
 *  1. Alunos matriculados no curso que contém a CU
 *  2. Submissões de TODAS as atividades da CU (LEFT JOIN por aluno)
 *  3. Submissão corrente de avaliação (se existir) por aluno
 *
 * Composição final em PHP pra entregar ao template já pronto. Cada
 * aluno tem:
 *  - activity_statuses: map[activity_id => 'not_submitted' | 'pending'
 *      | 'with_feedback']
 *  - evaluation_status: array{state, grade?, feedback_at?, retry_allowed?}
 *      com state ∈ {'none', 'not_submitted', 'pending', 'approved',
 *      'failed', 'retry'} — mesmo vocabulário do E7-05 + 'none' quando
 *      a CU não tem avaliação.
 */
final class CuRoster
{
    /**
     * @return list<array{
     *   id:int,
     *   name:string,
     *   email:string,
     *   active:int,
     *   activity_statuses: array<int,string>,
     *   evaluation_status: array<string,mixed>
     * }>
     */
    public static function listForCu(int $cuId, int $tenantId): array
    {
        $pdo = Database::pdo();

        // Contexto + course_id via CU
        $stmt = $pdo->prepare(
            'SELECT c.id AS course_id,
                    (SELECT id FROM evaluations WHERE competence_unit_id = ? LIMIT 1) AS eval_id
               FROM competence_units cu
               JOIN core_competencies cc ON cc.id = cu.core_competency_id
               JOIN courses c            ON c.id  = cc.course_id AND c.tenant_id = ?
              WHERE cu.id = ?
              LIMIT 1'
        );
        $stmt->execute([$cuId, $tenantId, $cuId]);
        $ctx = $stmt->fetch();
        if ($ctx === false) {
            return [];
        }
        $courseId = (int) $ctx['course_id'];
        $evalId   = $ctx['eval_id'] !== null ? (int) $ctx['eval_id'] : null;

        // 1. Alunos matriculados (ativos + inativos; cliente filtra)
        $stmt = $pdo->prepare(
            'SELECT u.id, u.name, u.email, u.active
               FROM enrollments e
               JOIN users u ON u.id = e.student_user_id
              WHERE e.course_id = ? AND u.role = \'student\'
              ORDER BY u.name ASC, u.id ASC'
        );
        $stmt->execute([$courseId]);
        $students = $stmt->fetchAll();
        if ($students === []) {
            return [];
        }

        // 2. Submissões das atividades da CU, indexadas por (activity_id, student_id)
        $stmt = $pdo->prepare(
            'SELECT s.activity_id, s.student_user_id, s.feedback_at
               FROM activity_submissions s
               JOIN activities a ON a.id = s.activity_id
              WHERE a.competence_unit_id = ?'
        );
        $stmt->execute([$cuId]);
        $activityMap = [];
        foreach ($stmt->fetchAll() as $row) {
            $aid = (int) $row['activity_id'];
            $sid = (int) $row['student_user_id'];
            $activityMap[$aid][$sid] = [
                'feedback_at' => $row['feedback_at'],
            ];
        }

        // 3. Submissão corrente de avaliação (se existir), indexada por student
        $evalMap = [];
        if ($evalId !== null) {
            $stmt = $pdo->prepare(
                'SELECT student_user_id, grade, feedback_at, retry_allowed
                   FROM evaluation_submissions
                  WHERE evaluation_id = ? AND is_current = 1'
            );
            $stmt->execute([$evalId]);
            foreach ($stmt->fetchAll() as $row) {
                $sid = (int) $row['student_user_id'];
                $evalMap[$sid] = $row;
            }
        }

        // Pega os activity_ids pra compor o map por aluno — mesmo os sem
        // submissão precisam aparecer com 'not_submitted'.
        $stmt = $pdo->prepare(
            'SELECT id FROM activities WHERE competence_unit_id = ? ORDER BY position ASC, id ASC'
        );
        $stmt->execute([$cuId]);
        $activityIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

        $roster = [];
        foreach ($students as $s) {
            $sid = (int) $s['id'];
            $activityStatuses = [];
            foreach ($activityIds as $aid) {
                $sub = $activityMap[$aid][$sid] ?? null;
                if ($sub === null) {
                    $activityStatuses[$aid] = 'not_submitted';
                } elseif ($sub['feedback_at'] === null) {
                    $activityStatuses[$aid] = 'pending';
                } else {
                    $activityStatuses[$aid] = 'with_feedback';
                }
            }

            $evalStatus = ['state' => 'none'];
            if ($evalId !== null) {
                $ev = $evalMap[$sid] ?? null;
                if ($ev === null) {
                    $evalStatus = ['state' => 'not_submitted'];
                } elseif ($ev['feedback_at'] === null) {
                    $evalStatus = ['state' => 'pending'];
                } else {
                    $grade = $ev['grade'] !== null ? (float) $ev['grade'] : null;
                    $retry = (int) ($ev['retry_allowed'] ?? 0) === 1;
                    if ($grade !== null && $grade >= 6.0) {
                        $evalStatus = ['state' => 'approved', 'grade' => $grade];
                    } elseif ($retry) {
                        $evalStatus = ['state' => 'retry', 'grade' => $grade];
                    } else {
                        $evalStatus = ['state' => 'failed', 'grade' => $grade];
                    }
                }
            }

            $roster[] = [
                'id'                => $sid,
                'name'              => (string) $s['name'],
                'email'             => (string) $s['email'],
                'active'            => (int) $s['active'],
                'activity_statuses' => $activityStatuses,
                'evaluation_status' => $evalStatus,
            ];
        }

        return $roster;
    }
}
