<?php
declare(strict_types=1);

/**
 * Model de Competence Unit (E3-03).
 *
 * Segue o mesmo padrão de CoreCompetency: swap + renormalização das positions
 * em 0..N-1 na reordenação, isolamento via JOIN até tenant.
 *
 * Validação estrita de URL (course → cc → cu) vive em findInCourseAndCc() —
 * usado pela página de detalhes da CC para evitar que um cc_id de outro
 * curso do mesmo tenant seja aberto pela URL errada.
 */
final class CompetenceUnit
{
    /**
     * Lista CUs de uma CC do tenant, em ordem (position, id), com contagens
     * de atividades e avaliações (para alimentar o modal de exclusão E3-05).
     * @return list<array<string,mixed>>
     */
    public static function listByCc(int $ccId, int $tenantId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT cu.id, cu.core_competency_id, cu.name, cu.position, cu.workload_hours,
                    cu.created_at, cu.updated_at,
                    (SELECT COUNT(*) FROM activities  WHERE competence_unit_id = cu.id) AS activities_count,
                    (SELECT COUNT(*) FROM evaluations WHERE competence_unit_id = cu.id) AS evaluations_count
               FROM competence_units cu
               JOIN core_competencies cc ON cc.id = cu.core_competency_id
               JOIN courses c ON c.id = cc.course_id AND c.tenant_id = ?
              WHERE cu.core_competency_id = ?
              ORDER BY cu.position ASC, cu.id ASC'
        );
        $stmt->execute([$tenantId, $ccId]);
        return $stmt->fetchAll();
    }

    /**
     * Conta atividades e avaliações descendentes de uma CU.
     *
     * @return array{activities:int, evaluations:int}
     */
    public static function countDescendants(int $id, int $tenantId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT
                (SELECT COUNT(*) FROM activities  WHERE competence_unit_id = ?) AS activities,
                (SELECT COUNT(*) FROM evaluations WHERE competence_unit_id = ?) AS evaluations
              FROM competence_units cu
              JOIN core_competencies cc ON cc.id = cu.core_competency_id
              JOIN courses c ON c.id = cc.course_id AND c.tenant_id = ?
              WHERE cu.id = ? LIMIT 1'
        );
        $stmt->execute([$id, $id, $tenantId, $id]);
        $row = $stmt->fetch();
        if ($row === false) {
            return ['activities' => 0, 'evaluations' => 0];
        }
        return [
            'activities'  => (int) $row['activities'],
            'evaluations' => (int) $row['evaluations'],
        ];
    }

    /**
     * Exclui CU do tenant após revalidar nome. Cascade cuida de contents,
     * activities, evaluations e suas submissões.
     *
     * Retorna 'ok' | 'not_found' | 'name_mismatch'.
     */
    public static function delete(int $id, int $tenantId, string $expectedName): string
    {
        $cu = self::findForTenant($id, $tenantId);
        if ($cu === null) {
            return 'not_found';
        }
        if ((string) $cu['name'] !== $expectedName) {
            return 'name_mismatch';
        }
        Database::pdo()
            ->prepare('DELETE FROM competence_units WHERE id = ?')
            ->execute([$id]);
        return 'ok';
    }

    /**
     * Retorna CU do tenant, ou null. Inclui course_id e course_archived para
     * callers decidirem redirects/bloqueios.
     * @return array<string,mixed>|null
     */
    public static function findForTenant(int $id, int $tenantId): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT cu.id, cu.core_competency_id, cu.name, cu.position,
                    cu.manual_completion_enabled, cu.manual_completion_xp,
                    cc.course_id, c.archived AS course_archived,
                    c.grading_mode AS course_grading_mode,
                    c.cc_mode AS course_cc_mode,
                    c.structure_version AS course_structure_version
               FROM competence_units cu
               JOIN core_competencies cc ON cc.id = cu.core_competency_id
               JOIN courses c ON c.id = cc.course_id AND c.tenant_id = ?
              WHERE cu.id = ?
              LIMIT 1'
        );
        $stmt->execute([$tenantId, $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Retorna a CC do tenant + valida estritamente que ela pertence ao curso
     * informado na URL. 404 quando qualquer parte não bate.
     * Pensado para a página /teacher/courses/{c}/cc/{cc}.
     * @return array<string,mixed>|null
     */
    public static function findCcInCourse(int $ccId, int $courseId, int $tenantId): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT cc.id, cc.course_id, cc.name, cc.position,
                    c.name AS course_name, c.archived AS course_archived
               FROM core_competencies cc
               JOIN courses c ON c.id = cc.course_id AND c.tenant_id = ?
              WHERE cc.id = ? AND cc.course_id = ?
              LIMIT 1'
        );
        $stmt->execute([$tenantId, $ccId, $courseId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Retorna CU + nomes de contexto (cu.name, cc.name, course.name, course.id)
     * se o aluno tem matrícula ativa no curso que contém a CU (E5-05).
     * null em qualquer elo que não bate (CU inexistente, aluno não matriculado).
     * Curso arquivado não bloqueia: aluno preserva acesso ao material.
     *
     * @return array<string,mixed>|null
     */
    public static function findForStudent(int $cuId, int $studentId): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT cu.id, cu.name, cu.position AS cu_position, cu.workload_hours,
                    cu.manual_completion_enabled, cu.manual_completion_xp,
                    cc.id AS cc_id, cc.name AS cc_name,
                    c.id AS course_id, c.name AS course_name, c.language AS course_language,
                    c.structure_version AS course_structure_version,
                    (SELECT COUNT(*) + 1
                       FROM competence_units cu2
                      WHERE cu2.core_competency_id = cc.id
                        AND (cu2.position < cu.position
                             OR (cu2.position = cu.position AND cu2.id < cu.id))
                    ) AS cu_index_in_cc
               FROM competence_units cu
               JOIN core_competencies cc ON cc.id = cu.core_competency_id
               JOIN courses c            ON c.id  = cc.course_id
               JOIN enrollments e        ON e.course_id = c.id
                                        AND e.student_user_id = ?
              WHERE cu.id = ?
              LIMIT 1'
        );
        $stmt->execute([$studentId, $cuId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * course_id da CU, SEM filtro de tenant. Usado pelo gating de acesso
     * compartilhado (E32) — a página resolve o curso e chama
     * effective_authoring_tenant(). Retorna null se a CU não existe.
     */
    public static function courseIdOf(int $cuId): ?int
    {
        $st = Database::pdo()->prepare(
            'SELECT cc.course_id
               FROM competence_units cu
               JOIN core_competencies cc ON cc.id = cu.core_competency_id
              WHERE cu.id = ? LIMIT 1'
        );
        $st->execute([$cuId]);
        $v = $st->fetchColumn();
        return $v === false ? null : (int) $v;
    }

    /**
     * Cria CU com position = MAX+1. Retorna id ou null se a CC não pertence
     * ao tenant ou o curso está arquivado. `workloadHours` é a carga horária
     * em horas cheias (E14-00); 0 é default (sem carga cadastrada).
     */
    public static function create(int $ccId, int $tenantId, string $name, int $workloadHours = 0): ?int
    {
        return Database::tx(static function (PDO $pdo) use ($ccId, $tenantId, $name, $workloadHours): ?int {
            $stmt = $pdo->prepare(
                'SELECT cc.id, c.archived
                   FROM core_competencies cc
                   JOIN courses c ON c.id = cc.course_id AND c.tenant_id = ?
                  WHERE cc.id = ? LIMIT 1'
            );
            $stmt->execute([$tenantId, $ccId]);
            $cc = $stmt->fetch();
            if ($cc === false || (int) $cc['archived'] === 1) {
                return null;
            }

            $stmt = $pdo->prepare(
                'SELECT COALESCE(MAX(position), -1) + 1 FROM competence_units WHERE core_competency_id = ?'
            );
            $stmt->execute([$ccId]);
            $pos = (int) $stmt->fetchColumn();

            $ins = $pdo->prepare(
                'INSERT INTO competence_units (core_competency_id, name, position, workload_hours)
                      VALUES (?, ?, ?, ?)'
            );
            $ins->execute([$ccId, $name, $pos, max(0, $workloadHours)]);
            return (int) $pdo->lastInsertId();
        });
    }

    /**
     * Atualiza nome e (opcionalmente) carga horária. `workloadHours = null`
     * mantém o valor atual — preserva compat com callers legados (E3-03
     * chamava só com nome).
     */
    public static function rename(int $id, int $tenantId, string $name, ?int $workloadHours = null): bool
    {
        $cu = self::findForTenant($id, $tenantId);
        if ($cu === null || (int) $cu['course_archived'] === 1) {
            return false;
        }
        if ($workloadHours === null) {
            $stmt = Database::pdo()->prepare('UPDATE competence_units SET name = ? WHERE id = ?');
            $stmt->execute([$name, $id]);
        } else {
            $stmt = Database::pdo()->prepare(
                'UPDATE competence_units SET name = ?, workload_hours = ? WHERE id = ?'
            );
            $stmt->execute([$name, max(0, $workloadHours), $id]);
        }
        return true;
    }

    /**
     * Soma das cargas horárias das CUs do curso — usado no CourseCard
     * (E14-02) pra mostrar "{N}h no total". Zero se curso sem CUs ou
     * sem workload cadastrado.
     */
    public static function sumWorkloadForCourse(int $courseId): int
    {
        $stmt = Database::pdo()->prepare(
            'SELECT COALESCE(SUM(cu.workload_hours), 0)
               FROM competence_units cu
               JOIN core_competencies cc ON cc.id = cu.core_competency_id
              WHERE cc.course_id = ?'
        );
        $stmt->execute([$courseId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Atualiza config de "Mark as completed" (v0.31.0). Caller valida que
     * a CU pertence ao tenant e que nao tem evaluation cadastrada.
     */
    public static function setManualCompletion(int $id, bool $enabled, int $xp): void
    {
        Database::pdo()->prepare(
            'UPDATE competence_units
                SET manual_completion_enabled = ?, manual_completion_xp = ?
              WHERE id = ?'
        )->execute([$enabled ? 1 : 0, max(0, $xp), $id]);
    }

    public static function moveUp(int $id, int $tenantId): bool
    {
        return self::move($id, $tenantId, -1);
    }

    public static function moveDown(int $id, int $tenantId): bool
    {
        return self::move($id, $tenantId, +1);
    }

    private static function move(int $id, int $tenantId, int $offset): bool
    {
        return Database::tx(static function (PDO $pdo) use ($id, $tenantId, $offset): bool {
            $stmt = $pdo->prepare(
                'SELECT cu.core_competency_id, c.archived
                   FROM competence_units cu
                   JOIN core_competencies cc ON cc.id = cu.core_competency_id
                   JOIN courses c ON c.id = cc.course_id AND c.tenant_id = ?
                  WHERE cu.id = ? LIMIT 1'
            );
            $stmt->execute([$tenantId, $id]);
            $row = $stmt->fetch();
            if ($row === false || (int) $row['archived'] === 1) {
                return false;
            }
            $ccId = (int) $row['core_competency_id'];

            $stmt = $pdo->prepare(
                'SELECT id FROM competence_units
                  WHERE core_competency_id = ?
                  ORDER BY position ASC, id ASC'
            );
            $stmt->execute([$ccId]);
            $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

            $idx = array_search($id, $ids, true);
            if ($idx === false) {
                return false;
            }
            $newIdx = $idx + $offset;
            if ($newIdx < 0 || $newIdx >= count($ids)) {
                return false;
            }

            [$ids[$idx], $ids[$newIdx]] = [$ids[$newIdx], $ids[$idx]];

            $upd = $pdo->prepare(
                'UPDATE competence_units SET position = ? WHERE id = ? AND core_competency_id = ?'
            );
            foreach ($ids as $pos => $cuId) {
                $upd->execute([$pos, $cuId, $ccId]);
            }
            return true;
        });
    }
}
