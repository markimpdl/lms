<?php
declare(strict_types=1);

/**
 * POST /teacher/ranks/{id}/move-(up|down) — reordena patente (E9-01).
 * Padrão E3-02: swap + renormalize positions.
 */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    header('Location: /teacher/ranks');
    return;
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
    header('Location: /teacher/ranks');
    return;
}

$id        = (int)    ($_REQUEST['id']        ?? 0);
$direction = (string) ($_REQUEST['direction'] ?? 'up');

$ok = $direction === 'down'
    ? Rank::moveDown($id, $tenantId)
    : Rank::moveUp($id, $tenantId);

if (!$ok) {
    http_response_code(404);
    require LMS_ROOT . '/src/templates/errors/404.php';
    return;
}

header('Location: /teacher/ranks', true, 303);
