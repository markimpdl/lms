<?php
declare(strict_types=1);

/**
 * GET /teacher/cu/{id}/attachment/{aid}/view — serve o arquivo do anexo
 * inline (img renderizado no editor, PDF renderizado no browser) para o
 * professor dono do tenant. E5-03 cria esta rota para o picker de imagens
 * do TinyMCE; E5-04 vai adicionar a rota análoga `/student/...` com
 * validação de matrícula.
 *
 * Path traversal: `stored_path` vem do próprio DB (gravado pelo service
 * em um formato fixo). Ainda assim, validamos que o `realpath` começa
 * dentro de `storage/uploads/` antes de servir.
 */

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    exit;
}

$tenantId = current_tenant_id();
if ($tenantId === null) {
    http_response_code(403);
    require LMS_ROOT . '/src/templates/errors/403.php';
    return;
}

$aid = (int) ($_REQUEST['aid'] ?? 0);
$att = ContentAttachment::findForTenant($aid, $tenantId);
if ($att === null) {
    http_response_code(404);
    require LMS_ROOT . '/src/templates/errors/404.php';
    return;
}

$fullPath = LMS_ROOT . '/' . $att['stored_path'];
$realBase = realpath(LMS_ROOT . '/storage/uploads');
$realFile = @realpath($fullPath);
if ($realBase === false || $realFile === false || !str_starts_with($realFile, $realBase)) {
    http_response_code(404);
    require LMS_ROOT . '/src/templates/errors/404.php';
    return;
}

header('Content-Type: ' . $att['mime']);
header('Content-Length: ' . filesize($realFile));
header('Content-Disposition: inline; filename="' . str_replace(['"', "\n", "\r"], '_', (string) $att['filename']) . '"');
header('X-Content-Type-Options: nosniff');
readfile($realFile);
