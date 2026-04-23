<?php
declare(strict_types=1);

/** POST /teacher/groups/{id}/remove-member/{student_id} — remove membership (E4-04). */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}

try {
    csrf_verify();
} catch (RuntimeException) {
    flash('danger', __t('auth.forbidden'));
    header('Location: /teacher/groups');
    exit;
}

$groupId   = (int) ($_REQUEST['id']         ?? 0);
$studentId = (int) ($_REQUEST['student_id'] ?? 0);

TeacherGroupMembersController::remove($groupId, $studentId);
