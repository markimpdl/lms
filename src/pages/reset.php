<?php
declare(strict_types=1);

$currentUser = current_user();
if ($currentUser !== null) {
    header('Location: ' . AuthController::dashboardFor($currentUser['role']));
    exit;
}

$token = (string) ($_REQUEST['token'] ?? '');
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_verify();
    } catch (RuntimeException) {
        flash('danger', __t('auth.reset.invalid_link'));
        header('Location: /login');
        exit;
    }

    $password = (string) ($_POST['password'] ?? '');
    $confirm  = (string) ($_POST['password_confirm'] ?? '');

    if (strlen($password) < 8) {
        $error = __t('auth.reset.min_length');
    } elseif ($password !== $confirm) {
        $error = __t('auth.reset.mismatch');
    } elseif (!AuthController::consumePasswordResetToken($token, $password)) {
        // Token inválido/expirado ou já usado → para o login com mensagem.
        flash('danger', __t('auth.reset.invalid_link'));
        header('Location: /login');
        exit;
    } else {
        flash('success', __t('auth.reset.success'));
        header('Location: /login');
        exit;
    }
}

// GET: valida o token antes de renderizar o form.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && AuthController::validateResetToken($token) === null) {
    flash('danger', __t('auth.reset.invalid_link'));
    header('Location: /login');
    exit;
}

$page_title = __t('auth.reset.title');

ob_start();
?>
<div class="row justify-content-center">
    <div class="col-12 col-sm-8 col-md-6 col-lg-5">
        <div class="card shadow-sm">
            <div class="card-body p-4">
                <h1 class="h4 mb-3 text-center"><?= e(__t('auth.reset.title')) ?></h1>

                <?php if ($error !== null): ?>
                    <div class="alert alert-danger" role="alert"><?= e($error) ?></div>
                <?php endif; ?>

                <form method="POST" action="/reset" novalidate autocomplete="on">
                    <?= csrf_field() ?>
                    <input type="hidden" name="token" value="<?= e($token) ?>">

                    <div class="mb-3">
                        <label for="password" class="form-label"><?= e(__t('auth.reset.new_password')) ?></label>
                        <input type="password" name="password" id="password"
                               class="form-control form-control-lg"
                               required autocomplete="new-password" minlength="8" autofocus>
                    </div>

                    <div class="mb-3">
                        <label for="password_confirm" class="form-label"><?= e(__t('auth.reset.confirm')) ?></label>
                        <input type="password" name="password_confirm" id="password_confirm"
                               class="form-control form-control-lg"
                               required autocomplete="new-password" minlength="8">
                    </div>

                    <button type="submit" class="btn btn-primary btn-lg w-100"><?= e(__t('auth.reset.submit')) ?></button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php
$page_content = ob_get_clean();

require LMS_ROOT . '/src/templates/layout.php';
