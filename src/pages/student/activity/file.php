<?php
declare(strict_types=1);

/**
 * GET /student/activity/{id}/file — aluno baixa o arquivo da própria
 * submissão (E6-03). Só o autor da submissão pode ver. Reusa
 * AttachmentStorage::stream pra emitir headers + readfile.
 *
 * Nota: `activity_submissions` não armazena mime (diferente de
 * `content_attachments`), então inferimos da extensão — o service
 * restringiu a pdf/zip/txt, o mapa é pequeno.
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
if ($ctx === null || $ctx['submission'] === null
    || $ctx['submission']['filename'] === null) {
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
