<?php
declare(strict_types=1);

/**
 * Model de Core Competency (E3-02).
 *
 * Todas as operações de mutação são em transação e renormalizam as positions
 * em sequência contínua (0, 1, 2, ...) ao final — mais simples que manter
 * "gaps" e garante ordem estável mesmo com dados herdados.
 *
 * Isolamento por tenant é validado via JOIN em `courses` em toda query;
 * caller nunca consegue operar em CC de outro tenant (retorno false/null
 * quando o vínculo não bate).
 */
final class CoreCompetency
{
    /**
     * Lista CCs de um curso do tenant, em ordem (position, id).
     * Retorna array vazio se o curso não pertence ao tenant.
     *
     * @return list<array<string,mixed>>
     */
    public static function listByCourse(int $courseId, int $tenantId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT cc.id, cc.course_id, cc.name, cc.position,
                    cc.created_at, cc.updated_at,
                    COUNT(cu.id) AS cu_count
               FROM core_competencies cc
               JOIN courses c ON c.id = cc.course_id AND c.tenant_id = ?
               LEFT JOIN competence_units cu ON cu.core_competency_id = cc.id
              WHERE cc.course_id = ?
              GROUP BY cc.id, cc.course_id, cc.name, cc.position,
                       cc.created_at, cc.updated_at
              ORDER BY cc.position ASC, cc.id ASC'
        );
        $stmt->execute([$tenantId, $courseId]);
        return $stmt->fetchAll();
    }

    /**
     * Retorna CC do tenant, ou null se não pertence/não existe.
     *
     * @return array<string,mixed>|null
     */
    public static function findForTenant(int $id, int $tenantId): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT cc.id, cc.course_id, cc.name, cc.position,
                    c.name AS course_name, c.archived AS course_archived
               FROM core_competencies cc
               JOIN courses c ON c.id = cc.course_id AND c.tenant_id = ?
              WHERE cc.id = ?
              LIMIT 1'
        );
        $stmt->execute([$tenantId, $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Cria CC com position = max(position)+1 dentro do curso. Retorna o id
     * novo ou null se o curso não pertence ao tenant ou está arquivado.
     */
    public static function create(int $courseId, int $tenantId, string $name): ?int
    {
        return Database::tx(static function (PDO $pdo) use ($courseId, $tenantId, $name): ?int {
            $stmt = $pdo->prepare(
                'SELECT archived FROM courses WHERE id = ? AND tenant_id = ? LIMIT 1'
            );
            $stmt->execute([$courseId, $tenantId]);
            $course = $stmt->fetch();
            if ($course === false || (int) $course['archived'] === 1) {
                return null;
            }

            $stmt = $pdo->prepare(
                'SELECT COALESCE(MAX(position), -1) + 1 FROM core_competencies WHERE course_id = ?'
            );
            $stmt->execute([$courseId]);
            $pos = (int) $stmt->fetchColumn();

            $ins = $pdo->prepare(
                'INSERT INTO core_competencies (course_id, name, position) VALUES (?, ?, ?)'
            );
            $ins->execute([$courseId, $name, $pos]);

            return (int) $pdo->lastInsertId();
        });
    }

    /**
     * Renomeia CC. Retorna false se CC não pertence ao tenant.
     */
    public static function rename(int $id, int $tenantId, string $name): bool
    {
        $cc = self::findForTenant($id, $tenantId);
        if ($cc === null || (int) $cc['course_archived'] === 1) {
            return false;
        }
        $stmt = Database::pdo()->prepare(
            'UPDATE core_competencies SET name = ? WHERE id = ?'
        );
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

    /**
     * Move CC para cima (-1) ou para baixo (+1) na lista do curso e
     * renormaliza todas as positions em sequência 0, 1, 2, ...
     * Retorna false se CC não existe, não pertence ao tenant, curso está
     * arquivado, ou já está na ponta (topo/fundo).
     */
    private static function move(int $id, int $tenantId, int $offset): bool
    {
        return Database::tx(static function (PDO $pdo) use ($id, $tenantId, $offset): bool {
            $stmt = $pdo->prepare(
                'SELECT cc.course_id, c.archived
                   FROM core_competencies cc
                   JOIN courses c ON c.id = cc.course_id AND c.tenant_id = ?
                  WHERE cc.id = ? LIMIT 1'
            );
            $stmt->execute([$tenantId, $id]);
            $row = $stmt->fetch();
            if ($row === false || (int) $row['archived'] === 1) {
                return false;
            }
            $courseId = (int) $row['course_id'];

            $stmt = $pdo->prepare(
                'SELECT id FROM core_competencies
                  WHERE course_id = ?
                  ORDER BY position ASC, id ASC'
            );
            $stmt->execute([$courseId]);
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
                'UPDATE core_competencies SET position = ? WHERE id = ? AND course_id = ?'
            );
            foreach ($ids as $pos => $ccId) {
                $upd->execute([$pos, $ccId, $courseId]);
            }
            return true;
        });
    }
}
