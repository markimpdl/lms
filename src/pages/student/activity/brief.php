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
    abort_subresource(403);
}

$activityId = (int) ($_REQUEST['id'] ?? 0);
$ctx = ActivitySubmission::findForStudentActivity($activityId, (int) $user['id']);
if ($ctx === null || ($ctx['activity']['pdf_path'] ?? null) === null) {
    abort_subresource(404);
}

// Unidade em rascunho: o enunciado eh material do professor e a atividade ja
// nao aparece na capa da CU. Fecha o link salvo — inclusive pra quem entregou,
// diferente de `/student/activity/{id}`: o enunciado eh do professor, a
// entrega eh do aluno. `/file` e `/delete` seguem abertos pelo mesmo motivo, e
// a tela que os oferece continua acessivel a quem tem entrega.
if (cu_is_draft_for_student((int) $ctx['activity']['cu_id'])) {
    abort_subresource(404);
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
