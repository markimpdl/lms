<?php
declare(strict_types=1);

/**
 * POST /teacher/cu/{id}/attachment — upload de anexo para o conteúdo da CU
 * (E5-03). Sempre POST + CSRF; GET → 405.
 *
 * O service valida mime, tamanho e cria o `contents` vazio se ainda não
 * existir. Em sucesso, redirect pro editor com flash. Em erro, mesmo lugar
 * com flash de erro.
 */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}

// E32 (ADR-033): conteúdo via tenant do dono (dono ou colaborador).
$__cuId     = (int) ($_REQUEST['id'] ?? 0);
$__courseId = CompetenceUnit::courseIdOf($__cuId);
$tenantId   = $__courseId !== null ? effective_authoring_tenant($__courseId) : null;
if ($tenantId === null) {
    http_response_code(404);
    require LMS_ROOT . '/src/templates/errors/404.php';
    return;
}

try {
    csrf_verify();
} catch (RuntimeException) {
    flash('danger', __t('auth.forbidden'));
    header('Location: /teacher/cu/' . (int) ($_REQUEST['id'] ?? 0) . '/content/edit');
    exit;
}

$cuId = (int) ($_REQUEST['id'] ?? 0);
$file = $_FILES['attachment'] ?? null;
if (!is_array($file)) {
    flash('danger', __t('attachments.err.no_file'));
    header('Location: /teacher/cu/' . $cuId . '/content/edit');
    exit;
}

$result = AttachmentStorage::store($file, $tenantId, $cuId);

if ($result['status'] === 'ok') {
    flash('success', __t('attachments.uploaded'));
} else {
    flash('danger', __t($result['error_key'] ?? 'attachments.err.generic'));
}

header('Location: /teacher/cu/' . $cuId . '/content/edit', true, 303);
