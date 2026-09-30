<?php
declare(strict_types=1);

/**
 * GET /teacher/evaluation/{id}/submission/{sid}/file — professor baixa o
 * arquivo de uma submissão (E7-03). Autoriza via teacher_grading_student_tenant.
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

$evaluationId = (int) ($_REQUEST['id']  ?? 0);
$submissionId = (int) ($_REQUEST['sid'] ?? 0);

// ADR-040: qualquer professor do curso baixa de qualquer aluno da turma. A
// submissão tem que ser da avaliação da URL (é por ela que o curso é resolvido).
$__courseId      = Evaluation::courseIdOf($evaluationId);
$__studentId     = EvaluationSubmission::studentIdOf($submissionId);
$studentTenantId = ($__courseId !== null && $__studentId !== null)
    ? teacher_grading_student_tenant($__studentId, $__courseId)
    : null;
$submission = $studentTenantId !== null
    ? EvaluationSubmission::findForTeacher($submissionId, $studentTenantId)
    : null;
if ($submission === null || (int) $submission['evaluation_id'] !== $evaluationId
    || $submission['filename'] === null) {
    abort_subresource(404);
}

$storedPath = (string) $submission['stored_path'];
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
    'filename'    => (string) $submission['filename'],
    'stored_path' => $storedPath,
    'mime'        => $mime,
], 'attachment');
