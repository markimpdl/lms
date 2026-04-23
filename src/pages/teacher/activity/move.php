<?php
declare(strict_types=1);

/**
 * POST /teacher/activity/{id}/move-{up|down} — reordena atividade na CU (E6-02).
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
    header('Location: /teacher');
    exit;
}

$activityId = (int) ($_REQUEST['id']        ?? 0);
$direction  = (string) ($_REQUEST['direction'] ?? '');

$activity = Activity::findForTenant($activityId, $tenantId);
if ($activity === null) {
    http_response_code(404);
    require LMS_ROOT . '/src/templates/errors/404.php';
    return;
}

$ok = $direction === 'up'
    ? Activity::moveUp($activityId, $tenantId)
    : Activity::moveDown($activityId, $tenantId);

if (!$ok) {
    flash('warning', __t('activities.err.move'));
}

header('Location: /teacher/cu/' . (int) $activity['competence_unit_id'], true, 303);
