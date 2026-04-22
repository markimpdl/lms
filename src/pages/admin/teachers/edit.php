<?php
declare(strict_types=1);

/**
 * /admin/teachers/{id} — editar professor + tenant (E2-03).
 *
 * O id vem capturado pelo roteador em $_REQUEST['id']. Auth e papel já
 * garantidos pelo front controller. Email é readonly permanente (ADR-021)
 * e a senha não aparece aqui — fluxo separado em E2-07.
 */

$teacherId = (int) ($_REQUEST['id'] ?? 0);
$teacher   = $teacherId > 0 ? TeacherAdmin::findById($teacherId) : null;

if ($teacher === null) {
    http_response_code(404);
    require LMS_ROOT . '/src/templates/errors/404.php';
    return;
}

$errors = [];
$old = [
    'name'        => (string) $teacher['name'],
    'language'    => (string) $teacher['language'],
    'tenant_name' => (string) $teacher['tenant_name'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_verify();
    } catch (RuntimeException) {
        flash('danger', __t('auth.forbidden'));
        header('Location: /admin/teachers/' . $teacherId);
        exit;
    }

    $old = [
        'name'        => (string) ($_POST['name']        ?? $old['name']),
        'language'    => (string) ($_POST['language']    ?? $old['language']),
        'tenant_name' => (string) ($_POST['tenant_name'] ?? $old['tenant_name']),
    ];

    $errors = AdminTeachersController::update($teacherId, $old);
}

// Senha recém-resetada (E2-07) — mostrada UMA vez e drenada da sessão.
// Só considerada se refere ao professor aberto agora.
$resetOnce = $_SESSION['teacher_password_reset_once'] ?? null;
if ($resetOnce !== null && (int) ($resetOnce['teacher_id'] ?? 0) === $teacherId) {
    unset($_SESSION['teacher_password_reset_once']);
} else {
    $resetOnce = null;
}

$page_title = __t('admin.teachers.edit.title');

ob_start();
?>
<div class="row justify-content-center">
    <div class="col-12 col-md-10 col-lg-8">
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
            <h1 class="h4 mb-0"><?= e(__t('admin.teachers.edit.title')) ?></h1>
            <a href="/admin/teachers" class="btn btn-sm btn-outline-secondary">
                <?= e(__t('common.back')) ?>
            </a>
        </div>

        <?php if ($errors !== []): ?>
            <div class="alert alert-danger" role="alert">
                <?= e(__t('admin.teachers.form.has_errors')) ?>
            </div>
        <?php endif; ?>

        <?php if ($resetOnce !== null): ?>
            <div class="alert alert-success" role="alert">
                <h2 class="h6 mb-2"><?= e(__t('admin.teachers.reset_password.creds_title', ['name' => $resetOnce['name']])) ?></h2>
                <p class="small mb-2">
                    <?= e(__t(
                        $resetOnce['reason'] === 'smtp_unavailable'
                            ? 'admin.teachers.reset_password.creds_smtp_off'
                            : 'admin.teachers.reset_password.creds_opted_out'
                    )) ?>
                </p>
                <dl class="row mb-0 small">
                    <dt class="col-4 col-md-3"><?= e(__t('auth.email')) ?></dt>
                    <dd class="col-8 col-md-9"><code><?= e($resetOnce['email']) ?></code></dd>
                    <dt class="col-4 col-md-3"><?= e(__t('admin.teachers.reset_password.new_password')) ?></dt>
                    <dd class="col-8 col-md-9"><code><?= e($resetOnce['password']) ?></code></dd>
                </dl>
            </div>
        <?php endif; ?>

        <form method="POST" action="/admin/teachers/<?= (int) $teacherId ?>" novalidate class="card card-body shadow-sm mb-3">
            <?= csrf_field() ?>

            <div class="mb-3">
                <label for="f-email" class="form-label"><?= e(__t('auth.email')) ?></label>
                <input type="email" id="f-email" class="form-control"
                       value="<?= e($teacher['email']) ?>"
                       readonly
                       data-bs-toggle="tooltip"
                       title="<?= e(__t('admin.teachers.email_immutable')) ?>">
                <div class="form-text"><?= e(__t('admin.teachers.email_immutable')) ?></div>
            </div>

            <div class="mb-3">
                <label for="f-name" class="form-label"><?= e(__t('admin.teachers.form.name')) ?></label>
                <input type="text" name="name" id="f-name"
                       class="form-control form-control-lg<?= isset($errors['name']) ? ' is-invalid' : '' ?>"
                       value="<?= e($old['name']) ?>"
                       required minlength="3" maxlength="120" autocomplete="name">
                <?php if (isset($errors['name'])): ?>
                    <div class="invalid-feedback"><?= e(__t($errors['name'])) ?></div>
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
                           required minlength="3" maxlength="120">
                    <?php if (isset($errors['tenant_name'])): ?>
                        <div class="invalid-feedback"><?= e(__t($errors['tenant_name'])) ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="d-flex flex-wrap gap-2">
                <button type="submit" class="btn btn-primary btn-lg">
                    <?= e(__t('common.save')) ?>
                </button>
                <a href="/admin/teachers" class="btn btn-outline-secondary btn-lg">
                    <?= e(__t('common.cancel')) ?>
                </a>
            </div>
        </form>

        <div class="card card-body shadow-sm">
            <h2 class="h6 mb-3"><?= e(__t('admin.teachers.edit.metadata')) ?></h2>
            <dl class="row mb-0 small">
                <dt class="col-6 col-md-4"><?= e(__t('admin.teachers.edit.status')) ?></dt>
                <dd class="col-6 col-md-8">
                    <?php if ((int) $teacher['active'] === 1): ?>
                        <span class="badge bg-success-subtle text-success border border-success-subtle"><?= e(__t('admin.teachers.active')) ?></span>
                    <?php else: ?>
                        <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle"><?= e(__t('admin.teachers.inactive')) ?></span>
                    <?php endif; ?>
                </dd>

                <dt class="col-6 col-md-4"><?= e(__t('admin.teachers.created_at')) ?></dt>
                <dd class="col-6 col-md-8"><?= e(substr((string) $teacher['created_at'], 0, 10)) ?></dd>

                <dt class="col-6 col-md-4"><?= e(__t('admin.teachers.last_login')) ?></dt>
                <dd class="col-6 col-md-8">
                    <?= $teacher['last_login_at'] !== null
                        ? e(substr((string) $teacher['last_login_at'], 0, 16))
                        : '<span class="text-muted">' . e(__t('admin.teachers.never')) . '</span>' ?>
                </dd>

                <dt class="col-6 col-md-4"><?= e(__t('admin.teachers.courses')) ?></dt>
                <dd class="col-6 col-md-8"><?= (int) $teacher['active_courses'] ?></dd>

                <dt class="col-6 col-md-4"><?= e(__t('admin.teachers.students')) ?></dt>
                <dd class="col-6 col-md-8"><?= (int) $teacher['unique_students'] ?></dd>
            </dl>

            <hr>

            <div class="d-flex flex-wrap gap-2 justify-content-end">
                <button type="button" class="btn btn-sm btn-outline-warning"
                        data-bs-toggle="modal" data-bs-target="#reset-password-modal">
                    <?= e(__t('admin.teachers.reset_password.button')) ?>
                </button>

                <form method="POST" action="/admin/teachers/<?= (int) $teacher['id'] ?>/toggle" class="m-0 js-toggle-form"
                      <?php if ((int) $teacher['active'] === 1): ?>
                          data-confirm="<?= e(__t('admin.teachers.deactivate.confirm_short', ['name' => $teacher['name']])) ?>"
                      <?php endif; ?>>
                    <?= csrf_field() ?>
                    <input type="hidden" name="from" value="edit">
                    <?php if ((int) $teacher['active'] === 1): ?>
                        <button type="submit" class="btn btn-sm btn-outline-danger">
                            <?= e(__t('admin.teachers.deactivate')) ?>
                        </button>
                    <?php else: ?>
                        <button type="submit" class="btn btn-sm btn-outline-success">
                            <?= e(__t('admin.teachers.reactivate')) ?>
                        </button>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Modal de reset de senha (E2-07) -->
<div class="modal fade" id="reset-password-modal" tabindex="-1" aria-hidden="true" aria-labelledby="reset-password-title">
    <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down">
        <div class="modal-content">
            <form method="POST" action="/admin/teachers/<?= (int) $teacher['id'] ?>/reset-password" novalidate>
                <?= csrf_field() ?>
                <div class="modal-header">
                    <h2 class="modal-title h5" id="reset-password-title">
                        <?= e(__t('admin.teachers.reset_password.title', ['name' => $teacher['name']])) ?>
                    </h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= e(__t('common.cancel')) ?>"></button>
                </div>
                <div class="modal-body">
                    <?php if (!Mailer::isConfigured()): ?>
                        <div class="alert alert-info small" role="alert">
                            <?= e(__t('admin.teachers.reset_password.smtp_off_notice')) ?>
                        </div>
                    <?php endif; ?>

                    <div class="mb-3">
                        <label for="f-new-password" class="form-label">
                            <?= e(__t('admin.teachers.reset_password.new_password')) ?>
                        </label>
                        <input type="text" name="new_password" id="f-new-password"
                               class="form-control form-control-lg"
                               required minlength="8" autocomplete="new-password">
                        <div class="form-text"><?= e(__t('admin.teachers.form.password_hint')) ?></div>
                    </div>

                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="f-send-email"
                               name="send_email" value="1" checked>
                        <label class="form-check-label" for="f-send-email">
                            <?= e(__t('admin.teachers.reset_password.send_email')) ?>
                        </label>
                    </div>

                    <p class="small text-muted mt-3 mb-0">
                        <?= e(__t('admin.teachers.reset_password.note')) ?>
                    </p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                        <?= e(__t('common.cancel')) ?>
                    </button>
                    <button type="submit" class="btn btn-warning">
                        <?= e(__t('admin.teachers.reset_password.confirm')) ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Tooltip no campo de email (readonly). window.load garante que o bundle
// do Bootstrap carregado no fim do body já esteja disponível.
window.addEventListener('load', function () {
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) {
        new bootstrap.Tooltip(el);
    });
});

// Confirmação via data-confirm — evita incorporar a string no atributo
// onsubmit (que quebraria se o nome tivesse apóstrofo).
document.querySelectorAll('form.js-toggle-form[data-confirm]').forEach(function (form) {
    form.addEventListener('submit', function (event) {
        if (!window.confirm(form.dataset.confirm)) {
            event.preventDefault();
        }
    });
});
</script>
<?php
$page_content = ob_get_clean();

require LMS_ROOT . '/src/templates/layout.php';
