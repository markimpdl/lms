<?php
declare(strict_types=1);

/**
 * /teacher/courses/new — criar curso (E3-01).
 * POST delega ao TeacherCoursesController::create() (redireciona em sucesso).
 */

$errors = [];
$old = [
    'name'        => '',
    'description' => '',
    'year'        => (int) date('Y'),
    'language'    => current_user()['language'] ?? 'pt',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_verify();
    } catch (RuntimeException) {
        flash('danger', __t('auth.forbidden'));
        header('Location: /teacher/courses/new');
        exit;
    }

    $old = [
        'name'        => (string) ($_POST['name']        ?? ''),
        'description' => (string) ($_POST['description'] ?? ''),
        'year'        => (int)    ($_POST['year']        ?? 0),
        'language'    => (string) ($_POST['language']    ?? 'pt'),
    ];

    $errors = TeacherCoursesController::create($old);
}

$page_title = __t('courses.new.title');

ob_start();
?>
<div class="row justify-content-center">
    <div class="col-12 col-md-10 col-lg-7">
        <h1 class="h4 mb-3"><?= e(__t('courses.new.title')) ?></h1>

        <?php if ($errors !== []): ?>
            <div class="alert alert-danger" role="alert">
                <?= e(__t('courses.form.has_errors')) ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="/teacher/courses/new" novalidate class="card card-body shadow-sm">
            <?= csrf_field() ?>

            <div class="mb-3">
                <label for="f-name" class="form-label"><?= e(__t('courses.form.name')) ?></label>
                <input type="text" name="name" id="f-name"
                       class="form-control form-control-lg<?= isset($errors['name']) ? ' is-invalid' : '' ?>"
                       value="<?= e($old['name']) ?>"
                       required minlength="3" maxlength="150" autofocus>
                <?php if (isset($errors['name'])): ?>
                    <div class="invalid-feedback"><?= e(__t($errors['name'])) ?></div>
                <?php endif; ?>
            </div>

            <div class="mb-3">
                <label for="f-desc" class="form-label"><?= e(__t('courses.form.description')) ?></label>
                <textarea name="description" id="f-desc" rows="3"
                          class="form-control<?= isset($errors['description']) ? ' is-invalid' : '' ?>"
                          maxlength="2000"><?= e($old['description']) ?></textarea>
                <div class="form-text"><?= e(__t('courses.form.description_hint')) ?></div>
                <?php if (isset($errors['description'])): ?>
                    <div class="invalid-feedback"><?= e(__t($errors['description'])) ?></div>
                <?php endif; ?>
            </div>

            <div class="row g-3">
                <div class="col-6 col-md-4 mb-3">
                    <label for="f-year" class="form-label"><?= e(__t('courses.form.year')) ?></label>
                    <input type="number" name="year" id="f-year"
                           class="form-control form-control-lg<?= isset($errors['year']) ? ' is-invalid' : '' ?>"
                           value="<?= e((string) $old['year']) ?>"
                           min="<?= TeacherCoursesController::YEAR_MIN ?>"
                           max="<?= TeacherCoursesController::YEAR_MAX ?>"
                           required>
                    <?php if (isset($errors['year'])): ?>
                        <div class="invalid-feedback"><?= e(__t($errors['year'])) ?></div>
                    <?php endif; ?>
                </div>
                <div class="col-6 col-md-4 mb-3">
                    <label for="f-lang" class="form-label"><?= e(__t('courses.form.language')) ?></label>
                    <select name="language" id="f-lang"
                            class="form-select form-select-lg<?= isset($errors['language']) ? ' is-invalid' : '' ?>">
                        <option value="pt" <?= $old['language'] === 'pt' ? 'selected' : '' ?>><?= e(__t('profile.lang_pt')) ?></option>
                        <option value="en" <?= $old['language'] === 'en' ? 'selected' : '' ?>><?= e(__t('profile.lang_en')) ?></option>
                    </select>
                    <?php if (isset($errors['language'])): ?>
                        <div class="invalid-feedback"><?= e(__t($errors['language'])) ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="d-flex flex-wrap gap-2">
                <button type="submit" class="btn btn-primary btn-lg">
                    <?= e(__t('courses.form.save')) ?>
                </button>
                <a href="/teacher/courses" class="btn btn-outline-secondary btn-lg">
                    <?= e(__t('common.cancel')) ?>
                </a>
            </div>
        </form>
    </div>
</div>
<?php
$page_content = ob_get_clean();
require LMS_ROOT . '/src/templates/layout.php';
