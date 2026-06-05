<?php
declare(strict_types=1);

/**
 * POST /teacher/activity/{id}/delete — exclusão permanente com
 * confirmação por digitação do título (E6-05, padrão E3-05).
 *
 * Ordem: valida nome via Activity::delete (transação interna) →
 * apaga arquivos físicos das submissions → rmdir do diretório da
 * atividade. Arquivo ausente NÃO é erro (registro foi a fonte de verdade).
 */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}

// E32 (ADR-033): conteúdo via tenant do dono (dono ou colaborador).
$__activityId = (int) ($_REQUEST['id'] ?? 0);
$__courseId   = Activity::courseIdOf($__activityId);
$tenantId     = $__courseId !== null ? effective_authoring_tenant($__courseId) : null;
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

$activityId   = (int)    ($_REQUEST['id']          ?? 0);
$expectedName = (string) ($_POST['expected_name']  ?? '');

$activity = Activity::findForTenant($activityId, $tenantId);
if ($activity === null) {
    http_response_code(404);
    require LMS_ROOT . '/src/templates/errors/404.php';
    return;
}

$cuId = (int) $activity['competence_unit_id'];

$result = Activity::delete($activityId, $tenantId, $expectedName);

if ($result['status'] === 'name_mismatch') {
    flash('danger', __t('delete.err.name_mismatch'));
    header('Location: /teacher/activity/' . $activityId . '/edit', true, 303);
    return;
}
if ($result['status'] === 'not_found') {
    http_response_code(404);
    require LMS_ROOT . '/src/templates/errors/404.php';
    return;
}

// Apaga arquivos físicos (submissions + brief PDF v0.30.0) — seguro após
// commit do DB. Helper safe_unlink_storage tem defesa traversal.
foreach ($result['stored_paths'] ?? [] as $rel) {
    safe_unlink_storage((string) $rel);
}

// Tenta rmdir dos diretórios da atividade (submissions + brief). Silencioso.
$submissionsDir = LMS_ROOT . '/storage/uploads/tenant_' . $tenantId . '/submissions/' . $activityId;
if (is_dir($submissionsDir)) {
    @rmdir($submissionsDir);
}
$briefDir = LMS_ROOT . '/storage/uploads/tenant_' . $tenantId . '/activities/' . $activityId;
if (is_dir($briefDir)) {
    @rmdir($briefDir);
}

flash('success', __t('activities.deleted', ['name' => (string) $activity['title']]));
header('Location: /teacher/cu/' . $cuId, true, 303);
