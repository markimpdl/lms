<?php
declare(strict_types=1);

/**
 * /profile — usuário logado edita nome/idioma e troca a senha (E1-04).
 *
 * Uma página com duas abas (Bootstrap nav-tabs). O POST distingue pela
 * hidden "form" (data|password). Email é exibido como readonly permanente.
 */

// Auth garantida pelo front controller via src/routes.php (E1-05).
$user = current_user();
$activeTab = 'data';
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_verify();
    } catch (RuntimeException) {
        flash('danger', __t('auth.forbidden'));
        header('Location: /profile');
        exit;
    }

    $form = (string) ($_POST['form'] ?? '');

    if ($form === 'data') {
        $name = (string) ($_POST['name'] ?? '');
        $lang = (string) ($_POST['language'] ?? '');
        $err  = UserController::updateProfile((int) $user['id'], $name, $lang);

        if ($err !== null) {
            $error = __t($err);
        } else {
            $_SESSION['user']['name']     = trim($name);
            $_SESSION['user']['language'] = $lang;
            $_SESSION['lang']             = $lang;
            flash('success', __t('profile.updated'));
            header('Location: /profile');
            exit;
        }
    } elseif ($form === 'password') {
        $activeTab = 'password';
        $current = (string) ($_POST['current_password'] ?? '');
        $new     = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['new_password_confirm'] ?? '');
        $changedAt = null;
        $err = UserController::changePassword((int) $user['id'], $current, $new, $confirm, $changedAt);

        if ($err !== null) {
            $error = __t($err);
        } else {
            // Mesmo timestamp literal que foi ao banco — o middleware de E1-05
            // compara string-a-string e deslogaria por divergência de 1s.
            $_SESSION['user']['password_changed_at'] = $changedAt;
            flash('success', __t('profile.password_changed'));
            header('Location: /profile');
            exit;
        }
    }
}

$user = current_user(); // re-lê após possível mutação acima (embora após erro)
$page_title = __t('profile.title');

$backHref = match ($user['role'] ?? '') {
    'student'     => '/student',
    'teacher'     => '/teacher',
    'super_admin' => '/admin',
    default       => '/',
};

// No student area, .lms-student-main já é o 1fr ao lado da sidebar — não
// precisa do col-lg-8 que comprime ainda mais. Pra teacher/super-admin
// (layout container clássico), mantém o centramento de form estreito.
$isStudent = ($user['role'] ?? '') === 'student';

ob_start();
?>
<?php if (!$isStudent): ?><div class="row justify-content-center"><div class="col-12 col-md-10 col-lg-8"><?php endif; ?>
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
            <h1 class="h4 mb-0"><?= e(__t('profile.title')) ?></h1>
            <a href="<?= e($backHref) ?>" class="btn btn-sm btn-outline-secondary">
                <?= e(__t('common.back')) ?>
            </a>
        </div>

        <?php if ($error !== null): ?>
            <div class="alert alert-danger" role="alert"><?= e($error) ?></div>
        <?php endif; ?>

        <ul class="nav nav-tabs mb-3" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link<?= $activeTab === 'data' ? ' active' : '' ?>"
                        id="tab-data" data-bs-toggle="tab" data-bs-target="#pane-data"
                        type="button" role="tab" aria-controls="pane-data"
                        aria-selected="<?= $activeTab === 'data' ? 'true' : 'false' ?>">
                    <?= e(__t('profile.tab_data')) ?>
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link<?= $activeTab === 'password' ? ' active' : '' ?>"
                        id="tab-password" data-bs-toggle="tab" data-bs-target="#pane-password"
                        type="button" role="tab" aria-controls="pane-password"
                        aria-selected="<?= $activeTab === 'password' ? 'true' : 'false' ?>">
                    <?= e(__t('profile.tab_password')) ?>
                </button>
            </li>
        </ul>

        <div class="tab-content">
            <div class="tab-pane fade<?= $activeTab === 'data' ? ' show active' : '' ?>"
                 id="pane-data" role="tabpanel" aria-labelledby="tab-data">
                <div class="card shadow-sm">
                    <div class="card-body p-4">
                        <form method="POST" action="/profile" novalidate>
                            <?= csrf_field() ?>
                            <input type="hidden" name="form" value="data">

                            <div class="mb-3">
                                <label for="email" class="form-label"><?= e(__t('auth.email')) ?></label>
                                <input type="email" id="email" class="form-control"
                                       value="<?= e($user['email']) ?>"
                                       readonly
                                       data-bs-toggle="tooltip"
                                       title="<?= e(__t('profile.email_readonly')) ?>">
                                <div class="form-text"><?= e(__t('profile.email_readonly')) ?></div>
                            </div>

                            <div class="mb-3">
                                <label for="name" class="form-label"><?= e(__t('profile.name')) ?></label>
                                <input type="text" name="name" id="name"
                                       class="form-control form-control-lg"
                                       value="<?= e($user['name']) ?>"
                                       required maxlength="150" autocomplete="name">
                            </div>

                            <div class="mb-3">
                                <label for="language" class="form-label"><?= e(__t('common.language')) ?></label>
                                <select name="language" id="language" class="form-select form-select-lg">
                                    <option value="pt"<?= ($user['language'] ?? 'pt') === 'pt' ? ' selected' : '' ?>><?= e(__t('profile.lang_pt')) ?></option>
                                    <option value="en"<?= ($user['language'] ?? 'pt') === 'en' ? ' selected' : '' ?>><?= e(__t('profile.lang_en')) ?></option>
                                </select>
                            </div>

                            <button type="submit" class="btn btn-primary btn-lg w-100 w-sm-auto">
                                <?= e(__t('common.save')) ?>
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="tab-pane fade<?= $activeTab === 'password' ? ' show active' : '' ?>"
                 id="pane-password" role="tabpanel" aria-labelledby="tab-password">
                <div class="card shadow-sm">
                    <div class="card-body p-4">
                        <form method="POST" action="/profile" novalidate autocomplete="on">
                            <?= csrf_field() ?>
                            <input type="hidden" name="form" value="password">

                            <div class="mb-3">
                                <label for="current_password" class="form-label"><?= e(__t('profile.current_password')) ?></label>
                                <input type="password" name="current_password" id="current_password"
                                       class="form-control form-control-lg"
                                       required autocomplete="current-password">
                            </div>

                            <div class="mb-3">
                                <label for="new_password" class="form-label"><?= e(__t('profile.new_password')) ?></label>
                                <input type="password" name="new_password" id="new_password"
                                       class="form-control form-control-lg"
                                       required autocomplete="new-password" minlength="8">
                            </div>

                            <div class="mb-3">
                                <label for="new_password_confirm" class="form-label"><?= e(__t('profile.confirm_password')) ?></label>
                                <input type="password" name="new_password_confirm" id="new_password_confirm"
                                       class="form-control form-control-lg"
                                       required autocomplete="new-password" minlength="8">
                            </div>

                            <button type="submit" class="btn btn-primary btn-lg w-100 w-sm-auto">
                                <?= e(__t('profile.change_password')) ?>
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
<?php if (!$isStudent): ?></div></div><?php endif; ?>

<script>
// Bundle do Bootstrap é incluído no fim do <body> pelo layout — esperar `load`
// garante que `bootstrap` global exista antes de instanciar os tooltips.
window.addEventListener('load', function () {
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) {
        new bootstrap.Tooltip(el);
    });
});
</script>
<?php
$page_content = ob_get_clean();

require LMS_ROOT . '/src/templates/layout.php';
