<?php
declare(strict_types=1);

/**
 * Model de Atividade (E6-01).
 *
 * Atividade pertence a uma CU → CC → curso → tenant. Toda query valida
 * esse caminho via JOIN. Não tem nota (doc 06) — XP é creditado no
 * momento da entrega (ADR-002), e a entrega é única por aluno (UK em
 * activity_submissions).
 */
final class Activity
{
    public const TYPES = ['quiz', 'pesquisa', 'formulario', 'projeto', 'codigo'];

    /**
     * Retorna atividade + contexto (cu_id, course_id, course_archived) se
     * pertence ao tenant; null caso contrário.
     *
     * @return array<string,mixed>|null
     */
    public static function findForTenant(int $id, int $tenantId): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT a.id, a.competence_unit_id, a.title, a.instruction,
                    a.type, a.xp_value, a.submission_open,
                    a.allow_online_code_run, a.position,
                    a.created_at, a.updated_at,
                    cc.id AS cc_id, c.id AS course_id, c.archived AS course_archived
               FROM activities a
               JOIN competence_units cu   ON cu.id = a.competence_unit_id
               JOIN core_competencies cc  ON cc.id = cu.core_competency_id
               JOIN courses c             ON c.id  = cc.course_id AND c.tenant_id = ?
              WHERE a.id = ?
              LIMIT 1'
        );
        $stmt->execute([$tenantId, $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Cria atividade com position = MAX+1 da CU. Valida que a CU pertence ao
     * tenant e que o curso não está arquivado. Retorna id novo, ou 'not_found'
     * / 'course_archived'.
     *
     * @param array{title:string, instruction:string, type:string, xp_value:int, submission_open:bool, allow_online_code_run:bool} $data
     * @return int|string
     */
    public static function create(int $cuId, int $tenantId, array $data): int|string
    {
        return Database::tx(static function (PDO $pdo) use ($cuId, $tenantId, $data): int|string {
            $stmt = $pdo->prepare(
                'SELECT c.archived
                   FROM competence_units cu
                   JOIN core_competencies cc ON cc.id = cu.core_competency_id
                   JOIN courses c ON c.id = cc.course_id AND c.tenant_id = ?
                  WHERE cu.id = ?
                  LIMIT 1'
            );
            $stmt->execute([$tenantId, $cuId]);
            $row = $stmt->fetch();
            if ($row === false) {
                return 'not_found';
            }
            if ((int) $row['archived'] === 1) {
                return 'course_archived';
            }

            $stmt = $pdo->prepare(
                'SELECT COALESCE(MAX(position), -1) + 1
                   FROM activities WHERE competence_unit_id = ?'
            );
            $stmt->execute([$cuId]);
            $pos = (int) $stmt->fetchColumn();

            $ins = $pdo->prepare(
                'INSERT INTO activities
                    (competence_unit_id, title, instruction, type, xp_value,
                     submission_open, allow_online_code_run, position)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $ins->execute([
                $cuId,
                $data['title'],
                $data['instruction'],
                $data['type'],
                $data['xp_value'],
                $data['submission_open'] ? 1 : 0,
                $data['allow_online_code_run'] ? 1 : 0,
                $pos,
            ]);
            return (int) $pdo->lastInsertId();
        });
    }

    /**
     * Atualiza campos editáveis. Retorna 'ok', 'not_found' ou 'course_archived'.
     * Curso arquivado bloqueia edição.
     *
     * @param array{title:string, instruction:string, type:string, xp_value:int, submission_open:bool, allow_online_code_run:bool} $data
     */
    public static function update(int $id, int $tenantId, array $data): string
    {
        $activity = self::findForTenant($id, $tenantId);
        if ($activity === null) {
            return 'not_found';
        }
        if ((int) $activity['course_archived'] === 1) {
            return 'course_archived';
        }

        Database::pdo()->prepare(
            'UPDATE activities
                SET title = ?, instruction = ?, type = ?, xp_value = ?,
                    submission_open = ?, allow_online_code_run = ?
              WHERE id = ?'
        )->execute([
            $data['title'],
            $data['instruction'],
            $data['type'],
            $data['xp_value'],
            $data['submission_open'] ? 1 : 0,
            $data['allow_online_code_run'] ? 1 : 0,
            $id,
        ]);
        return 'ok';
    }

    /**
     * Quantas submissões existem para a atividade. Usado no aviso do form
     * de edição (doc 06: "editar é permitido mas gera aviso").
     */
    public static function countSubmissions(int $activityId): int
    {
        $stmt = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM activity_submissions WHERE activity_id = ?'
        );
        $stmt->execute([$activityId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Lista atividades da CU ordenadas por `position ASC, id ASC` com
     * contagem de submissões. Valida que a CU pertence ao tenant via
     * JOIN em courses.
     *
     * @return list<array<string,mixed>>
     */
    public static function listByCu(int $cuId, int $tenantId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT a.id, a.title, a.type, a.xp_value,
                    a.submission_open, a.allow_online_code_run, a.position,
                    (SELECT COUNT(*) FROM activity_submissions s
                      WHERE s.activity_id = a.id) AS submission_count
               FROM activities a
               JOIN competence_units cu   ON cu.id = a.competence_unit_id
               JOIN core_competencies cc  ON cc.id = cu.core_competency_id
               JOIN courses c             ON c.id  = cc.course_id AND c.tenant_id = ?
              WHERE a.competence_unit_id = ?
              ORDER BY a.position ASC, a.id ASC'
        );
        $stmt->execute([$tenantId, $cuId]);
        return $stmt->fetchAll();
    }

    /**
     * Alterna `submission_open`. Retorna novo valor (0|1) ou null se
     * atividade não pertence ao tenant / curso arquivado.
     */
    public static function toggleSubmissionOpen(int $id, int $tenantId): ?int
    {
        $activity = self::findForTenant($id, $tenantId);
        if ($activity === null || (int) $activity['course_archived'] === 1) {
            return null;
        }
        $next = (int) $activity['submission_open'] === 1 ? 0 : 1;
        Database::pdo()
            ->prepare('UPDATE activities SET submission_open = ? WHERE id = ?')
            ->execute([$next, $id]);
        return $next;
    }

    public static function moveUp(int $id, int $tenantId): bool
    {
        return self::move($id, $tenantId, -1);
    }

    public static function moveDown(int $id, int $tenantId): bool
    {
        return self::move($id, $tenantId, +1);
    }

    /**
     * Swap + renormalização das positions em 0..N-1 (padrão E3-02).
     * false se atividade não existe, não pertence ao tenant, curso
     * arquivado, ou já está na ponta.
     */
    private static function move(int $id, int $tenantId, int $offset): bool
    {
        return Database::tx(static function (PDO $pdo) use ($id, $tenantId, $offset): bool {
            $stmt = $pdo->prepare(
                'SELECT a.competence_unit_id, c.archived
                   FROM activities a
                   JOIN competence_units cu   ON cu.id = a.competence_unit_id
                   JOIN core_competencies cc  ON cc.id = cu.core_competency_id
                   JOIN courses c             ON c.id  = cc.course_id AND c.tenant_id = ?
                  WHERE a.id = ? LIMIT 1'
            );
            $stmt->execute([$tenantId, $id]);
            $row = $stmt->fetch();
            if ($row === false || (int) $row['archived'] === 1) {
                return false;
            }
            $cuId = (int) $row['competence_unit_id'];

            $stmt = $pdo->prepare(
                'SELECT id FROM activities
                  WHERE competence_unit_id = ?
                  ORDER BY position ASC, id ASC'
            );
            $stmt->execute([$cuId]);
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
                'UPDATE activities SET position = ? WHERE id = ? AND competence_unit_id = ?'
            );
            foreach ($ids as $pos => $aid) {
                $upd->execute([$pos, $aid, $cuId]);
            }
            return true;
        });
    }
}
