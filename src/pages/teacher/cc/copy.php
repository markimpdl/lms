<?php
declare(strict_types=1);

/** POST /teacher/cc/{id}/copy — copia CC (com CUs) para outro curso (E31-03, #466). */

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

$ccId           = (int) ($_REQUEST['id']            ?? 0);
$targetCourseId = (int) ($_POST['target_course_id'] ?? 0);
TeacherCurriculumController::copyCc($ccId, $targetCourseId);
