<?php
declare(strict_types=1);

/** POST /teacher/students/{id}/enroll — matricular aluno em N cursos (E4-02). */

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

$studentId = (int)   ($_REQUEST['id']         ?? 0);
$courseIds = (array) ($_POST['course_ids']    ?? []);

TeacherEnrollmentsController::enrollMany($studentId, $courseIds);
