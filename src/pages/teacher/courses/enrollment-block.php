<?php
declare(strict_types=1);

/**
 * POST /teacher/courses/{id}/enrollment/{student_id}/block
 *
 * Toggle do bloqueio de acesso do aluno ao curso (E17-02). Reversível —
 * preserva XP, progresso e histórico. Diferente de `enrollment-remove.php`
 * que faz DELETE definitivo.
 *
 * Auth + role garantidos pelo front controller. Tenant via `current_tenant_id()`.
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

$result = Enrollment::toggleBlock($studentId, $courseId, $tenantId);
if ($result === null) {
    http_response_code(404);
    require LMS_ROOT . '/src/templates/errors/404.php';
    return;
}

flash('success', __t($result ? 'enrollments.block.success' : 'enrollments.unblock.success'));
header('Location: /teacher/courses/' . $courseId, true, 303);
exit;
