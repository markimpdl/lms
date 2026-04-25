<?php
declare(strict_types=1);

/**
 * POST /teacher/courses/{id}/enrollment/{student_id}/remove
 *
 * Remove a matrícula definitivamente (E17-02). DELETE limpo — `xp_events`
 * gerados naquele curso permanecem (PO confirmou: histórico preservado).
 * Confirmação por digitação do email do aluno (padrão E3-05).
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

$courseId      = (int)    ($_REQUEST['id']            ?? 0);
$studentId     = (int)    ($_REQUEST['student_id']    ?? 0);
$expectedEmail = (string) ($_POST['expected_name']    ?? '');

$result = Enrollment::deleteWithConfirm($studentId, $courseId, $tenantId, $expectedEmail);

if ($result === 'email_mismatch') {
    flash('danger', __t('delete.err.name_mismatch'));
    header('Location: /teacher/courses/' . $courseId, true, 303);
    exit;
}
if ($result !== 'ok') {
    http_response_code(404);
    require LMS_ROOT . '/src/templates/errors/404.php';
    return;
}

flash('success', __t('enrollments.remove.success'));
header('Location: /teacher/courses/' . $courseId, true, 303);
exit;
