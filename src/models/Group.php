<?php
declare(strict_types=1);

/**
 * Model de Grupo (E4-03).
 *
 * Grupo é um agrupamento livre de alunos dentro do tenant (turma paralela,
 * equipe, etc.), independente de matrícula em curso. Unicidade por
 * `(tenant_id, name)` via UK `uk_groups_tenant_name`.
 *
 * Toda query filtra por `tenant_id`; cross-tenant é bloqueado a seco.
 * Cascade do schema cuida de `group_members` na exclusão.
 *
 * Tabela `groups` precisa de backticks: é palavra reservada no MySQL 8.
 */
final class Group
{
    public const PER_PAGE = 20;

    /** Whitelist de colunas de ORDER BY. Chave 'members' referencia o alias. */
    private const SORT_COLUMNS = [
        'name'       => 'g.name',
        'created_at' => 'g.created_at',
        'members'    => 'members_count',
    ];

    /**
     * @param array<string,string> $filters Chaves: q, sort, dir.
     * @return array{
     *   rows: list<array<string,mixed>>,
     *   total: int,
     *   page: int,
     *   total_pages: int,
     *   per_page: int,
     * }
     */
    public static function listByTenant(int $tenantId, array $filters, int $page): array
    {
        $q       = trim((string) ($filters['q']    ?? ''));
        $sortKey = (string)      ($filters['sort'] ?? 'created_at');
        $sortCol = self::SORT_COLUMNS[$sortKey] ?? self::SORT_COLUMNS['created_at'];
        $dir     = strtoupper((string) ($filters['dir'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';

        $where  = ['g.tenant_id = :tid'];
        $params = [':tid' => $tenantId];
        if ($q !== '') {
            $where[] = 'g.name LIKE :q';
            $params[':q'] = '%' . $q . '%';
        }
        $whereSql = implode(' AND ', $where);

        $pdo = Database::pdo();

        $stmtTotal = $pdo->prepare('SELECT COUNT(*) FROM `groups` g WHERE ' . $whereSql);
        $stmtTotal->execute($params);
        $total = (int) $stmtTotal->fetchColumn();

        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));
        $page       = max(1, min($page, $totalPages));
        $offset     = ($page - 1) * self::PER_PAGE;

        $sql = <<<SQL
            SELECT
                g.id, g.name, g.created_at, g.updated_at,
                COUNT(gm.student_user_id) AS members_count
            FROM `groups` g
            LEFT JOIN group_members gm ON gm.group_id = g.id
            WHERE {$whereSql}
            GROUP BY g.id, g.name, g.created_at, g.updated_at
            ORDER BY {$sortCol} {$dir}, g.id DESC
            LIMIT :limit OFFSET :offset
            SQL;

        $stmt = $pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit',  self::PER_PAGE, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset,        PDO::PARAM_INT);
        $stmt->execute();

        return [
            'rows'        => $stmt->fetchAll(),
            'total'       => $total,
            'page'        => $page,
            'total_pages' => $totalPages,
            'per_page'    => self::PER_PAGE,
        ];
    }

    /**
     * Retorna grupo do tenant + contagem de membros, ou null.
     *
     * @return array<string,mixed>|null
     */
    public static function findForTenant(int $id, int $tenantId): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT g.id, g.tenant_id, g.name, g.created_at, g.updated_at,
                    COUNT(gm.student_user_id) AS members_count
               FROM `groups` g
               LEFT JOIN group_members gm ON gm.group_id = g.id
              WHERE g.id = ? AND g.tenant_id = ?
              GROUP BY g.id, g.tenant_id, g.name, g.created_at, g.updated_at
              LIMIT 1'
        );
        $stmt->execute([$id, $tenantId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Lista enxuta de grupos do tenant — apenas id + name — para popular
     * multi-selects (ex.: atribuir aluno a grupos, E4-04). Ordenada por
     * nome asc.
     *
     * @return list<array{id:int,name:string}>
     */
    public static function listForSelect(int $tenantId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT id, name
               FROM `groups`
              WHERE tenant_id = ?
              ORDER BY name ASC, id ASC'
        );
        $stmt->execute([$tenantId]);
        $rows = $stmt->fetchAll();
        return array_map(static fn(array $r): array => [
            'id'   => (int) $r['id'],
            'name' => (string) $r['name'],
        ], $rows);
    }

    /**
     * Cria grupo. Retorna o id do grupo novo, ou string 'duplicate' se já
     * existe grupo com esse nome no tenant (UK `uk_groups_tenant_name`).
     * Pré-check SELECT para dar erro amigável mesmo antes do INSERT; o
     * catch da SQLSTATE 23000 é defensivo contra race.
     */
    public static function create(int $tenantId, string $name): int|string
    {
        $pdo = Database::pdo();

        $stmt = $pdo->prepare('SELECT 1 FROM `groups` WHERE tenant_id = ? AND name = ? LIMIT 1');
        $stmt->execute([$tenantId, $name]);
        if ($stmt->fetchColumn() !== false) {
            return 'duplicate';
        }

        try {
            $pdo->prepare('INSERT INTO `groups` (tenant_id, name) VALUES (?, ?)')
                ->execute([$tenantId, $name]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                return 'duplicate';
            }
            throw $e;
        }
        return (int) $pdo->lastInsertId();
    }

    /**
     * Renomeia grupo. Retorna:
     *   'ok'         — renomeado com sucesso
     *   'not_found'  — grupo não pertence ao tenant
     *   'duplicate'  — outro grupo do tenant já usa esse nome
     */
    public static function rename(int $id, int $tenantId, string $name): string
    {
        $group = self::findForTenant($id, $tenantId);
        if ($group === null) {
            return 'not_found';
        }

        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'SELECT 1 FROM `groups` WHERE tenant_id = ? AND name = ? AND id <> ? LIMIT 1'
        );
        $stmt->execute([$tenantId, $name, $id]);
        if ($stmt->fetchColumn() !== false) {
            return 'duplicate';
        }

        try {
            $pdo->prepare('UPDATE `groups` SET name = ? WHERE id = ? AND tenant_id = ?')
                ->execute([$name, $id, $tenantId]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                return 'duplicate';
            }
            throw $e;
        }
        return 'ok';
    }

    /**
     * Exclui grupo após revalidar o nome (confirmação por digitação, padrão
     * E3-05). Cascade do schema cuida de `group_members`.
     *
     * Retorna: 'ok' | 'not_found' | 'name_mismatch'.
     */
    public static function delete(int $id, int $tenantId, string $expectedName): string
    {
        $group = self::findForTenant($id, $tenantId);
        if ($group === null) {
            return 'not_found';
        }
        if ((string) $group['name'] !== $expectedName) {
            return 'name_mismatch';
        }
        Database::pdo()
            ->prepare('DELETE FROM `groups` WHERE id = ? AND tenant_id = ?')
            ->execute([$id, $tenantId]);
        return 'ok';
    }
}
