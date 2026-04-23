<?php
declare(strict_types=1);

/**
 * POST /student/activity/{id}/delete — aluno remove sua própria submissão
 * antes do feedback (ADR-027). Revoga o XP via `XpEvents::revokeActivity`.
 */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}

$user = current_user();
if ($user === null || ($user['role'] ?? '') !== 'student') {
    http_response_code(403);
    require LMS_ROOT . '/src/templates/errors/403.php';
    return;
}

try {
    csrf_verify();
} catch (RuntimeException) {
    flash('danger', __t('auth.forbidden'));
    header('Location: /student');
    exit;
}

$studentId  = (int) $user['id'];
$tenantId   = (int) ($user['tenant_id'] ?? 0);
$activityId = (int) ($_REQUEST['id'] ?? 0);

$ctx = ActivitySubmission::findForStudentActivity($activityId, $studentId);
if ($ctx === null || $ctx['submission'] === null) {
    http_response_code(404);
    require LMS_ROOT . '/src/templates/errors/404.php';
    return;
}

if (!ActivitySubmission::isMutable($ctx['submission'])) {
    flash('danger', __t('submissions.err.after_feedback'));
    header('Location: /student/activity/' . $activityId, true, 303);
    return;
}

ActivitySubmission::delete($activityId, $studentId);
SubmissionStorage::delete($activityId, $studentId, $tenantId);
XpEvents::revokeActivity($studentId, $activityId);

flash('success', __t('submissions.deleted'));
header('Location: /student/activity/' . $activityId, true, 303);
