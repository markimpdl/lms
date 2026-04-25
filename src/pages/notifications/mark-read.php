<?php
declare(strict_types=1);

/**
 * POST /notifications/mark-read — marca 1 notificação como lida e
 * redireciona pro link associado (E10-01).
 *
 * Body: id=N. Valida ownership em `Notification::markRead` (user_id do
 * usuário logado). Não existe? Redireciona pra /notifications.
 */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Location: /notifications');
    return;
}

try {
    csrf_verify();
} catch (RuntimeException) {
    flash('danger', __t('auth.forbidden'));
    header('Location: /notifications');
    return;
}

$user = current_user();
if ($user === null) {
    header('Location: /login');
    return;
}

$uid = (int) $user['id'];
$id  = (int) ($_POST['id'] ?? 0);

if ($id <= 0) {
    header('Location: /notifications');
    return;
}

$notification = Notification::findByIdForUser($id, $uid);
if ($notification === null) {
    header('Location: /notifications');
    return;
}

if (Notification::markRead($id, $uid)) {
    // Conquistas (E18-04): primeira notificação lida desbloqueia.
    $tenantId = $user['tenant_id'] !== null ? (int) $user['tenant_id'] : 0;
    if ($tenantId > 0) {
        try {
            AchievementsService::evaluateForEvent($uid, $tenantId, 'notification_read');
        } catch (\Throwable) {
            // swallow
        }
    }
}

// Aceita só path interno (começa com `/` mas NÃO `//`, que seria
// protocol-relative e redirecionaria pra domínio externo).
$link   = (string) ($notification['link'] ?? '');
$target = '/notifications';
if ($link !== '' && str_starts_with($link, '/') && !str_starts_with($link, '//')) {
    $target = $link;
}

header('Location: ' . $target, true, 303);
