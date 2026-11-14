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
if ($submission === null || (int) $submission['evaluation_id'] !== $evaluationId) {
    abort_subresource(404);
}

// Sem `f` (ou f=0) = arquivo legado da entrega de arquivo único.
$file = SubmissionFiles::findIn(
    SubmissionFiles::KIND_EVALUATION,
    $submission,
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
