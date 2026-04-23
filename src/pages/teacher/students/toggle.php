<?php
declare(strict_types=1);

/** POST /teacher/students/{id}/toggle — alterna `active` do aluno (E4-01). */

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

$studentId = (int) ($_REQUEST['id'] ?? 0);
TeacherStudentsController::toggleActive($studentId);
