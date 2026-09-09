<?php
declare(strict_types=1);

/**
 * GET /student/evaluation/{id}/brief — aluno baixa o PDF do enunciado (E7-02).
 * Matrícula validada via `EvaluationSubmission::findForStudentEvaluation`.
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

$evaluationId = (int) ($_REQUEST['id'] ?? 0);
$ctx = EvaluationSubmission::findForStudentEvaluation($evaluationId, (int) $user['id']);
if ($ctx === null || $ctx['evaluation']['pdf_path'] === null) {
    abort_subresource(404);
}

// Unidade em rascunho: mesmo gate de activity/brief.php — enunciado eh
// material do professor. A entrega do aluno (`/file`) segue acessivel.
if (cu_is_draft_for_student((int) $ctx['evaluation']['cu_id'])) {
    abort_subresource(404);
}

$storedPath = (string) $ctx['evaluation']['pdf_path'];
$mime       = EvaluationBriefStorage::mimeFromStoredPath($storedPath);
$ext        = $mime === 'application/zip' ? 'zip' : 'pdf';
$filename   = 'enunciado-' . $evaluationId . '.' . $ext;

AttachmentStorage::stream([
    'filename'    => $filename,
    'stored_path' => $storedPath,
    'mime'        => $mime,
], 'attachment');
