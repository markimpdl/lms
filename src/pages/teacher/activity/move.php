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

// E32 (ADR-033): conteúdo via tenant do dono (dono ou colaborador).
$__activityId = (int) ($_REQUEST['id'] ?? 0);
$__courseId   = Activity::courseIdOf($__activityId);
$tenantId     = $__courseId !== null ? effective_authoring_tenant($__courseId) : null;
if ($tenantId === null) {
    http_response_code(404);
    require LMS_ROOT . '/src/templates/errors/404.php';
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
