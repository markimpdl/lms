<?php
declare(strict_types=1);

/**
 * POST /teacher/ranks/{id}/delete — remove patente (E9-01). Sem cascade;
 * aluno com XP na faixa removida vira "Sem patente" no ProfileSidebar.
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

$id = (int) ($_REQUEST['id'] ?? 0);
$result = Rank::delete($id, $tenantId);

if ($result === 'not_found') {
    http_response_code(404);
    require LMS_ROOT . '/src/templates/errors/404.php';
    return;
}

flash('success', __t('ranks.deleted'));
header('Location: /teacher/ranks', true, 303);
