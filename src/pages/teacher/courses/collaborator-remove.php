<?php
declare(strict_types=1);

/** POST /teacher/courses/{id}/collaborators/{userId}/remove — remove (reversível) (E32-03, #469). */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}

try {
    csrf_verify();
} catch (RuntimeException) {
    flash('danger', __t('auth.forbidden'));
    header('Location: /teacher/courses');
    exit;
}

$courseId = (int) ($_REQUEST['id']      ?? 0);
$userId   = (int) ($_REQUEST['user_id'] ?? 0);
TeacherCollaboratorsController::remove($courseId, $userId);
