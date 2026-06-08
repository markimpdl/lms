<?php
declare(strict_types=1);

/**
 * POST /teacher/evaluation/{id}/delete — exclusão permanente (E7-01).
 *
 * Ordem: Evaluation::delete valida título e apaga xp_events + evaluations
 * (cascade nas submissions) em transação → handler apaga PDF do enunciado
 * e arquivos das submissões do disco → rmdir do diretório da avaliação.
 */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}

// E32 (ADR-033): conteúdo via tenant do dono (dono ou colaborador).
$__evaluationId = (int) ($_REQUEST['id'] ?? 0);
$__courseId     = Evaluation::courseIdOf($__evaluationId);
$tenantId       = $__courseId !== null ? effective_authoring_tenant($__courseId) : null;
if ($tenantId === null) {
    http_response_code(404);
    require LMS_ROOT . '/src/templates/errors/404.php';
    return;
}

try {
    csrf_verify();
} catch (RuntimeException) {
    flash('danger', __t('auth.forbidden'));
    header('Location: /teacher');
    exit;
}

$evaluationId = (int)    ($_REQUEST['id']         ?? 0);
$expectedName = (string) ($_POST['expected_name'] ?? '');

$evaluation = Evaluation::findForTenant($evaluationId, $tenantId);
if ($evaluation === null) {
    http_response_code(404);
    require LMS_ROOT . '/src/templates/errors/404.php';
    return;
}

$cuId = (int) $evaluation['competence_unit_id'];

$result = Evaluation::delete($evaluationId, $tenantId, $expectedName);

if ($result['status'] === 'name_mismatch') {
    flash('danger', __t('delete.err.name_mismatch'));
    header('Location: /teacher/evaluation/' . $evaluationId . '/edit', true, 303);
    return;
}
if ($result['status'] === 'not_found') {
    http_response_code(404);
    require LMS_ROOT . '/src/templates/errors/404.php';
    return;
}

$realBase = realpath(LMS_ROOT . '/storage/uploads');

// Arquivos das submissions dos alunos (podem coexistir em diferentes dirs
// conforme o storage usado em E7-02, mas nesta story a tabela está vazia).
foreach ($result['stored_paths'] ?? [] as $rel) {
    $full = LMS_ROOT . '/' . $rel;
    $real = @realpath($full);
    if ($realBase !== false && $real !== false && str_starts_with($real, $realBase)) {
        @unlink($real);
    }
}

// PDF do enunciado + diretório da avaliação.
EvaluationBriefStorage::delete($evaluationId, $tenantId);

// E33 (F24/ADR-035): auditoria — label (título) capturado antes do delete.
course_audit((int) $__courseId, 'delete', 'evaluation', $evaluationId, (string) $evaluation['title']);

flash('success', __t('evaluations.deleted', ['name' => (string) $evaluation['title']]));
header('Location: /teacher/cu/' . $cuId, true, 303);
