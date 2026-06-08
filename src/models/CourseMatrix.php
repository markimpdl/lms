<?php
declare(strict_types=1);

/**
 * Matriz alunos × CUs por curso (E11-02).
 *
 * Composição em 4 queries pequenas + agregação em PHP:
 *  1. Students matriculados no curso (active + inactive; cliente filtra)
 *  2. CUs do curso agrupadas por CC, com total de atividades e flag has_eval
 *  3. Contagem de entregas por (student_id, cu_id)
 *  4. Avaliação aprovada (grade ≥ 6) por (student_id, cu_id)
 *  5. Grupos de cada aluno (pro filtro client-side)
 *
 * A fórmula de cu_status é a mesma do StudentProgress::cuStatus
 * (doc/10): percent = (entregues + aprovada_na_avaliacao) / (total_ativ
 * + tem_avaliacao) × 100. Replicada aqui pra evitar N*M chamadas a
 * `student_cu_status` (1-30 alunos × 5-20 CUs = até 600 round-trips).
 *
 * Retorna estrutura pronta pro template renderizar tabela/cards.
 */
final class CourseMatrix
{
    /**
     * E32-05 (ADR-033): dois tenants. `$authoringTenantId` = tenant do DONO do
     * curso (conteúdo: CCs/CUs/avaliações — compartilhado com o colaborador via
     * `effective_authoring_tenant`). `$studentTenantId` = tenant do professor
     * que está olhando (alunos/grupos: cada um vê só os seus). Para o dono, os
     * dois coincidem — comportamento idêntico ao anterior.
     *
     * @return array{
     *   course: array<string,mixed>,
     *   ccs:    list<array{id:int, name:string, cus: list<array{id:int, name:string}>}>,
     *   students: list<array{id:int, name:string, email:string, active:int, groups: list<int>}>,
     *   cells: array<int, array<int, array{status:string, percent:int}>>,
     *   groups: list<array{id:int, name:string}>
     * }|null
     */
    public static function forCourse(int $courseId, int $authoringTenantId, int $studentTenantId): ?array
    {
        $pdo = Database::pdo();

        // Valida curso pertence ao tenant de autoria; pega metadados.
        $stmt = $pdo->prepare(
            'SELECT id, name, archived FROM courses WHERE id = ? AND tenant_id = ? LIMIT 1'
        );
        $stmt->execute([$courseId, $authoringTenantId]);
        $course = $stmt->fetch();
        if ($course === false) {
            return null;
        }

        // 1. CUs agrupadas por CC, com total_activities e has_eval
        $stmt = $pdo->prepare(
            'SELECT cu.id AS cu_id, cu.name AS cu_name, cu.position AS cu_pos,
                    cc.id AS cc_id, cc.name AS cc_name, cc.position AS cc_pos,
                    (SELECT COUNT(*) FROM activities a
                      WHERE a.competence_unit_id = cu.id) AS activity_total,
                    (SELECT COUNT(*) FROM evaluations e
                      WHERE e.competence_unit_id = cu.id AND e.tenant_id = ?) AS has_eval
               FROM competence_units cu
               JOIN core_competencies cc ON cc.id = cu.core_competency_id
              WHERE cc.course_id = ?
              ORDER BY cc.position ASC, cc.id ASC, cu.position ASC, cu.id ASC'
        );
        $stmt->execute([$authoringTenantId, $courseId]);
        $cuRows = $stmt->fetchAll();

        $ccs      = [];
        $ccIdx    = [];
        $cuTotals = [];   // cu_id => ['activity_total' => int, 'has_eval' => int]

        foreach ($cuRows as $row) {
            $ccId = (int) $row['cc_id'];
            if (!isset($ccIdx[$ccId])) {
                $ccIdx[$ccId] = count($ccs);
                $ccs[] = [
                    'id'   => $ccId,
                    'name' => (string) $row['cc_name'],
                    'cus'  => [],
                ];
            }
            $cuId = (int) $row['cu_id'];
            $ccs[$ccIdx[$ccId]]['cus'][] = [
                'id'   => $cuId,
                'name' => (string) $row['cu_name'],
            ];
            $cuTotals[$cuId] = [
                'activity_total' => (int) $row['activity_total'],
                'has_eval'       => (int) $row['has_eval'] > 0 ? 1 : 0,
            ];
        }

        // 2. Students enrolled no curso. E32-05 (ADR-033): filtra por
        // u.tenant_id do ALUNO — num curso compartilhado cada professor vê só
        // os seus alunos (a matriz é tela de roster/progresso, não de autoria).
        $stmt = $pdo->prepare(
            'SELECT u.id, u.name, u.email, u.active
               FROM enrollments e
               JOIN users u ON u.id = e.student_user_id
              WHERE e.course_id = ? AND u.tenant_id = ? AND u.role = \'student\'
              ORDER BY u.name ASC, u.id ASC'
        );
        $stmt->execute([$courseId, $studentTenantId]);
        $studentRows = $stmt->fetchAll();

        // 3. Contagem de activity_submissions por (student, cu)
        $submittedBy = [];  // [student_id][cu_id] = count
        if ($studentRows !== [] && $cuTotals !== []) {
            $stmt = $pdo->prepare(
                'SELECT s.student_user_id, a.competence_unit_id AS cu_id, COUNT(*) AS cnt
                   FROM activity_submissions s
                   JOIN activities a ON a.id = s.activity_id
                   JOIN competence_units cu ON cu.id = a.competence_unit_id
                   JOIN core_competencies cc ON cc.id = cu.core_competency_id
                  WHERE cc.course_id = ?
                  GROUP BY s.student_user_id, a.competence_unit_id'
            );
            $stmt->execute([$courseId]);
            foreach ($stmt->fetchAll() as $row) {
                $sid = (int) $row['student_user_id'];
                $cid = (int) $row['cu_id'];
                $submittedBy[$sid][$cid] = (int) $row['cnt'];
            }
        }

        // 4. Avaliação aprovada por (student, cu)
        $evalApprovedBy = [];  // [student_id][cu_id] = 1
        if ($studentRows !== [] && $cuTotals !== []) {
            $stmt = $pdo->prepare(
                'SELECT es.student_user_id, e.competence_unit_id AS cu_id
                   FROM evaluation_submissions es
                   JOIN evaluations e ON e.id = es.evaluation_id AND e.tenant_id = ?
                   JOIN competence_units cu ON cu.id = e.competence_unit_id
                   JOIN core_competencies cc ON cc.id = cu.core_competency_id
                  WHERE cc.course_id = ?
                    AND es.grade IS NOT NULL
                    AND es.grade >= 6.0'
            );
            $stmt->execute([$authoringTenantId, $courseId]);
            foreach ($stmt->fetchAll() as $row) {
                $sid = (int) $row['student_user_id'];
                $cid = (int) $row['cu_id'];
                $evalApprovedBy[$sid][$cid] = 1;
            }
        }

        // 5. Grupos de cada aluno (pro filter client-side)
        $studentGroups = [];
        if ($studentRows !== []) {
            $stmt = $pdo->prepare(
                'SELECT gm.student_user_id, gm.group_id
                   FROM group_members gm
                   JOIN `groups` g ON g.id = gm.group_id AND g.tenant_id = ?'
            );
            $stmt->execute([$studentTenantId]);
            foreach ($stmt->fetchAll() as $row) {
                $studentGroups[(int) $row['student_user_id']][] = (int) $row['group_id'];
            }
        }

        // Groups do tenant do professor que olha (pra select do filtro)
        $stmt = $pdo->prepare(
            'SELECT id, name FROM `groups` WHERE tenant_id = ? ORDER BY name ASC'
        );
        $stmt->execute([$studentTenantId]);
        $groups = array_map(static fn ($r) => [
            'id'   => (int) $r['id'],
            'name' => (string) $r['name'],
        ], $stmt->fetchAll());

        // Compõe students com grupos + cells com status/percent
        $students = [];
        $cells    = [];
        foreach ($studentRows as $s) {
            $sid = (int) $s['id'];
            $students[] = [
                'id'     => $sid,
                'name'   => (string) $s['name'],
                'email'  => (string) $s['email'],
                'active' => (int) $s['active'],
                'groups' => $studentGroups[$sid] ?? [],
            ];

            foreach ($cuTotals as $cuId => $totals) {
                $total = (int) $totals['activity_total'] + (int) $totals['has_eval'];
                if ($total === 0) {
                    // CU não-avaliável — considerada 100% "neutra"; no
                    // handoff mostra cinza. Aqui retorna not_started/0.
                    $cells[$sid][$cuId] = ['status' => 'not_started', 'percent' => 0];
                    continue;
                }
                $submitted = (int) ($submittedBy[$sid][$cuId] ?? 0);
                $approved  = isset($evalApprovedBy[$sid][$cuId]) ? 1 : 0;
                $done      = $submitted + $approved;
                $percent   = (int) min(100, round(($done / $total) * 100));
                $status    = $percent >= 100
                    ? 'completed'
                    : ($percent > 0 ? 'in_progress' : 'not_started');
                $cells[$sid][$cuId] = [
                    'status'  => $status,
                    'percent' => $percent,
                ];
            }
        }

        return [
            'course'   => [
                'id'       => (int) $course['id'],
                'name'     => (string) $course['name'],
                'archived' => (int) $course['archived'],
            ],
            'ccs'      => $ccs,
            'students' => $students,
            'cells'    => $cells,
            'groups'   => $groups,
        ];
    }
}
