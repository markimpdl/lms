<?php
declare(strict_types=1);

/**
 * POST /teacher/courses/{id}/toggle-archive — alterna arquivado ↔ ativo.
 * Delega a TeacherCoursesController::toggleArchive() que faz redirect 303.
 */

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

$courseId = (int) ($_REQUEST['id'] ?? 0);
TeacherCoursesController::toggleArchive($courseId);
