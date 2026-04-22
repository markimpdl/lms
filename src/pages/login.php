<?php
declare(strict_types=1);

// Já logado? Redireciona para o dashboard do papel.
$user = current_user();
if ($user !== null) {
    header('Location: ' . AuthController::dashboardFor($user['role']));
    exit;
}

$error = null;
$email = '';
$ip    = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_verify();
    } catch (RuntimeException) {
        flash('danger', __t('auth.invalid'));
        header('Location: /login');
        exit;
    }

    $email    = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');

    if (AuthController::isIpBlocked($ip)) {
        $error = __t('auth.rate_limited');
    } else {
        $valid = filter_var($email, FILTER_VALIDATE_EMAIL) !== false
              && strlen($password) >= 8;

        $userRow = $valid ? AuthController::authenticate($email, $password) : null;

        if ($userRow === null) {
            AuthController::recordAttempt($email, $ip, false);
            $error = __t('auth.invalid');
        } else {
            AuthController::recordAttempt($email, $ip, true);
            AuthController::completeLogin($userRow);
            $next   = AuthController::safeNext($_GET['next'] ?? null);
            $target = $next ?? AuthController::dashboardFor((string) $userRow['role']);
            header('Location: ' . $target);
            exit;
        }
    }
}

$page_title = __t('auth.login');

ob_start();
?>
<div class="row justify-content-center">
    <div class="col-12 col-sm-8 col-md-6 col-lg-5">
        <div class="card shadow-sm">
            <div class="card-body p-4">
                <h1 class="h4 mb-4 text-center"><?= e(__t('auth.login')) ?></h1>

                <?php if ($error !== null): ?>
                    <div class="alert alert-danger" role="alert"><?= e($error) ?></div>
                <?php endif; ?>

                <form method="POST" action="/login" novalidate autocomplete="on">
                    <?= csrf_field() ?>

                    <div class="mb-3">
                        <label for="email" class="form-label"><?= e(__t('auth.email')) ?></label>
                        <input type="email" name="email" id="email"
                               class="form-control form-control-lg"
                               value="<?= e($email) ?>"
                               required autofocus autocomplete="email" inputmode="email" maxlength="191">
                    </div>

                    <div class="mb-3">
                        <label for="password" class="form-label"><?= e(__t('auth.password')) ?></label>
                        <input type="password" name="password" id="password"
                               class="form-control form-control-lg"
                               required autocomplete="current-password" minlength="8">
                    </div>

                    <button type="submit" class="btn btn-primary btn-lg w-100"><?= e(__t('auth.login')) ?></button>
                </form>

                <div class="text-center mt-3">
                    <a href="/forgot" class="text-decoration-none small"><?= e(__t('auth.forgot')) ?></a>
                </div>
            </div>
        </div>
    </div>
</div>
<?php
$page_content = ob_get_clean();

require LMS_ROOT . '/src/templates/layout.php';
