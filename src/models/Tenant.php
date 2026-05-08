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

    /**
     * Retorna o tenant pelo id, ou null. Útil pra páginas de config que
     * precisam ler `avatar_style` e outros campos sem reconstruir query.
     *
     * @return array<string,mixed>|null
     */
    public static function findById(int $tenantId): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT id, owner_user_id, name, active, is_actvet, platform_name, logo_path,
                    whatsapp_number, avatar_style, created_at, updated_at
               FROM tenants WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$tenantId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Atualiza o estilo do avatar do tenant (E17-04). Whitelist obrigatória
     * — caller já valida, mas defensivo aqui também.
     */
    public static function updateAvatarStyle(int $tenantId, string $style): bool
    {
        if (!in_array($style, ['arabe', 'ocidental'], true)) {
            return false;
        }
        $stmt = Database::pdo()->prepare(
            'UPDATE tenants SET avatar_style = ? WHERE id = ?'
        );
        $stmt->execute([$style, $tenantId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Alterna o flag `is_actvet` do tenant (E24-01). Usado pelo super-admin
     * em /admin/teachers/{new,edit} para marcar professores Actvet.
     */
    public static function setIsActvet(int $tenantId, bool $isActvet): void
    {
        Database::pdo()->prepare('UPDATE tenants SET is_actvet = ? WHERE id = ?')
            ->execute([$isActvet ? 1 : 0, $tenantId]);
    }

    /**
     * Atualiza o branding do tenant (E24-03): nome customizado da plataforma
     * e basename da logo customizada. Caller é responsável por respeitar a
     * regra Actvet (logo travada — não chamar com novo logoPath se Actvet)
     * e por validar tamanho/sanitização do nome.
     *
     * Strings vazias são normalizadas pra NULL pra cair no fallback do
     * `tenant_branding()`.
     */
    public static function updateBranding(int $tenantId, ?string $platformName, ?string $logoBasename): void
    {
        $name = ($platformName !== null && trim($platformName) !== '') ? trim($platformName) : null;
        $logo = ($logoBasename !== null && $logoBasename !== '')        ? $logoBasename       : null;

        Database::pdo()->prepare(
            'UPDATE tenants SET platform_name = ?, logo_path = ? WHERE id = ?'
        )->execute([$name, $logo, $tenantId]);
    }

    /** String vazia normaliza para NULL. */
    public static function updateWhatsapp(int $tenantId, ?string $whatsappDigits): void
    {
        Database::pdo()->prepare('UPDATE tenants SET whatsapp_number = ? WHERE id = ?')
            ->execute([$whatsappDigits === '' ? null : $whatsappDigits, $tenantId]);
    }

    /**
     * Zera apenas `logo_path` (mantém `platform_name`). Usado quando o
     * tenant vira Actvet — a logo customizada deixa de ser referenciada
     * pelo `tenant_branding()` (que passa a usar a logo Actvet hardcoded),
     * o arquivo no disco fica órfão e o caller deve removê-lo via
     * `LogoStorage::deleteByBasename()`.
     */
    public static function clearLogo(int $tenantId): void
    {
        Database::pdo()->prepare('UPDATE tenants SET logo_path = NULL WHERE id = ?')
            ->execute([$tenantId]);
    }
}
