<?php
declare(strict_types=1);

/**
 * GET /student/evaluation/{id}/submission/{sid}/file — aluno baixa o
 * arquivo da própria submissão (qualquer tentativa, não só a corrente).
 */

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    exit;
}

$user = current_user();
if ($user === null || ($user['role'] ?? '') !== 'student') {
    abort_subresource(403);
}

$submissionId = (int) ($_REQUEST['sid'] ?? 0);
$submission   = EvaluationSubmission::findForStudent($submissionId, (int) $user['id']);
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
