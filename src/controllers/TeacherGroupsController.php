<?php
declare(strict_types=1);

/**
 * Controller de Grupos do professor (E4-03).
 *
 * Agrupamentos livres de alunos dentro do tenant. Tenant sempre resolvido
 * via `current_tenant_id()` — nunca vem do input. Nome único por tenant
 * (UK `uk_groups_tenant_name`).
 *
 * Endpoints finos: validação de input aqui, regras de persistência no
 * `Group` model.
 */
final class TeacherGroupsController
{
    /** Valida o nome do grupo. Retorna chave i18n do erro ou null. */
    public static function validateName(string $name): ?string
    {
        $name = trim($name);
        if (mb_strlen($name) < 2 || mb_strlen($name) > 100) {
            return 'groups.form.err.name';
        }
        return null;
    }

    /** POST /teacher/groups/new. */
    public static function create(string $name): void
    {
        $tenantId = current_tenant_id();
        if ($tenantId === null) {
            http_response_code(403);
            require LMS_ROOT . '/src/templates/errors/403.php';
            exit;
        }

        $name = trim($name);
        $err = self::validateName($name);
        if ($err !== null) {
            flash('danger', __t($err));
            header('Location: /teacher/groups', true, 303);
            exit;
        }

        $result = Group::create($tenantId, $name);
        if ($result === 'duplicate') {
            flash('danger', __t('groups.err.duplicate', ['name' => $name]));
            header('Location: /teacher/groups', true, 303);
            exit;
        }

        flash('success', __t('groups.created', ['name' => $name]));
        header('Location: /teacher/groups', true, 303);
        exit;
    }

    /** POST /teacher/groups/{id}/rename. */
    public static function rename(int $groupId, string $name): void
    {
        $tenantId = current_tenant_id();
        if ($tenantId === null) {
            http_response_code(403);
            require LMS_ROOT . '/src/templates/errors/403.php';
            exit;
        }

        $group = Group::findForTenant($groupId, $tenantId);
        if ($group === null) {
            http_response_code(404);
            require LMS_ROOT . '/src/templates/errors/404.php';
            exit;
        }

        $name = trim($name);
        $err = self::validateName($name);
        if ($err !== null) {
            flash('danger', __t($err));
            header('Location: /teacher/groups', true, 303);
            exit;
        }

        $result = Group::rename($groupId, $tenantId, $name);
        if ($result === 'duplicate') {
            flash('danger', __t('groups.err.duplicate', ['name' => $name]));
        } elseif ($result !== 'ok') {
            http_response_code(404);
            require LMS_ROOT . '/src/templates/errors/404.php';
            exit;
        } else {
            flash('success', __t('groups.renamed', ['name' => $name]));
        }
        header('Location: /teacher/groups', true, 303);
        exit;
    }

    /** POST /teacher/groups/{id}/delete — confirmação por digitação (E3-05). */
    public static function delete(int $groupId, string $expectedName): void
    {
        $tenantId = current_tenant_id();
        if ($tenantId === null) {
            http_response_code(403);
            require LMS_ROOT . '/src/templates/errors/403.php';
            exit;
        }

        $group = Group::findForTenant($groupId, $tenantId);
        if ($group === null) {
            http_response_code(404);
            require LMS_ROOT . '/src/templates/errors/404.php';
            exit;
        }

        $result = Group::delete($groupId, $tenantId, $expectedName);
        if ($result === 'name_mismatch') {
            flash('danger', __t('delete.err.name_mismatch'));
            header('Location: /teacher/groups', true, 303);
            exit;
        }
        if ($result !== 'ok') {
            http_response_code(404);
            require LMS_ROOT . '/src/templates/errors/404.php';
            exit;
        }

        flash('success', __t('groups.deleted', ['name' => $group['name']]));
        header('Location: /teacher/groups', true, 303);
        exit;
    }
}
