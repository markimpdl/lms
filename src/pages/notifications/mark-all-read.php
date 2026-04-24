<?php
declare(strict_types=1);

/**
 * POST /notifications/mark-all-read — zera todas as não-lidas do usuário
 * logado e volta pra /notifications (ou pra página do referer se veio do
 * dropdown em outra tela).
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

Notification::markAllRead((int) $user['id']);

$referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');
$host    = (string) ($_SERVER['HTTP_HOST'] ?? '');
$target  = '/notifications';
if ($referer !== '' && $host !== '') {
    $parsed = parse_url($referer);
    if (($parsed['host'] ?? '') === $host && isset($parsed['path']) && str_starts_with((string) $parsed['path'], '/')) {
        $target = (string) $parsed['path'];
        if (isset($parsed['query']) && $parsed['query'] !== '') {
            $target .= '?' . $parsed['query'];
        }
    }
}

header('Location: ' . $target, true, 303);
