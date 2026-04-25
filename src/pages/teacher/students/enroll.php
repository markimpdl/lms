<?php
declare(strict_types=1);

/** POST /teacher/students/{id}/enroll — matricular aluno em N cursos (E4-02 + E17-01). */

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

// Período (E17-01): inputs `datetime-local` opcionais. Vazio = NULL.
$startsAt = parse_datetime_local($_POST['access_starts_at'] ?? '');
$endsAt   = parse_datetime_local($_POST['access_ends_at']   ?? '');

if (($_POST['access_starts_at'] ?? '') !== '' && $startsAt === null) {
    flash('danger', __t('enrollments.error.invalid_window'));
    header('Location: /teacher/students/' . $studentId, true, 303);
    exit;
}
if (($_POST['access_ends_at'] ?? '') !== '' && $endsAt === null) {
    flash('danger', __t('enrollments.error.invalid_window'));
    header('Location: /teacher/students/' . $studentId, true, 303);
    exit;
}
if ($startsAt !== null && $endsAt !== null && $startsAt >= $endsAt) {
    flash('danger', __t('enrollments.error.invalid_window'));
    header('Location: /teacher/students/' . $studentId, true, 303);
    exit;
}

TeacherEnrollmentsController::enrollMany($studentId, $courseIds, $startsAt, $endsAt);
