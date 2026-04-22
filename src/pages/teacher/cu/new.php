<?php
declare(strict_types=1);

/** POST /teacher/cu/new — handler de criação (E3-03). */

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

$courseId = (int)    ($_POST['course_id'] ?? 0);
$ccId     = (int)    ($_POST['cc_id']     ?? 0);
$name     = (string) ($_POST['name']      ?? '');

TeacherCurriculumController::createCu($courseId, $ccId, $name);
