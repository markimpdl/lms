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
    abort_subresource(403);
}

$submissionId = (int) ($_REQUEST['sid'] ?? 0);
$submission   = EvaluationSubmission::findForTeacher($submissionId, $tenantId);
if ($submission === null || $submission['filename'] === null) {
    abort_subresource(404);
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
