<?php
declare(strict_types=1);

/**
 * POST /teacher/preferences/shared-roster — alterna a preferência do professor
 * "ver todos os alunos" em curso compartilhado (F25/E34 — ADR-036).
 *
 * Preferência fixa por professor (sticky). Default 0 = só meus alunos. Só
 * afeta LEITURA agregada (roster/progresso/métricas/matriz); correção/notas
 * seguem por tenant. Redireciona de volta pro caminho de origem validado.
 */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
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
    header('Location: /teacher');
    exit;
}

$user    = current_user();
$showAll = isset($_POST['show_all']) ? 1 : 0;

Database::pdo()
    ->prepare('UPDATE users SET shared_course_show_all_students = ? WHERE id = ?')
    ->execute([$showAll, (int) $user['id']]);

// Redireciona de volta pro caminho de origem. Só aceita caminho interno
// começando com "/" (e não "//") pra evitar open redirect.
$return = (string) ($_POST['return'] ?? '/teacher');
if ($return === '' || $return[0] !== '/' || str_starts_with($return, '//')) {
    $return = '/teacher';
}

header('Location: ' . $return, true, 303);
