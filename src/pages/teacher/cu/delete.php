<?php
declare(strict_types=1);

/** POST /teacher/cu/{id}/delete — exclusão com confirmação por nome (E3-05). */

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

$cuId         = (int)    ($_REQUEST['id']         ?? 0);
$expectedName = (string) ($_POST['expected_name'] ?? '');

TeacherCurriculumController::deleteCu($cuId, $expectedName);
