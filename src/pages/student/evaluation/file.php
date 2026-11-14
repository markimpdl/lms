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
if ($submission === null) {
    abort_subresource(404);
}

// Sem `f` (ou f=0) = arquivo legado da entrega de arquivo único.
$file = SubmissionFiles::findIn(
    SubmissionFiles::KIND_EVALUATION,
    $submission,
    (int) ($_GET['f'] ?? 0)
);
if ($file === null) {
    abort_subresource(404);
}

AttachmentStorage::stream([
    'filename'    => $file['filename'],
    'stored_path' => $file['stored_path'],
    'mime'        => SubmissionFiles::mimeFor($file['stored_path']),
], 'attachment');
