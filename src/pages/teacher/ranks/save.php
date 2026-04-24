<?php
declare(strict_types=1);

/**
 * POST /teacher/ranks/save — cria ou atualiza patente (E9-01).
 *
 * Distingue via campo oculto `id`: vazio = create; preenchido = update.
 * Validações server-side:
 *  - name: 2..80 chars
 *  - xp_min: int >= 0
 *  - xp_max: vazio (topo sem teto) ou int > xp_min
 *  - color_hex: formato /^#[0-9A-Fa-f]{6}$/
 *  - não sobrepor outras patentes do tenant (checado no model)
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

$rawId    = trim((string) ($_POST['id']        ?? ''));
$name     = trim((string) ($_POST['name']      ?? ''));
$xpMinRaw = trim((string) ($_POST['xp_min']    ?? ''));
$xpMaxRaw = trim((string) ($_POST['xp_max']    ?? ''));
$color    = trim((string) ($_POST['color_hex'] ?? '#6366F1'));

if (mb_strlen($name) < 2 || mb_strlen($name) > 80) {
    flash('danger', __t('ranks.err.name'));
    header('Location: /teacher/ranks');
    return;
}
if (!ctype_digit($xpMinRaw)) {
    flash('danger', __t('ranks.err.xp_min'));
    header('Location: /teacher/ranks');
    return;
}
$xpMin = (int) $xpMinRaw;
$xpMax = null;
if ($xpMaxRaw !== '') {
    if (!ctype_digit($xpMaxRaw)) {
        flash('danger', __t('ranks.err.xp_max'));
        header('Location: /teacher/ranks');
        return;
    }
    $xpMax = (int) $xpMaxRaw;
    if ($xpMax <= $xpMin) {
        flash('danger', __t('ranks.err.xp_max_less_than_min'));
        header('Location: /teacher/ranks');
        return;
    }
}
if (preg_match('/^#[0-9A-Fa-f]{6}$/', $color) !== 1) {
    $color = '#6366F1';
}

$data = [
    'name'      => $name,
    'xp_min'    => $xpMin,
    'xp_max'    => $xpMax,
    'color_hex' => $color,
];

if ($rawId === '') {
    $result = Rank::create($tenantId, $data);
    if ($result === 'duplicate') {
        flash('danger', __t('ranks.err.duplicate', ['name' => $name]));
    } elseif ($result === 'overlap') {
        flash('danger', __t('ranks.err.overlap'));
    } else {
        flash('success', __t('ranks.created', ['name' => $name]));
    }
} else {
    $id = (int) $rawId;
    $result = Rank::update($id, $tenantId, $data);
    if ($result === 'not_found') {
        http_response_code(404);
        require LMS_ROOT . '/src/templates/errors/404.php';
        return;
    }
    if ($result === 'duplicate') {
        flash('danger', __t('ranks.err.duplicate', ['name' => $name]));
    } elseif ($result === 'overlap') {
        flash('danger', __t('ranks.err.overlap'));
    } else {
        flash('success', __t('ranks.updated', ['name' => $name]));
    }
}

header('Location: /teacher/ranks', true, 303);
