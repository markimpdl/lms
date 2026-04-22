<?php
declare(strict_types=1);

/**
 * /teacher/courses/{id}/edit — edição de curso (E3-01).
 * Bloqueia submit quando curso arquivado — o controller reforça no servidor.
 */

$tenantId = current_tenant_id();
if ($tenantId === null) {
    http_response_code(403);
    require LMS_ROOT . '/src/templates/errors/403.php';
    return;
}

$courseId = (int) ($_REQUEST['id'] ?? 0);
$course = Course::findForTenant($courseId, $tenantId);
if ($course === null) {
    http_response_code(404);
    require LMS_ROOT . '/src/templates/errors/404.php';
    return;
}

$isArchived = (int) $course['archived'] === 1;

$errors = [];
$old = [
    'name'        => (string) $course['name'],
    'description' => (string) ($course['description'] ?? ''),
    'year'        => (int)    $course['year'],
    'language'    => (string) $course['language'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$isArchived) {
    try {
        csrf_verify();
    } catch (RuntimeException) {
        flash('danger', __t('auth.forbidden'));
        header('Location: /teacher/courses/' . $courseId . '/edit');
        exit;
    }

    $old = [
        'name'        => (string) ($_POST['name']        ?? ''),
        'description' => (string) ($_POST['description'] ?? ''),
        'year'        => (int)    ($_POST['year']        ?? 0),
        'language'    => (string) ($_POST['language']    ?? 'pt'),
    ];

    $errors = TeacherCoursesController::update($courseId, $old);
}

$tree = curriculum_tree($courseId, $tenantId);
$activeCcId = 0;
$activeCuId = 0;

$page_title = __t('courses.edit.title');

ob_start();
?>
<?= breadcrumbs([
    ['label' => __t('courses.index.title'), 'url' => '/teacher/courses'],
    ['label' => (string) $course['name'], 'url' => '/teacher/courses/' . (int) $course['id']],
    ['label' => __t('courses.edit.title')],
]) ?>

<button type="button" class="btn btn-outline-secondary d-md-none mb-2"
        data-bs-toggle="offcanvas" data-bs-target="#curriculumOffcanvas">
    ☰ <?= e(__t('nav.curriculum.open')) ?>
</button>

<div class="row g-3">
    <aside class="col-md-3 d-none d-md-block">
        <div class="card shadow-sm sticky-top" style="top: 1rem;">
            <div class="card-body p-2">
                <?php require LMS_ROOT . '/src/templates/partials/curriculum_nav.php'; ?>
            </div>
        </div>
    </aside>
    <div class="col-12 col-md-9 col-lg-7">
        <h1 class="h4 mb-3"><?= e(__t('courses.edit.title')) ?></h1>

        <?php if ($isArchived): ?>
            <div class="alert alert-warning" role="alert">
                <?= e(__t('courses.edit.archived_notice')) ?>
            </div>
        <?php endif; ?>

        <?php if ($errors !== []): ?>
            <div class="alert alert-danger" role="alert">
                <?= e(__t('courses.form.has_errors')) ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="/teacher/courses/<?= (int) $course['id'] ?>/edit" novalidate class="card card-body shadow-sm">
            <?= csrf_field() ?>
            <fieldset <?= $isArchived ? 'disabled' : '' ?>>
                <div class="mb-3">
                    <label for="f-name" class="form-label"><?= e(__t('courses.form.name')) ?></label>
                    <input type="text" name="name" id="f-name"
                           class="form-control form-control-lg<?= isset($errors['name']) ? ' is-invalid' : '' ?>"
                           value="<?= e($old['name']) ?>"
                           required minlength="3" maxlength="150">
                    <?php if (isset($errors['name'])): ?>
                        <div class="invalid-feedback"><?= e(__t($errors['name'])) ?></div>
                    <?php endif; ?>
                </div>

                <div class="mb-3">
                    <label for="f-desc" class="form-label"><?= e(__t('courses.form.description')) ?></label>
                    <textarea name="description" id="f-desc" rows="3"
                              class="form-control<?= isset($errors['description']) ? ' is-invalid' : '' ?>"
                              maxlength="2000"><?= e($old['description']) ?></textarea>
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
                    </div>
                </div>
            </fieldset>

            <div class="d-flex flex-wrap gap-2">
                <?php if (!$isArchived): ?>
                    <button type="submit" class="btn btn-primary btn-lg">
                        <?= e(__t('courses.form.save')) ?>
                    </button>
                <?php endif; ?>
                <a href="/teacher/courses/<?= (int) $course['id'] ?>" class="btn btn-outline-secondary btn-lg">
                    <?= e(__t('common.cancel')) ?>
                </a>
            </div>
        </form>
    </div>
</div>

<!-- Offcanvas (mobile) -->
<div class="offcanvas offcanvas-start" tabindex="-1" id="curriculumOffcanvas" aria-labelledby="curriculumOffcanvasLabel">
    <div class="offcanvas-header">
        <h5 class="offcanvas-title" id="curriculumOffcanvasLabel"><?= e(__t('nav.curriculum.title')) ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body">
        <?php require LMS_ROOT . '/src/templates/partials/curriculum_nav.php'; ?>
    </div>
</div>
<?php
$page_content = ob_get_clean();
require LMS_ROOT . '/src/templates/layout.php';
