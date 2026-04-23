<?php
declare(strict_types=1);

/**
 * /teacher/students/new — cadastro de aluno pelo professor (E4-01).
 * Em sucesso, o controller redireciona para /teacher/students/{id}.
 * Em erro, re-renderiza o form com os valores antigos preservados.
 */

$tenantId     = current_tenant_id();
$activeCourses = $tenantId !== null ? Course::listActiveForSelect($tenantId) : [];

$errors = [];
$old = [
    'name'       => '',
    'email'      => '',
    'language'   => current_user()['language'] ?? 'pt',
    'send_email' => '1',
    'course_ids' => [],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_verify();
    } catch (RuntimeException) {
        flash('danger', __t('auth.forbidden'));
        header('Location: /teacher/students/new');
        exit;
    }

    $old = [
        'name'       => (string) ($_POST['name']     ?? ''),
        'email'      => (string) ($_POST['email']    ?? ''),
        'language'   => (string) ($_POST['language'] ?? 'pt'),
        'send_email' => isset($_POST['send_email']) ? '1' : '0',
        'course_ids' => array_map('intval', (array) ($_POST['course_ids'] ?? [])),
    ];

    $errors = TeacherStudentsController::create(
        [
            'name'     => $old['name'],
            'email'    => $old['email'],
            'password' => (string) ($_POST['password'] ?? ''),
            'language' => $old['language'],
        ],
        $old['send_email'] === '1',
        $old['course_ids']
    );
}

$page_title = __t('students.form.title_new');

ob_start();
?>
<div class="row justify-content-center">
    <div class="col-12 col-md-10 col-lg-7">
        <h1 class="h4 mb-3"><?= e(__t('students.form.title_new')) ?></h1>

        <?php if ($errors !== []): ?>
            <div class="alert alert-danger" role="alert">
                <?= e(__t('students.form.has_errors')) ?>
            </div>
        <?php endif; ?>

        <?php if (!Mailer::isConfigured()): ?>
            <div class="alert alert-info small" role="alert">
                <?= e(__t('students.form.smtp_off_notice')) ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="/teacher/students/new" novalidate class="card card-body shadow-sm">
            <?= csrf_field() ?>

            <div class="mb-3">
                <label for="f-name" class="form-label"><?= e(__t('students.form.name')) ?></label>
                <input type="text" name="name" id="f-name"
                       class="form-control form-control-lg<?= isset($errors['name']) ? ' is-invalid' : '' ?>"
                       value="<?= e($old['name']) ?>"
                       required minlength="3" maxlength="120" autocomplete="off" autofocus>
                <?php if (isset($errors['name'])): ?>
                    <div class="invalid-feedback"><?= e(__t($errors['name'])) ?></div>
                <?php endif; ?>
            </div>

            <div class="mb-3">
                <label for="f-email" class="form-label"><?= e(__t('students.form.email')) ?></label>
                <input type="email" name="email" id="f-email"
                       class="form-control form-control-lg<?= isset($errors['email']) ? ' is-invalid' : '' ?>"
                       value="<?= e($old['email']) ?>"
                       required autocomplete="off">
                <?php if (isset($errors['email'])): ?>
                    <div class="invalid-feedback"><?= e(__t($errors['email'])) ?></div>
                <?php endif; ?>
            </div>

            <div class="mb-3">
                <label for="f-password" class="form-label"><?= e(__t('students.form.password')) ?></label>
                <div class="input-group">
                    <input type="text" name="password" id="f-password"
                           class="form-control form-control-lg<?= isset($errors['password']) ? ' is-invalid' : '' ?>"
                           required minlength="8" autocomplete="new-password">
                    <button type="button" class="btn btn-outline-secondary" id="btn-gen-pass">
                        <?= e(__t('students.form.password_generate')) ?>
                    </button>
                </div>
                <div class="form-text"><?= e(__t('students.form.password_hint')) ?></div>
                <?php if (isset($errors['password'])): ?>
                    <div class="invalid-feedback d-block"><?= e(__t($errors['password'])) ?></div>
                <?php endif; ?>
            </div>

            <div class="mb-3">
                <label for="f-lang" class="form-label"><?= e(__t('students.form.language')) ?></label>
                <select name="language" id="f-lang"
                        class="form-select form-select-lg<?= isset($errors['language']) ? ' is-invalid' : '' ?>">
                    <option value="pt" <?= $old['language'] === 'pt' ? 'selected' : '' ?>><?= e(__t('profile.lang_pt')) ?></option>
                    <option value="en" <?= $old['language'] === 'en' ? 'selected' : '' ?>><?= e(__t('profile.lang_en')) ?></option>
                </select>
                <?php if (isset($errors['language'])): ?>
                    <div class="invalid-feedback"><?= e(__t($errors['language'])) ?></div>
                <?php endif; ?>
            </div>

            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" name="send_email" id="f-send"
                       value="1" <?= $old['send_email'] === '1' ? 'checked' : '' ?>>
                <label class="form-check-label" for="f-send">
                    <?= e(__t('students.form.send_email')) ?>
                </label>
            </div>

            <?php if ($activeCourses !== []): ?>
                <div class="mb-3">
                    <label for="f-courses" class="form-label"><?= e(__t('enrollments.form.enroll_in')) ?></label>
                    <select name="course_ids[]" id="f-courses" class="form-select" multiple size="6">
                        <?php foreach ($activeCourses as $c): ?>
                            <option value="<?= (int) $c['id'] ?>"
                                    <?= in_array((int) $c['id'], $old['course_ids'], true) ? 'selected' : '' ?>>
                                <?= e($c['name']) ?> (<?= (int) $c['year'] ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text"><?= e(__t('enrollments.form.enroll_in_hint')) ?></div>
                </div>
            <?php endif; ?>

            <div class="d-flex flex-wrap gap-2">
                <button type="submit" class="btn btn-primary btn-lg">
                    <?= e(__t('students.form.submit_new')) ?>
                </button>
                <a href="/teacher/students" class="btn btn-outline-secondary btn-lg">
                    <?= e(__t('common.cancel')) ?>
                </a>
            </div>
        </form>
    </div>
</div>

<script>
// "Gerar senha forte" — 12 chars alfanum + símbolos, pelo menos 1 de cada classe.
(function () {
    var btn = document.getElementById('btn-gen-pass');
    var input = document.getElementById('f-password');
    if (!btn || !input) return;

    function pick(src) { return src.charAt(Math.floor(Math.random() * src.length)); }
    function generate() {
        var lower   = 'abcdefghijkmnpqrstuvwxyz';
        var upper   = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        var digits  = '23456789';
        var symbols = '!@#$%&*?';
        var all     = lower + upper + digits + symbols;
        var out = pick(lower) + pick(upper) + pick(digits) + pick(symbols);
        for (var i = 0; i < 8; i++) out += pick(all);
        return out.split('').sort(function () { return Math.random() - 0.5; }).join('');
    }
    btn.addEventListener('click', function () {
        input.type = 'text';
        input.value = generate();
        input.focus();
        input.select();
    });
})();
</script>
<?php
$page_content = ob_get_clean();
require LMS_ROOT . '/src/templates/layout.php';
