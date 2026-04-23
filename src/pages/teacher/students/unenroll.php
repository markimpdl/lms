<?php
declare(strict_types=1);

/** POST /teacher/students/{id}/unenroll/{course_id} — remove matrícula (E4-02). */

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

$studentId = (int) ($_REQUEST['id']        ?? 0);
$courseId  = (int) ($_REQUEST['course_id'] ?? 0);

TeacherEnrollmentsController::unenroll($studentId, $courseId);
