<?php
declare(strict_types=1);

/**
 * GET /student/activity/{id}/file[?f=<file_id>] — aluno baixa um arquivo da
 * própria submissão (E6-03). Só o autor da submissão pode ver. Sem `f` (ou
 * f=0) = arquivo legado da entrega de arquivo único. Reusa
 * AttachmentStorage::stream pra emitir headers + readfile.
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
if ($ctx === null || $ctx['submission'] === null) {
    abort_subresource(404);
}

$file = SubmissionFiles::findIn(
    SubmissionFiles::KIND_ACTIVITY,
    $ctx['submission'],
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
