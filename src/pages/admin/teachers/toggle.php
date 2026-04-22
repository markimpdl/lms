<?php
declare(strict_types=1);

/**
 * POST /admin/teachers/{id}/toggle — alterna active do professor/tenant
 * (E2-04). Sempre POST + CSRF; GET e outros métodos caem em 405.
 *
 * O $_REQUEST['id'] é extraído pelo roteador (role_patterns em E2-03).
 * O `from` preserva a tela de origem para o redirect PRG ('list'|'edit').
 */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}

try {
    csrf_verify();
} catch (RuntimeException) {
    flash('danger', __t('auth.forbidden'));
    header('Location: /admin/teachers');
    exit;
}

$teacherId = (int) ($_REQUEST['id'] ?? 0);
$from      = (string) ($_POST['from'] ?? 'list');
if ($from !== 'edit') {
    $from = 'list';
}

AdminTeachersController::toggleActive($teacherId, $from);
