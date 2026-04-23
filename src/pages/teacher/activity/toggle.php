<?php
declare(strict_types=1);

/**
 * POST /teacher/activity/{id}/toggle — alterna `submission_open` (E6-02).
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

$activityId = (int) ($_REQUEST['id'] ?? 0);
$activity   = Activity::findForTenant($activityId, $tenantId);
if ($activity === null) {
    http_response_code(404);
    require LMS_ROOT . '/src/templates/errors/404.php';
    return;
}

$next = Activity::toggleSubmissionOpen($activityId, $tenantId);
if ($next === null) {
    flash('danger', __t('courses.edit.archived_notice'));
} else {
    flash(
        'success',
        __t(
            $next === 1 ? 'activities.submission_opened' : 'activities.submission_closed',
            ['name' => (string) $activity['title']]
        )
    );
}

header('Location: /teacher/cu/' . (int) $activity['competence_unit_id'], true, 303);
