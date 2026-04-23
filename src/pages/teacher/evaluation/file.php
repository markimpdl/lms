<?php
declare(strict_types=1);

/**
 * GET /teacher/evaluation/{id}/submission/{sid}/file — professor baixa o
 * arquivo de uma submissão (E7-03). Valida tenant via findForTeacher.
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

$submissionId = (int) ($_REQUEST['sid'] ?? 0);
$submission   = EvaluationSubmission::findForTeacher($submissionId, $tenantId);
if ($submission === null || $submission['filename'] === null) {
    http_response_code(404);
    require LMS_ROOT . '/src/templates/errors/404.php';
    return;
}

$storedPath = (string) $submission['stored_path'];
$ext        = strtolower(pathinfo($storedPath, PATHINFO_EXTENSION));
$mime       = match ($ext) {
    'pdf'   => 'application/pdf',
    'zip'   => 'application/zip',
    'txt'   => 'text/plain',
    default => 'application/octet-stream',
};

AttachmentStorage::stream([
    'filename'    => (string) $submission['filename'],
    'stored_path' => $storedPath,
    'mime'        => $mime,
], 'attachment');
