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

    // Fanout `submission_closed` (E10-05) — só sino, sem email. Dispara só
    // na transição 1 → 0 (fechou a entrega). Reabrir não notifica.
    if ($next === 0) {
        $cuId = (int) $activity['competence_unit_id'];
        $cu   = CompetenceUnit::findForTenant($cuId, $tenantId);
        if ($cu !== null) {
            $courseId   = (int) $cu['course_id'];
            $studentIds = Enrollment::activeStudentIdsForCourse($courseId, $tenantId);
            if ($studentIds !== []) {
                NotificationService::fanout(
                    NotificationService::EVENT_SUBMISSION_CLOSED,
                    $studentIds,
                    (string) $activity['title'],
                    null,
                    '/student/activity/' . $activityId,
                    $courseId,
                    false
                );
            }
        }
    }
}

header('Location: /teacher/cu/' . (int) $activity['competence_unit_id'], true, 303);
