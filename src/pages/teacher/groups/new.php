<?php
declare(strict_types=1);

/** POST /teacher/groups/new — cria grupo (E4-03). */

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

$name = (string) ($_POST['name'] ?? '');

TeacherGroupsController::create($name);
