<?php
declare(strict_types=1);

/**
 * GET /teacher/activity/{id}/submission/{student_id}/file[?f=<id>] — professor
 * baixa um arquivo da submissão de um aluno (E6-04). Usa o mesmo streaming
 * genérico do AttachmentStorage; valida tenant via `findForTeacher`.
 */

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    exit;
}

$tenantId = current_tenant_id();
if ($tenantId === null) {
    abort_subresource(403);
}

$activityId = (int) ($_REQUEST['id'] ?? 0);
$studentId  = (int) ($_REQUEST['student_id'] ?? 0);

// ADR-040: professor do curso (dono ou colaborador) baixa de qualquer aluno da turma.
$__courseId      = Activity::courseIdOf($activityId);
$studentTenantId = $__courseId !== null ? teacher_grading_student_tenant($studentId, $__courseId) : null;
$ctx = $studentTenantId !== null
    ? ActivitySubmission::findForTeacher($activityId, $studentId, $studentTenantId)
    : null;
if ($ctx === null) {
    abort_subresource(404);
}

// Sem `f` (ou f=0) = arquivo legado da entrega de arquivo único.
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
