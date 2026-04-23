<?php
declare(strict_types=1);

/**
 * GET /teacher/cu/{id}/attachment/{aid} — download do anexo com
 * `Content-Disposition: attachment` para o professor dono do tenant.
 * Contrapartida do `/view` (inline); esta rota força o save-as no browser.
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

AttachmentStorage::stream($att, 'attachment');
