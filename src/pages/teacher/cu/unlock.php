<?php
declare(strict_types=1);

/**
 * POST /teacher/cu/{id}/unlock — libera ou re-tranca uma CU pra um aluno
 * especifico (E36-02).
 *
 * Furar a trava sequencial eh dado de ALUNO, nao de conteudo: mesmo em curso
 * compartilhado (ADR-033/ADR-036) o professor so age sobre alunos do proprio
 * tenant. Por isso a validacao tem duas pernas:
 *   1. autoria da CU  -> effective_authoring_tenant (dono ou colaborador)
 *   2. posse do aluno -> matricula no curso E users.tenant_id = meu tenant
 */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}

$cuId             = (int) ($_REQUEST['id'] ?? 0);
$resolvedCourseId = CompetenceUnit::courseIdOf($cuId);
$tenantId         = $resolvedCourseId !== null ? effective_authoring_tenant($resolvedCourseId) : null;
if ($tenantId === null) {
    http_response_code(404);
    require LMS_ROOT . '/src/templates/errors/404.php';
    return;
}

try {
    csrf_verify();
} catch (RuntimeException) {
    flash('danger', __t('auth.forbidden'));
    header('Location: /teacher/cu/' . $cuId, true, 303);
    exit;
}

$cu = CompetenceUnit::findForTenant($cuId, $tenantId);
if ($cu === null) {
    http_response_code(404);
    require LMS_ROOT . '/src/templates/errors/404.php';
    return;
}

$courseId = (int) $cu['course_id'];
$backUrl  = '/teacher/cu/' . $cuId;

// Curso arquivado eh somente-leitura, igual ao resto da autoria.
if ((int) $cu['course_archived'] === 1) {
    flash('warning', __t('cu_unlock.err.archived'));
    header('Location: ' . $backUrl, true, 303);
    exit;
}

// Em cc_mode='free' nao existe trava sequencial pra furar — o desbloqueio
// seria um registro sem efeito nenhum. Recusa explicitamente em vez de gravar
// linha morta no banco.
if ((string) ($cu['course_cc_mode'] ?? 'sequential') !== 'sequential') {
    flash('warning', __t('cu_unlock.err.free_mode'));
    header('Location: ' . $backUrl, true, 303);
    exit;
}

$studentId = (int) ($_POST['student_user_id'] ?? 0);
$action    = (string) ($_POST['action'] ?? '');

if ($studentId <= 0 || ($action !== 'unlock' && $action !== 'lock')) {
    flash('danger', __t('cu_unlock.err.invalid'));
    header('Location: ' . $backUrl, true, 303);
    exit;
}

// Aluno tem de ser MEU e estar matriculado NESTE curso. `current_tenant_id()`
// (nao `$tenantId`) de proposito: num curso compartilhado o tenant de autoria
// pode ser o do dono, mas o aluno tem de ser do professor logado.
$myTenantId = current_tenant_id();
$stmt = Database::pdo()->prepare(
    'SELECT 1
       FROM enrollments e
       JOIN users u ON u.id = e.student_user_id
      WHERE e.student_user_id = ?
        AND e.course_id       = ?
        AND u.tenant_id       = ?
        AND u.role            = \'student\'
      LIMIT 1'
);
$stmt->execute([$studentId, $courseId, $myTenantId]);
if ($stmt->fetchColumn() === false) {
    flash('danger', __t('cu_unlock.err.not_my_student'));
    header('Location: ' . $backUrl, true, 303);
    exit;
}

if ($action === 'unlock') {
    StudentCuUnlock::grant($cuId, $studentId, (int) (current_user()['id'] ?? 0));
    flash('success', __t('cu_unlock.flash.unlocked'));
} else {
    StudentCuUnlock::revoke($cuId, $studentId);
    flash('success', __t('cu_unlock.flash.locked'));
}

header('Location: ' . $backUrl, true, 303);
exit;
