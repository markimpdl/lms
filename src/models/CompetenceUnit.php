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
     * Lista CUs de uma CC do tenant, em ordem (position, id).
     * @return list<array<string,mixed>>
     */
    public static function listByCc(int $ccId, int $tenantId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT cu.id, cu.core_competency_id, cu.name, cu.position,
                    cu.created_at, cu.updated_at
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
     * Retorna CU do tenant, ou null. Inclui course_id e course_archived para
     * callers decidirem redirects/bloqueios.
     * @return array<string,mixed>|null
     */
    public static function findForTenant(int $id, int $tenantId): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT cu.id, cu.core_competency_id, cu.name, cu.position,
                    cc.course_id, c.archived AS course_archived
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
     * Cria CU com position = MAX+1. Retorna id ou null se a CC não pertence
     * ao tenant ou o curso está arquivado.
     */
    public static function create(int $ccId, int $tenantId, string $name): ?int
    {
        return Database::tx(static function (PDO $pdo) use ($ccId, $tenantId, $name): ?int {
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
                'INSERT INTO competence_units (core_competency_id, name, position) VALUES (?, ?, ?)'
            );
            $ins->execute([$ccId, $name, $pos]);
            return (int) $pdo->lastInsertId();
        });
    }

    public static function rename(int $id, int $tenantId, string $name): bool
    {
        $cu = self::findForTenant($id, $tenantId);
        if ($cu === null || (int) $cu['course_archived'] === 1) {
            return false;
        }
        $stmt = Database::pdo()->prepare('UPDATE competence_units SET name = ? WHERE id = ?');
        $stmt->execute([$name, $id]);
        return true;
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
