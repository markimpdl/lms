<?php
declare(strict_types=1);

/**
 * POST /teacher/courses/{id}/enrollment/{student_id}/period
 *
 * Atualiza o período de acesso do aluno na matrícula (E17-01). Aceita
 * vazio em qualquer dos dois campos pra limpar (volta a "imediato/ilimitado").
 *
 * Auth + role garantidos pelo front controller. Tenant via
 * `current_tenant_id()`. CSRF + validação + ownership cobertos aqui.
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

$startsAt = parse_datetime_local($_POST['access_starts_at'] ?? '');
$endsAt   = parse_datetime_local($_POST['access_ends_at']   ?? '');

// Vazio é OK (limpa o campo). Mas se o input tem string e não parseou,
// é erro de formato. Recusa antes de gravar.
if (($_POST['access_starts_at'] ?? '') !== '' && $startsAt === null) {
    flash('danger', __t('enrollments.error.invalid_window'));
    header('Location: /teacher/courses/' . $courseId, true, 303);
    exit;
}
if (($_POST['access_ends_at'] ?? '') !== '' && $endsAt === null) {
    flash('danger', __t('enrollments.error.invalid_window'));
    header('Location: /teacher/courses/' . $courseId, true, 303);
    exit;
}
if ($startsAt !== null && $endsAt !== null && $startsAt >= $endsAt) {
    flash('danger', __t('enrollments.error.invalid_window'));
    header('Location: /teacher/courses/' . $courseId, true, 303);
    exit;
}

$ok = Enrollment::updatePeriod($studentId, $courseId, $tenantId, $startsAt, $endsAt);
if (!$ok) {
    http_response_code(404);
    require LMS_ROOT . '/src/templates/errors/404.php';
    return;
}

flash('success', __t('enrollments.period.updated'));
header('Location: /teacher/courses/' . $courseId, true, 303);
exit;
