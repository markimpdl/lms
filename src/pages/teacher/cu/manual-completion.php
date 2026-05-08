<?php
declare(strict_types=1);

/**
 * POST /teacher/cu/{id}/manual-completion — habilita/desabilita o botao
 * "Mark as completed" + define XP (v0.31.0). Mutuamente exclusivo com
 * evaluation: recusa quando ja existe avaliacao cadastrada na CU.
 */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}

$tenantId = current_tenant_id();
if ($tenantId === null) {
    http_response_code(403);
    require LMS_ROOT . '/src/templates/errors/403.php';
    return;
}

try {
    csrf_verify();
} catch (RuntimeException) {
    flash('danger', __t('auth.forbidden'));
    header('Location: /teacher');
    exit;
}

$cuId = (int) ($_REQUEST['id'] ?? 0);
$cu   = CompetenceUnit::findForTenant($cuId, $tenantId);
if ($cu === null) {
    http_response_code(404);
    require LMS_ROOT . '/src/templates/errors/404.php';
    return;
}

if ((int) $cu['course_archived'] === 1) {
    flash('danger', __t('courses.edit.archived_notice'));
    header('Location: /teacher/cu/' . $cuId, true, 303);
    exit;
}

$enabled = isset($_POST['enabled']) && $_POST['enabled'] === '1';
$xp      = (int) ($_POST['xp'] ?? 0);
if ($xp < 0)    { $xp = 0; }
if ($xp > 9999) { $xp = 9999; }

if ($enabled) {
    $hasEval = Evaluation::findByCu($cuId, $tenantId) !== null;
    if ($hasEval) {
        flash('danger', __t('manual_completion.err.has_evaluation'));
        header('Location: /teacher/cu/' . $cuId, true, 303);
        exit;
    }
}

CompetenceUnit::setManualCompletion($cuId, $enabled, $xp);
flash('success', __t($enabled ? 'manual_completion.enabled' : 'manual_completion.disabled'));
header('Location: /teacher/cu/' . $cuId, true, 303);
