<?php
declare(strict_types=1);

/** POST /teacher/cu/{id}/move-up|move-down (E3-03). */

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

$cuId      = (int)    ($_REQUEST['id']        ?? 0);
$direction = (string) ($_REQUEST['direction'] ?? '');
if ($direction !== 'up' && $direction !== 'down') {
    http_response_code(400);
    exit;
}

TeacherCurriculumController::moveCu($cuId, $direction);
