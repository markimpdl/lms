<?php
declare(strict_types=1);

// Se já está logado, não faz sentido mostrar /forgot.
$currentUser = current_user();
if ($currentUser !== null) {
    header('Location: ' . AuthController::dashboardFor($currentUser['role']));
    exit;
}

$emailInput = '';
$submitted  = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_verify();
    } catch (RuntimeException) {
        header('Location: /forgot');
        exit;
    }

    $emailInput = strtolower(trim((string) ($_POST['email'] ?? '')));
    $submitted  = true;

    if (filter_var($emailInput, FILTER_VALIDATE_EMAIL) !== false) {
        AuthController::requestPasswordReset($emailInput);
    }
    // Resposta sempre genérica — não revela se o email existe.
}

$page_title = __t('auth.forgot.title');

ob_start();
?>
<div class="row justify-content-center">
    <div class="col-12 col-sm-8 col-md-6 col-lg-5">
        <div class="card shadow-sm">
            <div class="card-body p-4">
                <h1 class="h4 mb-3 text-center"><?= e(__t('auth.forgot.title')) ?></h1>

                <?php if ($submitted): ?>
                    <div class="alert alert-success" role="alert">
                        <?= e(__t('auth.forgot.generic_response')) ?>
                    </div>
                    <div class="text-center">
                        <a href="/login" class="btn btn-outline-primary"><?= e(__t('auth.login')) ?></a>
                    </div>
                <?php else: ?>
                    <p class="text-muted small mb-3"><?= e(__t('auth.forgot.instruction')) ?></p>

                    <form method="POST" action="/forgot" novalidate>
                        <?= csrf_field() ?>
                        <div class="mb-3">
                            <label for="email" class="form-label"><?= e(__t('auth.email')) ?></label>
                            <input type="email" name="email" id="email"
                                   class="form-control form-control-lg"
                                   value="<?= e($emailInput) ?>"
                                   required autofocus autocomplete="email" inputmode="email" maxlength="191">
                        </div>
                        <button type="submit" class="btn btn-primary btn-lg w-100"><?= e(__t('auth.forgot.submit')) ?></button>
                    </form>

                    <div class="text-center mt-3">
                        <a href="/login" class="text-decoration-none small"><?= e(__t('common.back')) ?></a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php
$page_content = ob_get_clean();

require LMS_ROOT . '/src/templates/layout.php';
