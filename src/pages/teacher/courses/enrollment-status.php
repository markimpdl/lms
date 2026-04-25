<?php
declare(strict_types=1);

/**
 * POST /teacher/courses/{id}/enrollment/{student_id}/status
 *
 * Atualiza o status do aluno na matrícula (E16-03). Manual + indicativo —
 * não afeta XP, progresso ou ranking. Visível só pro professor.
 *
 * Auth + role garantidos pelo front controller. Tenant via
 * `current_tenant_id()`. CSRF + whitelist + ownership cobertos aqui.
 */

$tenantId = current_tenant_id();
if ($tenantId === null) {
    http_response_code(403);
    require LMS_ROOT . '/src/templates/errors/403.php';
    return;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    return;
}

try {
    csrf_verify();
} catch (RuntimeException) {
    flash('danger', __t('auth.forbidden'));
    header('Location: /teacher/courses', true, 303);
    exit;
}

$courseId  = (int) ($_REQUEST['id']         ?? 0);
$studentId = (int) ($_REQUEST['student_id'] ?? 0);
$status    = (string) ($_POST['status']     ?? '');

if (!in_array($status, ['active', 'absent', 'completed'], true)) {
    flash('danger', __t('enrollment.status.err.invalid'));
    header('Location: /teacher/courses/' . $courseId, true, 303);
    exit;
}

$ok = Enrollment::updateStatus($studentId, $courseId, $tenantId, $status);
if (!$ok) {
    http_response_code(404);
    require LMS_ROOT . '/src/templates/errors/404.php';
    return;
}

flash('success', __t('enrollment.status.updated'));
header('Location: /teacher/courses/' . $courseId, true, 303);
exit;
