<?php
declare(strict_types=1);

/**
 * GET /teacher/activity/{id}/submission/{student_id}/file — professor
 * baixa o arquivo da submissão de um aluno (E6-04). Usa o mesmo streaming
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
if ($ctx === null || $ctx['submission']['filename'] === null) {
    abort_subresource(404);
}

$storedPath = (string) $ctx['submission']['stored_path'];
$ext        = strtolower(pathinfo($storedPath, PATHINFO_EXTENSION));
$mime       = match ($ext) {
    'pdf'   => 'application/pdf',
    'zip'   => 'application/zip',
    'txt'   => 'text/plain',
    'jpg'   => 'image/jpeg',
    'png'   => 'image/png',
    default => 'application/octet-stream',
};

AttachmentStorage::stream([
    'filename'    => (string) $ctx['submission']['filename'],
    'stored_path' => $storedPath,
    'mime'        => $mime,
], 'attachment');
