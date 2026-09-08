<?php
declare(strict_types=1);

/**
 * POST /student/cu/{id}/mark-complete — aluno fecha CU clicando no botao
 * (v0.31.0). Gates: matriculado + curso disponivel + manual_completion
 * habilitado pelo professor + sem evaluation + todas as atividades
 * entregues. Idempotente via PK em cu_manual_completions.
 */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}

$user = current_user();
if ($user === null || ($user['role'] ?? '') !== 'student') {
    http_response_code(403);
    require LMS_ROOT . '/src/templates/errors/403.php';
    return;
}

try {
    csrf_verify();
} catch (RuntimeException) {
    flash('danger', __t('auth.forbidden'));
    header('Location: /student');
    exit;
}

$studentId = (int) $user['id'];
$tenantId  = (int) ($user['tenant_id'] ?? 0);
$cuId      = (int) ($_REQUEST['id'] ?? 0);

$cu = CompetenceUnit::findForStudent($cuId, $studentId);
if ($cu === null) {
    http_response_code(404);
    require LMS_ROOT . '/src/templates/errors/404.php';
    return;
}

$courseId = (int) $cu['course_id'];

$availability = enrollment_access_status($studentId, $courseId);
if (!$availability['available']) {
    flash('warning', $availability['message'] ?? __t('enrollment.unavailable.generic'));
    header('Location: /student', true, 303);
    exit;
}

// Unidade em rascunho (V1): a capa nao mostra o botao, e aqui fecha o POST
// forjado — nao ha por que creditar XP de conclusao de unidade nao publicada.
if (cu_is_draft_for_student($cuId)) {
    flash('warning', __t('progression.cu_draft'));
    header('Location: /student/course/' . $courseId, true, 303);
    exit;
}

$enabled = (int) ($cu['manual_completion_enabled'] ?? 0) === 1;
$hasEval = $tenantId > 0 && Evaluation::findByCu($cuId, $tenantId) !== null;
if (!$enabled || $hasEval) {
    http_response_code(403);
    require LMS_ROOT . '/src/templates/errors/403.php';
    return;
}

// Gate: o percurso da unidade precisa estar fechado.
//
// Em V2 o criterio eh a TRILHA inteira concluida, nao so as atividades: a
// trilha inclui licao, e "todas as atividades entregues" deixaria o aluno
// fechar a unidade com licoes por ler. Alem de errado, divergiria do botao,
// que ja usa a trilha como pre-requisito.
$isV2 = (int) ($cu['course_structure_version'] ?? 1) === 2;

if ($isV2) {
    // Trilha vazia nao pendura ninguem: `isComplete([])` eh false de proposito
    // (nao ha o que concluir), e usar isso como pendencia travaria pra sempre a
    // unidade que so tem capa + conclusao manual. Espelha o botao.
    $track   = UnitTrackService::forStudentCu($cuId, $studentId);
    $pending = $track !== [] && !UnitTrackService::isComplete($track);
} else {
    $pendingStmt = Database::pdo()->prepare(
        'SELECT COUNT(*)
           FROM activities a
           LEFT JOIN activity_submissions s
                  ON s.activity_id = a.id AND s.student_user_id = ?
          WHERE a.competence_unit_id = ?
            AND s.id IS NULL'
    );
    $pendingStmt->execute([$studentId, $cuId]);
    $pending = (int) $pendingStmt->fetchColumn() > 0;
}

if ($pending) {
    flash('warning', __t('manual_completion.err.activities_pending'));
    header('Location: /student/cu/' . $cuId, true, 303);
    exit;
}

Database::pdo()->prepare(
    'INSERT IGNORE INTO cu_manual_completions (cu_id, student_user_id) VALUES (?, ?)'
)->execute([$cuId, $studentId]);

XpEvents::awardCuManual($studentId, $cuId);

flash('success', __t('manual_completion.completed'));
header('Location: /student/cu/' . $cuId, true, 303);
