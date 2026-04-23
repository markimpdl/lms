<?php
declare(strict_types=1);

/**
 * GET /teacher/activity/{id}/submission/{student_id}/file — professor
 * baixa o arquivo da submissão de um aluno (E6-04). Usa o mesmo streaming
 * genérico do AttachmentStorage; valida tenant via `findForTeacher`.
 */

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    exit;
}

$tenantId = current_tenant_id();
if ($tenantId === null) {
    http_response_code(403);
    require LMS_ROOT . '/src/templates/errors/403.php';
    return;
}

$activityId = (int) ($_REQUEST['id'] ?? 0);
$studentId  = (int) ($_REQUEST['student_id'] ?? 0);

$ctx = ActivitySubmission::findForTeacher($activityId, $studentId, $tenantId);
if ($ctx === null || $ctx['submission']['filename'] === null) {
    http_response_code(404);
    require LMS_ROOT . '/src/templates/errors/404.php';
    return;
}

$storedPath = (string) $ctx['submission']['stored_path'];
$ext        = strtolower(pathinfo($storedPath, PATHINFO_EXTENSION));
$mime       = match ($ext) {
    'pdf'   => 'application/pdf',
    'zip'   => 'application/zip',
    'txt'   => 'text/plain',
    default => 'application/octet-stream',
};

AttachmentStorage::stream([
    'filename'    => (string) $ctx['submission']['filename'],
    'stored_path' => $storedPath,
    'mime'        => $mime,
], 'attachment');
