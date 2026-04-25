<?php
declare(strict_types=1);

// Já logado? Redireciona para o dashboard do papel.
$user = current_user();
if ($user !== null) {
    header('Location: ' . AuthController::dashboardFor($user['role']));
    exit;
}

// E22-01: cancelar o seletor de tenant via /login?cancel=picker.
// Limpa estado pendente e cai no form de credenciais normal.
if (($_GET['cancel'] ?? '') === 'picker') {
    unset($_SESSION['pending_login_candidates']);
    header('Location: /login', true, 303);
    exit;
}

$error = null;
$email = '';
$ip    = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');

// E22-01: TTL do seletor de tenant. Se sessão pending expirar entre
// digitar credencial e escolher o tenant, força refazer o login.
$pickerTtlSeconds = 300; // 5 min

// Detecta fase do POST: presença de selected_user_id = fase 2 (seletor).
$isPickerSubmit = $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['selected_user_id']);

// Estado pendente do seletor (se houver) — controla render da página.
$pendingPicker = $_SESSION['pending_login_candidates'] ?? null;
if (is_array($pendingPicker)
    && (int) ($pendingPicker['expires_at'] ?? 0) < time()
) {
    unset($_SESSION['pending_login_candidates']);
    $pendingPicker = null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$isPickerSubmit) {
    // ============================================================
    // FASE 1: credenciais
    // ============================================================
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

        $candidates = $valid ? AuthController::authenticateAll($email, $password) : [];

        if ($candidates === []) {
            AuthController::recordAttempt($email, $ip, false);
            $error = __t('auth.invalid');
        } elseif (count($candidates) === 1) {
            // Caso comum: 1 conta válida → login direto.
            AuthController::recordAttempt($email, $ip, true);
            AuthController::completeLogin($candidates[0]);
            $next   = AuthController::safeNext($_GET['next'] ?? null);
            $target = $next ?? AuthController::dashboardFor((string) $candidates[0]['role']);
            header('Location: ' . $target);
            exit;
        } else {
            // 2+ contas → registra attempt como sucesso (credencial bateu) +
            // guarda candidatos na sessão pra o seletor.
            AuthController::recordAttempt($email, $ip, true);
            $_SESSION['pending_login_candidates'] = [
                'user_ids'   => array_map(static fn ($c): int => (int) $c['id'], $candidates),
                'expires_at' => time() + $pickerTtlSeconds,
                'next'       => AuthController::safeNext($_GET['next'] ?? null),
            ];
            $pendingPicker = $_SESSION['pending_login_candidates'];
        }
    }
} elseif ($isPickerSubmit) {
    // ============================================================
    // FASE 2: seletor de tenant
    // ============================================================
    try {
        csrf_verify();
    } catch (RuntimeException) {
        unset($_SESSION['pending_login_candidates']);
        flash('danger', __t('auth.invalid'));
        header('Location: /login');
        exit;
    }

    if (!is_array($pendingPicker)) {
        // Sessão expirou ou foi limpa — força refazer login.
        flash('warning', __t('auth.login.tenant_picker.expired'));
        header('Location: /login', true, 303);
        exit;
    }

    $selectedId = (int) ($_POST['selected_user_id'] ?? 0);
    $allowedIds = array_map('intval', $pendingPicker['user_ids'] ?? []);
    if ($selectedId <= 0 || !in_array($selectedId, $allowedIds, true)) {
        // Defesa: alguém alterou o form. Limpa estado + força refazer.
        unset($_SESSION['pending_login_candidates']);
        flash('danger', __t('auth.invalid'));
        header('Location: /login', true, 303);
        exit;
    }

    $userRow = AuthController::loadActiveUserById($selectedId);
    if ($userRow === null) {
        unset($_SESSION['pending_login_candidates']);
        flash('danger', __t('auth.invalid'));
        header('Location: /login', true, 303);
        exit;
    }

    AuthController::completeLogin($userRow);
    $next = $pendingPicker['next'] ?? null;
    unset($_SESSION['pending_login_candidates']);
    $target = $next ?? AuthController::dashboardFor((string) $userRow['role']);
    header('Location: ' . $target, true, 303);
    exit;
}

$page_title = __t('auth.login');
$pickerCards = [];
if (is_array($pendingPicker)) {
    $pickerCards = AuthController::tenantPickerDisplay(
        array_map('intval', $pendingPicker['user_ids'] ?? [])
    );
}

ob_start();
?>
<div class="row justify-content-center">
    <div class="col-12 col-sm-8 col-md-6 col-lg-5">
        <div class="card shadow-sm">
            <div class="card-body p-4">

                <?php if ($pickerCards !== []): ?>
                    <!-- FASE 2: seletor de tenant -->
                    <h1 class="h4 mb-2 text-center">
                        <?= e(__t('auth.login.tenant_picker.title')) ?>
                    </h1>
                    <p class="text-muted small text-center mb-3">
                        <?= e(__t('auth.login.tenant_picker.help')) ?>
                    </p>

                    <form method="POST" action="/login" novalidate
                          aria-label="<?= e(__t('auth.login.tenant_picker.aria')) ?>">
                        <?= csrf_field() ?>
                        <div class="d-grid gap-2 mb-3 lms-tenant-picker">
                            <?php foreach ($pickerCards as $card): ?>
                                <button type="submit"
                                        name="selected_user_id"
                                        value="<?= (int) $card['user_id'] ?>"
                                        class="btn btn-outline-primary text-start py-3 lms-tenant-picker__card">
                                    <strong class="d-block lms-tenant-picker__name"><?= e($card['tenant_name']) ?></strong>
                                    <small class="lms-tenant-picker__teacher">
                                        <?= e(__t('auth.login.tenant_picker.teacher_label')) ?>:
                                        <?= e($card['teacher_name']) ?>
                                    </small>
                                </button>
                            <?php endforeach; ?>
                        </div>
                        <a href="/login?cancel=picker" class="btn btn-link btn-sm w-100 text-muted">
                            <?= e(__t('auth.login.tenant_picker.cancel')) ?>
                        </a>
                    </form>

                <?php else: ?>
                    <!-- FASE 1: credenciais -->
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
                <?php endif; ?>

            </div>
        </div>
    </div>
</div>
<?php
$page_content = ob_get_clean();

require LMS_ROOT . '/src/templates/layout.php';
