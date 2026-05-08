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

$enabled = (int) ($cu['manual_completion_enabled'] ?? 0) === 1;
$hasEval = $tenantId > 0 && Evaluation::findByCu($cuId, $tenantId) !== null;
if (!$enabled || $hasEval) {
    http_response_code(403);
    require LMS_ROOT . '/src/templates/errors/403.php';
    return;
}

// Gate: todas as atividades da CU precisam ter submissao do aluno.
$pendingStmt = Database::pdo()->prepare(
    'SELECT COUNT(*)
       FROM activities a
       LEFT JOIN activity_submissions s
              ON s.activity_id = a.id AND s.student_user_id = ?
      WHERE a.competence_unit_id = ?
        AND s.id IS NULL'
);
$pendingStmt->execute([$studentId, $cuId]);
if ((int) $pendingStmt->fetchColumn() > 0) {
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
