<?php
declare(strict_types=1);

/**
 * POST /teacher/widgets/{id}/delete — remove o widget (E35 / F26). Exclusivo do
 * dono (por tenant). Apaga o registro e a pasta extraída. Placeholders
 * [[widget:ID]] que ainda referenciem este widget renderizam "indisponível".
 */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}

$tenantId = current_tenant_id();
if ($tenantId === null) {
    http_response_code(403);
    require LMS_ROOT . '/src/templates/errors/403.php';
    return;
}

try {
    csrf_verify();
} catch (RuntimeException) {
    flash('danger', __t('auth.forbidden'));
    header('Location: /teacher/widgets');
    exit;
}

$widgetId = (int) ($_REQUEST['id'] ?? 0);
$removed  = Widget::delete($widgetId, $tenantId);

if ($removed === null) {
    http_response_code(404);
    require LMS_ROOT . '/src/templates/errors/404.php';
    return;
}

WidgetStorage::deleteDir((string) $removed['storage_path']);

flash('success', __t('widgets.deleted', ['name' => (string) $removed['name']]));
header('Location: /teacher/widgets', true, 303);
