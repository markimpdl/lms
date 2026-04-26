<?php
declare(strict_types=1);

/**
 * /teacher/evaluation/{id}/submission/{student_id}/report.pdf — download
 * do PDF gerado pelo `ReportService` após feedback completo (E26-04).
 *
 * Acesso: APENAS pro professor dono do tenant. Aluno nunca recebe link
 * pra este endpoint (e mesmo se descobrir o path, falha em
 * `findForGrading` que valida ownership via JOIN tenant).
 *
 * Path no banco é relativo a LMS_ROOT (ex.: `storage/reports/eval_1_student_2_attempt_1.pdf`).
 * Defesa contra path traversal: `realpath` confinado a `LMS_ROOT/storage/reports`.
 */

$tenantId = current_tenant_id();
if ($tenantId === null) {
    http_response_code(403);
    require LMS_ROOT . '/src/templates/errors/403.php';
    return;
}

$evaluationId = (int) ($_REQUEST['id']         ?? 0);
$studentId    = (int) ($_REQUEST['student_id'] ?? 0);

$ctx = EvaluationSubmission::findForGrading($evaluationId, $studentId, $tenantId);
if ($ctx === null) {
    http_response_code(404);
    require LMS_ROOT . '/src/templates/errors/404.php';
    return;
}

$current = $ctx['current'] ?? null;
$relPath = $current !== null ? (string) ($current['report_pdf_path'] ?? '') : '';
if ($relPath === '') {
    http_response_code(404);
    require LMS_ROOT . '/src/templates/errors/404.php';
    return;
}

// Defesa path traversal: confirma que o realpath cai dentro de
// LMS_ROOT/storage/reports/. Path armazenado vem do ReportService que
// usa naming determinístico, mas DB pode ter sido editado manualmente.
$absPath  = LMS_ROOT . '/' . $relPath;
$realPath = @realpath($absPath);
$realBase = @realpath(LMS_ROOT . '/storage/reports');
if ($realBase === false || $realPath === false || !str_starts_with($realPath, $realBase)) {
    http_response_code(404);
    require LMS_ROOT . '/src/templates/errors/404.php';
    return;
}

$filename = 'report-eval' . $evaluationId . '-student' . $studentId . '.pdf';
$size     = filesize($realPath);

header('Content-Type: application/pdf');
header('Content-Length: ' . (int) $size);
header('Content-Disposition: inline; filename="' . $filename . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=0, no-cache');

readfile($realPath);
exit;
