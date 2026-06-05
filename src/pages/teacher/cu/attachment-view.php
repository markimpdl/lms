<?php
declare(strict_types=1);

/**
 * GET /teacher/cu/{id}/attachment/{aid}/view — serve o arquivo do anexo
 * inline (img renderizado no editor, PDF aberto no browser) para o
 * professor dono do tenant. Criado em E5-03 para o picker de imagens do
 * TinyMCE. E5-04 consolida: a lógica de streaming mora em
 * `AttachmentStorage::stream`; este handler só autentica e delega.
 */

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
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

$aid = (int) ($_REQUEST['aid'] ?? 0);
$att = ContentAttachment::findForTenant($aid, $tenantId);
if ($att === null) {
    http_response_code(404);
    require LMS_ROOT . '/src/templates/errors/404.php';
    return;
}

AttachmentStorage::stream($att, 'inline');
