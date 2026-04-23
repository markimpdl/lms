<?php
declare(strict_types=1);

/**
 * POST /teacher/students/{id}/reset-password — professor força nova senha
 * de um aluno do próprio tenant (E4-05). Sempre POST + CSRF; GET/PUT/DELETE → 405.
 *
 * Id capturado pelo roteador em $_REQUEST['id']. Erros de campo
 * (ex.: senha curta) voltam como flash de erro + redirect para a tela
 * de detalhe, que é onde o modal mora.
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
    header('Location: /teacher/students');
    exit;
}

$studentId   = (int) ($_REQUEST['id'] ?? 0);
$newPassword = (string) ($_POST['new_password'] ?? '');
$sendByEmail = isset($_POST['send_email']);

$errors = TeacherStudentsController::resetPassword($studentId, $newPassword, $sendByEmail);

// Em caso de erro de validação o controller volta aqui (retorna array);
// em sucesso ele chama exit dentro do redirect.
if ($errors !== []) {
    foreach ($errors as $msg) {
        flash('danger', __t($msg));
    }
    header('Location: /teacher/students/' . $studentId);
    exit;
}
