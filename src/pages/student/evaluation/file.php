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
    http_response_code(403);
    require LMS_ROOT . '/src/templates/errors/403.php';
    return;
}

$submissionId = (int) ($_REQUEST['sid'] ?? 0);
$submission   = EvaluationSubmission::findForStudent($submissionId, (int) $user['id']);
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
