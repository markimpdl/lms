<?php
declare(strict_types=1);

/**
 * POST /settings/language — troca `users.language` do usuário logado
 * (E10-01). Atualiza sessão e redireciona pra página anterior.
 *
 * Body: lang=pt|en. Qualquer outro valor é rejeitado silenciosamente
 * (volta sem mudar nada).
 */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Location: /');
    return;
}

try {
    csrf_verify();
} catch (RuntimeException) {
    flash('danger', __t('auth.forbidden'));
    header('Location: /');
    return;
}

$user = current_user();
if ($user === null) {
    header('Location: /login');
    return;
}

$lang = (string) ($_POST['lang'] ?? '');
if (!in_array($lang, ['pt', 'en'], true)) {
    header('Location: /');
    return;
}

Database::pdo()
    ->prepare('UPDATE users SET language = ? WHERE id = ?')
    ->execute([$lang, (int) $user['id']]);

$_SESSION['lang']             = $lang;
$_SESSION['user']['language'] = $lang;

$referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');
$host    = (string) ($_SERVER['HTTP_HOST'] ?? '');
$target  = '/';
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
