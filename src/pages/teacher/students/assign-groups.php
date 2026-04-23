<?php
declare(strict_types=1);

/** POST /teacher/students/{id}/assign-groups — atribui aluno a N grupos (E4-04). */

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

$studentId = (int)   ($_REQUEST['id']       ?? 0);
$groupIds  = (array) ($_POST['group_ids']   ?? []);

TeacherGroupMembersController::assignStudentToGroups($studentId, $groupIds);
