<?php
declare(strict_types=1);

/**
 * /admin/teachers/new — cadastro administrativo de professor (E2-02).
 *
 * Auth garantida pelo front controller. POST delega ao
 * AdminTeachersController::create() — em sucesso ele redireciona para a
 * listagem; em erro, devolve um mapa de erros por campo e reentramos no
 * render com os valores antigos preservados.
 */

$errors = [];
$old    = [
    'name'        => '',
    'email'       => '',
    'language'    => 'pt',
    'tenant_name' => '',
    'is_actvet'   => '0',
    'send_email'  => '1',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_verify();
    } catch (RuntimeException) {
        flash('danger', __t('auth.forbidden'));
        header('Location: /admin/teachers/new');
        exit;
    }

    $old = [
        'name'        => (string) ($_POST['name']        ?? ''),
        'email'       => (string) ($_POST['email']       ?? ''),
        'language'    => (string) ($_POST['language']    ?? 'pt'),
        'tenant_name' => (string) ($_POST['tenant_name'] ?? ''),
        'is_actvet'   => isset($_POST['is_actvet']) ? '1' : '0',
        'send_email'  => isset($_POST['send_email']) ? '1' : '0',
    ];

    $errors = AdminTeachersController::create(
        [
            'name'        => $old['name'],
            'email'       => $old['email'],
            'password'    => (string) ($_POST['password'] ?? ''),
            'language'    => $old['language'],
            'tenant_name' => $old['tenant_name'],
            'is_actvet'   => $old['is_actvet'] === '1',
        ],
        $old['send_email'] === '1'
    );
}

$page_title = __t('admin.teachers.form.title');

ob_start();
?>
<div class="row justify-content-center">
    <div class="col-12 col-md-10 col-lg-7">
        <h1 class="h4 mb-3"><?= e(__t('admin.teachers.form.title')) ?></h1>

        <?php if ($errors !== []): ?>
            <div class="alert alert-danger" role="alert">
                <?= e(__t('admin.teachers.form.has_errors')) ?>
            </div>
        <?php endif; ?>

        <?php if (!Mailer::isConfigured()): ?>
            <div class="alert alert-info small" role="alert">
                <?= e(__t('admin.teachers.form.smtp_off_notice')) ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="/admin/teachers/new" novalidate class="card card-body shadow-sm">
            <?= csrf_field() ?>

            <div class="mb-3">
                <label for="f-name" class="form-label"><?= e(__t('admin.teachers.form.name')) ?></label>
                <input type="text" name="name" id="f-name"
                       class="form-control form-control-lg<?= isset($errors['name']) ? ' is-invalid' : '' ?>"
                       value="<?= e($old['name']) ?>"
                       required minlength="3" maxlength="120" autocomplete="name" autofocus>
                <?php if (isset($errors['name'])): ?>
                    <div class="invalid-feedback"><?= e(__t($errors['name'])) ?></div>
                <?php endif; ?>
            </div>

            <div class="mb-3">
                <label for="f-email" class="form-label"><?= e(__t('auth.email')) ?></label>
                <input type="email" name="email" id="f-email"
                       class="form-control form-control-lg<?= isset($errors['email']) ? ' is-invalid' : '' ?>"
                       value="<?= e($old['email']) ?>"
                       required autocomplete="off">
                <?php if (isset($errors['email'])): ?>
                    <div class="invalid-feedback"><?= e(__t($errors['email'])) ?></div>
                <?php endif; ?>
            </div>

            <div class="mb-3">
                <label for="f-password" class="form-label"><?= e(__t('admin.teachers.form.password')) ?></label>
                <input type="text" name="password" id="f-password"
                       class="form-control form-control-lg<?= isset($errors['password']) ? ' is-invalid' : '' ?>"
                       required minlength="8" autocomplete="new-password">
                <div class="form-text"><?= e(__t('admin.teachers.form.password_hint')) ?></div>
                <?php if (isset($errors['password'])): ?>
                    <div class="invalid-feedback"><?= e(__t($errors['password'])) ?></div>
                <?php endif; ?>
            </div>

            <div class="row g-3">
                <div class="col-12 col-md-6 mb-3">
                    <label for="f-lang" class="form-label"><?= e(__t('admin.teachers.language')) ?></label>
                    <select name="language" id="f-lang"
                            class="form-select form-select-lg<?= isset($errors['language']) ? ' is-invalid' : '' ?>">
                        <option value="pt" <?= $old['language'] === 'pt' ? 'selected' : '' ?>><?= e(__t('profile.lang_pt')) ?></option>
                        <option value="en" <?= $old['language'] === 'en' ? 'selected' : '' ?>><?= e(__t('profile.lang_en')) ?></option>
                    </select>
                    <?php if (isset($errors['language'])): ?>
                        <div class="invalid-feedback"><?= e(__t($errors['language'])) ?></div>
                    <?php endif; ?>
                </div>
                <div class="col-12 col-md-6 mb-3">
                    <label for="f-tenant" class="form-label"><?= e(__t('admin.teachers.form.tenant_name')) ?></label>
                    <input type="text" name="tenant_name" id="f-tenant"
                           class="form-control form-control-lg<?= isset($errors['tenant_name']) ? ' is-invalid' : '' ?>"
                           value="<?= e($old['tenant_name']) ?>"
                           required minlength="3" maxlength="120"
                           data-autofill-from="f-name">
                    <div class="form-text"><?= e(__t('admin.teachers.form.tenant_hint')) ?></div>
                    <?php if (isset($errors['tenant_name'])): ?>
                        <div class="invalid-feedback"><?= e(__t($errors['tenant_name'])) ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" name="is_actvet" id="f-actvet"
                       value="1" <?= $old['is_actvet'] === '1' ? 'checked' : '' ?>>
                <label class="form-check-label" for="f-actvet">
                    <?= e(__t('admin.teachers.is_actvet_label')) ?>
                </label>
                <div class="form-text"><?= e(__t('admin.teachers.is_actvet_hint')) ?></div>
            </div>

            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" name="send_email" id="f-send"
                       value="1" <?= $old['send_email'] === '1' ? 'checked' : '' ?>>
                <label class="form-check-label" for="f-send">
                    <?= e(__t('admin.teachers.form.send_email')) ?>
                </label>
            </div>

            <div class="d-flex flex-wrap gap-2">
                <button type="submit" class="btn btn-primary btn-lg">
                    <?= e(__t('admin.teachers.form.submit')) ?>
                </button>
                <a href="/admin/teachers" class="btn btn-outline-secondary btn-lg">
                    <?= e(__t('common.cancel')) ?>
                </a>
            </div>
        </form>
    </div>
</div>

<script>
// Sugestão automática: espelha o nome do professor no nome do tenant até
// que o admin edite o tenant manualmente (dirty flag).
(function () {
    var src = document.getElementById('f-name');
    var dst = document.getElementById('f-tenant');
    if (!src || !dst) return;
    var dirty = dst.value.trim() !== '';
    dst.addEventListener('input', function () { dirty = true; });
    src.addEventListener('input', function () {
        if (!dirty) dst.value = src.value;
    });
})();
</script>
<?php
$page_content = ob_get_clean();

require LMS_ROOT . '/src/templates/layout.php';
