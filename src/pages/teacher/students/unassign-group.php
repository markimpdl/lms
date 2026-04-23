<?php
declare(strict_types=1);

/** POST /teacher/students/{id}/unassign-group/{group_id} — remove vínculo (E4-04). */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}

try {
    csrf_verify();
} catch (RuntimeException) {
    flash('danger', __t('auth.forbidden'));
    header('Location: /teacher/students');
    exit;
}

$studentId = (int) ($_REQUEST['id']       ?? 0);
$groupId   = (int) ($_REQUEST['group_id'] ?? 0);

TeacherGroupMembersController::unassignStudentFromGroup($studentId, $groupId);
