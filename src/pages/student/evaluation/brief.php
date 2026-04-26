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
    http_response_code(403);
    require LMS_ROOT . '/src/templates/errors/403.php';
    return;
}

$evaluationId = (int) ($_REQUEST['id'] ?? 0);
$ctx = EvaluationSubmission::findForStudentEvaluation($evaluationId, (int) $user['id']);
if ($ctx === null || $ctx['evaluation']['pdf_path'] === null) {
    http_response_code(404);
    require LMS_ROOT . '/src/templates/errors/404.php';
    return;
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
