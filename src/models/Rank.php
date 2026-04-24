<?php
declare(strict_types=1);

/**
 * Model de Patente (E9-01).
 *
 * Patentes são faixas de XP nomeadas pelo professor. O aluno vê a patente
 * atual no ProfileSidebar (E14-01) e quanto falta pra próxima.
 *
 * Regras:
 *  - Faixa é semi-aberta à direita: `xp_min <= xp < xp_max` (xp_max NULL =
 *    sem teto, só pode haver uma assim por tenant).
 *  - Faixas **não se sobrepõem**; gap é permitido (aluno cai em "sem patente").
 *  - Nome único por tenant.
 *  - Multi-tenant: toda query filtra por `tenant_id`.
 *
 * Sem cascade destrutivo: excluir patente não afeta aluno (o cálculo no
 * ProfileSidebar é on-the-fly via `findCurrentByXp`).
 */
final class Rank
{
    /**
     * Lista todas as patentes do tenant ordenadas por `position` ascendente
     * (mesma ordem renderizada na tabela de CRUD).
     *
     * @return list<array<string,mixed>>
     */
    public static function listForTenant(int $tenantId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT id, tenant_id, name, xp_min, xp_max, color_hex, position,
                    created_at, updated_at
               FROM ranks
              WHERE tenant_id = ?
              ORDER BY position ASC, id ASC'
        );
        $stmt->execute([$tenantId]);
        return $stmt->fetchAll();
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function findForTenant(int $id, int $tenantId): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT id, tenant_id, name, xp_min, xp_max, color_hex, position
               FROM ranks
              WHERE id = ? AND tenant_id = ?
              LIMIT 1'
        );
        $stmt->execute([$id, $tenantId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Cria patente. `$data` aceita: `name`, `xp_min`, `xp_max` (null =
     * sem teto), `color_hex` (opcional, default `#6366F1`).
     *
     * Retorna:
     *   int        — id criado
     *   'duplicate' — nome já usado por outra patente do tenant
     *   'overlap'   — faixa se sobrepõe a outra patente existente
     *
     * @param array{name:string, xp_min:int, xp_max:?int, color_hex?:string} $data
     */
    public static function create(int $tenantId, array $data): int|string
    {
        $conflict = self::checkConflicts($tenantId, $data, null);
        if ($conflict !== null) {
            return $conflict;
        }

        $color = (string) ($data['color_hex'] ?? '#6366F1');
        $position = self::nextPosition($tenantId);

        $pdo = Database::pdo();
        try {
            $pdo->prepare(
                'INSERT INTO ranks (tenant_id, name, xp_min, xp_max, color_hex, position)
                      VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([
                $tenantId,
                $data['name'],
                $data['xp_min'],
                $data['xp_max'],
                $color,
                $position,
            ]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                return 'duplicate';
            }
            throw $e;
        }
        return (int) $pdo->lastInsertId();
    }

    /**
     * Atualiza patente. Retorna 'ok' | 'not_found' | 'duplicate' | 'overlap'.
     *
     * @param array{name:string, xp_min:int, xp_max:?int, color_hex?:string} $data
     */
    public static function update(int $id, int $tenantId, array $data): string
    {
        $current = self::findForTenant($id, $tenantId);
        if ($current === null) {
            return 'not_found';
        }

        $conflict = self::checkConflicts($tenantId, $data, $id);
        if ($conflict !== null) {
            return $conflict;
        }

        $color = (string) ($data['color_hex'] ?? ($current['color_hex'] ?? '#6366F1'));

        try {
            Database::pdo()->prepare(
                'UPDATE ranks
                    SET name = ?, xp_min = ?, xp_max = ?, color_hex = ?
                  WHERE id = ? AND tenant_id = ?'
            )->execute([
                $data['name'],
                $data['xp_min'],
                $data['xp_max'],
                $color,
                $id,
                $tenantId,
            ]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                return 'duplicate';
            }
            throw $e;
        }
        return 'ok';
    }

    /**
     * Exclui patente. Retorna 'ok' | 'not_found'. Sem cascade destrutivo —
     * aluno com patente "sem correspondência" é renderizado como "Sem patente"
     * no ProfileSidebar.
     */
    public static function delete(int $id, int $tenantId): string
    {
        $rank = self::findForTenant($id, $tenantId);
        if ($rank === null) {
            return 'not_found';
        }
        Database::pdo()
            ->prepare('DELETE FROM ranks WHERE id = ? AND tenant_id = ?')
            ->execute([$id, $tenantId]);

        self::renormalizePositions($tenantId);
        return 'ok';
    }

    /** Sobe uma posição na lista (swap + renormalize). */
    public static function moveUp(int $id, int $tenantId): bool
    {
        return self::move($id, $tenantId, -1);
    }

    /** Desce uma posição na lista. */
    public static function moveDown(int $id, int $tenantId): bool
    {
        return self::move($id, $tenantId, +1);
    }

    /**
     * Patente atual do aluno dado seu XP acumulado (E14-01). Retorna o
     * primeiro rank cujo intervalo `[xp_min, xp_max)` contém `$xp`. null
     * quando nenhum bate (tenant sem patentes ou gap na faixa).
     *
     * @return array<string,mixed>|null
     */
    public static function findCurrentByXp(int $tenantId, int $xp): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT id, name, xp_min, xp_max, color_hex
               FROM ranks
              WHERE tenant_id = ?
                AND xp_min   <= ?
                AND (xp_max IS NULL OR xp_max > ?)
              ORDER BY xp_min DESC
              LIMIT 1'
        );
        $stmt->execute([$tenantId, $xp, $xp]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Próxima patente (aquela com o menor `xp_min > $xp`). null quando
     * aluno já está na faixa topo (`xp_max IS NULL`) ou não há patente
     * acima do XP atual.
     *
     * @return array<string,mixed>|null
     */
    public static function findNextByXp(int $tenantId, int $xp): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT id, name, xp_min, xp_max, color_hex
               FROM ranks
              WHERE tenant_id = ? AND xp_min > ?
              ORDER BY xp_min ASC
              LIMIT 1'
        );
        $stmt->execute([$tenantId, $xp]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    // -----------------------------------------------------------------
    // Internos
    // -----------------------------------------------------------------

    /**
     * Valida unicidade de nome e não-sobreposição de faixa contra as
     * demais patentes do tenant. `$excludeId` é passado em update pra
     * ignorar a própria linha.
     *
     * Retorna 'duplicate' | 'overlap' | null (ok).
     *
     * @param array{name:string, xp_min:int, xp_max:?int} $data
     */
    private static function checkConflicts(int $tenantId, array $data, ?int $excludeId): ?string
    {
        $others = self::listForTenant($tenantId);
        $nMin = (int)  $data['xp_min'];
        $nMax = $data['xp_max'] === null ? null : (int) $data['xp_max'];
        $name = (string) $data['name'];

        foreach ($others as $o) {
            if ($excludeId !== null && (int) $o['id'] === $excludeId) {
                continue;
            }
            if ((string) $o['name'] === $name) {
                return 'duplicate';
            }
            $oMin = (int) $o['xp_min'];
            $oMax = $o['xp_max'] === null ? null : (int) $o['xp_max'];

            // overlap = (nMax IS NULL OR nMax > oMin) AND (oMax IS NULL OR oMax > nMin)
            $rightA = ($nMax === null) || ($nMax > $oMin);
            $rightB = ($oMax === null) || ($oMax > $nMin);
            if ($rightA && $rightB) {
                return 'overlap';
            }
        }
        return null;
    }

    private static function nextPosition(int $tenantId): int
    {
        $stmt = Database::pdo()->prepare(
            'SELECT COALESCE(MAX(position), -1) + 1 FROM ranks WHERE tenant_id = ?'
        );
        $stmt->execute([$tenantId]);
        return (int) $stmt->fetchColumn();
    }

    private static function move(int $id, int $tenantId, int $offset): bool
    {
        return Database::tx(static function (PDO $pdo) use ($id, $tenantId, $offset): bool {
            $stmt = $pdo->prepare(
                'SELECT id, position FROM ranks
                  WHERE id = ? AND tenant_id = ?
                  LIMIT 1'
            );
            $stmt->execute([$id, $tenantId]);
            $current = $stmt->fetch();
            if ($current === false) {
                return false;
            }

            $stmt = $pdo->prepare(
                'SELECT id, position FROM ranks
                  WHERE tenant_id = ?
                  ORDER BY position ASC, id ASC'
            );
            $stmt->execute([$tenantId]);
            $all = $stmt->fetchAll();

            $idx = null;
            foreach ($all as $i => $r) {
                if ((int) $r['id'] === $id) {
                    $idx = $i;
                    break;
                }
            }
            if ($idx === null) {
                return false;
            }

            $target = $idx + $offset;
            if ($target < 0 || $target >= count($all)) {
                return false;
            }

            [$all[$idx], $all[$target]] = [$all[$target], $all[$idx]];

            $upd = $pdo->prepare(
                'UPDATE ranks SET position = ? WHERE id = ? AND tenant_id = ?'
            );
            foreach ($all as $pos => $row) {
                $upd->execute([$pos, (int) $row['id'], $tenantId]);
            }
            return true;
        });
    }

    /**
     * Recompacta `position` em 0..N-1 após DELETE pra manter a sequência
     * densa. Invariante: não conta com positions únicas pra funcionar,
     * só garante que a ordem lógica bate com a ordem renderizada.
     */
    private static function renormalizePositions(int $tenantId): void
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'SELECT id FROM ranks WHERE tenant_id = ? ORDER BY position ASC, id ASC'
        );
        $stmt->execute([$tenantId]);
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

        $upd = $pdo->prepare('UPDATE ranks SET position = ? WHERE id = ? AND tenant_id = ?');
        foreach ($ids as $pos => $rid) {
            $upd->execute([$pos, $rid, $tenantId]);
        }
    }
}
