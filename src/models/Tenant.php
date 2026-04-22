<?php
declare(strict_types=1);

/**
 * Operações CRUD básicas sobre `tenants` (E2-03+).
 *
 * ADR-025 mantém `owner_user_id` fixo (não é alterável por esta camada).
 * O método `rename` só muda a coluna `name`, respeitando `uk_tenants_name`
 * (unicidade case-insensitive via collation utf8mb4_unicode_ci).
 */
final class Tenant
{
    /**
     * Atualiza o nome do tenant. `null` em sucesso; chave i18n de erro se
     * o nome estiver fora do range permitido ou já estiver em uso.
     */
    public static function rename(int $tenantId, string $newName): ?string
    {
        $name = trim($newName);
        if (mb_strlen($name) < 3 || mb_strlen($name) > 120) {
            return 'admin.teachers.form.err.tenant_name';
        }

        $pdo = Database::pdo();

        // Pré-check: outro tenant com esse nome.
        $stmt = $pdo->prepare('SELECT 1 FROM tenants WHERE name = ? AND id <> ? LIMIT 1');
        $stmt->execute([$name, $tenantId]);
        if ($stmt->fetchColumn() !== false) {
            return 'admin.teachers.form.err.tenant_taken';
        }

        try {
            $pdo->prepare('UPDATE tenants SET name = ? WHERE id = ?')
                ->execute([$name, $tenantId]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                return 'admin.teachers.form.err.tenant_taken';
            }
            throw $e;
        }

        return null;
    }
}
