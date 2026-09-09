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
    abort_subresource(403);
}

$activityId = (int) ($_REQUEST['id'] ?? 0);
$ctx = ActivitySubmission::findForStudentActivity($activityId, (int) $user['id']);
if ($ctx === null || $ctx['submission'] === null
    || $ctx['submission']['filename'] === null) {
    abort_subresource(404);
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
