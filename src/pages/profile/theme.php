<?php
declare(strict_types=1);

/**
 * POST /profile/theme — troca `users.theme` do aluno logado (E27-01 — F18).
 *
 * Body: theme=light|dark. Apenas aluno (defesa: 403 pra teacher/super-admin).
 * Atualiza sessão e redireciona pra página anterior (referer same-host) ou /profile.
 *
 * Padrão de implementação consistente com `/settings/language` (E10-01).
 */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Location: /profile');
    return;
}

try {
    csrf_verify();
} catch (RuntimeException) {
    flash('danger', __t('auth.forbidden'));
    header('Location: /profile');
    return;
}

$user = current_user();
if ($user === null) {
    header('Location: /login');
    return;
}

// Restrição MVP: dark mode é setting do aluno apenas; teacher/super-admin
// ficam light. Defesa server-side (UI tampouco mostra o toggle pra eles).
if (($user['role'] ?? '') !== 'student') {
    http_response_code(403);
    require LMS_ROOT . '/src/templates/errors/403.php';
    return;
}

$theme = (string) ($_POST['theme'] ?? '');
if (!in_array($theme, ['light', 'dark'], true)) {
    header('Location: /profile');
    return;
}

Database::pdo()
    ->prepare('UPDATE users SET theme = ? WHERE id = ?')
    ->execute([$theme, (int) $user['id']]);

$_SESSION['user']['theme'] = $theme;

flash('success', __t('profile.theme.saved'));

// Redirect pra referer same-host ou /profile como fallback.
$referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');
$host    = (string) ($_SERVER['HTTP_HOST'] ?? '');
$target  = '/profile';
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
