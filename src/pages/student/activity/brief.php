<?php
declare(strict_types=1);

/**
 * GET /student/activity/{id}/brief — aluno baixa o PDF do brief da atividade
 * tipo `projeto` (v0.30.0). Matrícula validada via
 * `ActivitySubmission::findForStudentActivity` (mesmo gate da página show).
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

$activityId = (int) ($_REQUEST['id'] ?? 0);
$ctx = ActivitySubmission::findForStudentActivity($activityId, (int) $user['id']);
if ($ctx === null || ($ctx['activity']['pdf_path'] ?? null) === null) {
    http_response_code(404);
    require LMS_ROOT . '/src/templates/errors/404.php';
    return;
}

$storedPath = (string) $ctx['activity']['pdf_path'];
$mime       = ActivityBriefStorage::mimeFromStoredPath($storedPath);
$ext        = $mime === 'application/zip' ? 'zip' : 'pdf';
$filename   = 'brief-' . $activityId . '.' . $ext;

AttachmentStorage::stream([
    'filename'    => $filename,
    'stored_path' => $storedPath,
    'mime'        => $mime,
], 'attachment');
