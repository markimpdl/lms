<?php
declare(strict_types=1);

/**
 * /teacher/cu/{id}/learning-outcomes — cadastro de 5 LOs por CU (E25-02).
 *
 * Restrito a tenant Actvet + curso em modo `learning_outcomes`. Defesa
 * server-side em camadas: 403 quando (a) tenant não-Actvet, (b) curso não
 * está em LO mode, (c) CU não pertence ao tenant logado.
 *
 * Save: 5 inputs obrigatórios (max 500 chars cada). `LearningOutcome::replaceForCu`
 * substitui atomicamente — DELETE + 5 INSERT com position 1..5.
 */

const LO_REQUIRED_COUNT = 5;
const LO_MAX_LENGTH     = 500;

// E32 (ADR-033): conteúdo via tenant do dono (dono ou colaborador).
$__cuId     = (int) ($_REQUEST['id'] ?? 0);
$__courseId = CompetenceUnit::courseIdOf($__cuId);
$tenantId   = $__courseId !== null ? effective_authoring_tenant($__courseId) : null;
if ($tenantId === null) {
    http_response_code(404);
    require LMS_ROOT . '/src/templates/errors/404.php';
    return;
}

$cuId = (int) ($_REQUEST['id'] ?? 0);
$cu   = CompetenceUnit::findForTenant($cuId, $tenantId);
if ($cu === null) {
    http_response_code(404);
    require LMS_ROOT . '/src/templates/errors/404.php';
    return;
}

// Gate: só Actvet com curso em LO mode pode ver/editar.
$tenant   = Tenant::findById($tenantId);
$isActvet = $tenant !== null && (int) ($tenant['is_actvet'] ?? 0) === 1;
$isLoMode = (string) ($cu['course_grading_mode'] ?? 'grade') === 'learning_outcomes';

if (!$isActvet || !$isLoMode) {
    http_response_code(403);
    require LMS_ROOT . '/src/templates/errors/403.php';
    return;
}

$courseId   = (int) $cu['course_id'];
$ccId       = (int) $cu['core_competency_id'];
$isArchived = (int) $cu['course_archived'] === 1;

$existing = LearningOutcome::findByCu($cuId);

// Pre-popula 5 slots (existentes + vazios até completar 5).
$old = [];
for ($i = 0; $i < LO_REQUIRED_COUNT; $i++) {
    $old[] = (string) ($existing[$i]['description'] ?? '');
}

$errors = [];
$confirmError = null;

// Quantas notas por LO já estão gravadas — se > 0, a UI exige
// confirmação explícita pra rodar o replaceForCu (que cascateia
// DELETE em evaluation_submission_lo_grades).
$gradesCount = LearningOutcome::countGradesByCu($cuId);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$isArchived) {
    try {
        csrf_verify();
    } catch (RuntimeException) {
        flash('danger', __t('auth.forbidden'));
        header('Location: /teacher/cu/' . $cuId . '/learning-outcomes');
        exit;
    }

    $rawInputs = is_array($_POST['descriptions'] ?? null) ? $_POST['descriptions'] : [];
    $old = [];
    for ($i = 0; $i < LO_REQUIRED_COUNT; $i++) {
        $old[] = trim((string) ($rawInputs[$i] ?? ''));
    }

    foreach ($old as $i => $desc) {
        if ($desc === '') {
            $errors[$i] = 'learning_outcomes.err.required';
        } elseif (mb_strlen($desc) > LO_MAX_LENGTH) {
            $errors[$i] = 'learning_outcomes.err.too_long';
        }
    }

    if ($errors === [] && $gradesCount > 0 && empty($_POST['confirm_drop_grades'])) {
        $confirmError = 'learning_outcomes.err.confirm_required';
    }

    if ($errors === [] && $confirmError === null) {
        LearningOutcome::replaceForCu($cuId, $old);
        flash('success', __t('learning_outcomes.success'));
        header('Location: /teacher/cu/' . $cuId, true, 303);
        exit;
    }
}

$tree       = curriculum_tree($courseId, $tenantId);
$activeCcId = $ccId;
$activeCuId = $cuId;

$ccName = '';
$courseName = (string) $tree['name'];
foreach ($tree['ccs'] as $navCc) {
    if ((int) $navCc['id'] === $ccId) {
        $ccName = (string) $navCc['name'];
        break;
    }
}

$page_title = __t('learning_outcomes.title') . ' — ' . (string) $cu['name'];

ob_start();
?>
<?= breadcrumbs([
    ['label' => __t('courses.index.title'), 'url' => '/teacher/courses'],
    ['label' => $courseName, 'url' => '/teacher/courses/' . $courseId],
    ['label' => $ccName,     'url' => '/teacher/courses/' . $courseId . '/cc/' . $ccId],
    ['label' => (string) $cu['name'], 'url' => '/teacher/cu/' . $cuId],
    ['label' => __t('learning_outcomes.breadcrumb')],
]) ?>

<div class="row justify-content-center">
    <div class="col-12 col-lg-9">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
            <div>
                <h1 class="h4 mb-1"><?= e(__t('learning_outcomes.title')) ?></h1>
                <small class="text-muted"><?= e((string) $cu['name']) ?></small>
            </div>
            <a href="/teacher/cu/<?= $cuId ?>" class="btn btn-sm btn-outline-secondary">
                <?= e(__t('common.back')) ?>
            </a>
        </div>

        <p class="text-muted small mb-3"><?= e(__t('learning_outcomes.help')) ?></p>

        <?php if ($isArchived): ?>
            <div class="alert alert-warning" role="alert">
                <?= e(__t('courses.edit.archived_notice')) ?>
            </div>
        <?php endif; ?>

        <?php if ($errors !== []): ?>
            <div class="alert alert-danger" role="alert">
                <?= e(__t('learning_outcomes.has_errors')) ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="/teacher/cu/<?= $cuId ?>/learning-outcomes"
              novalidate class="card card-body shadow-sm">
            <?= csrf_field() ?>

            <?php for ($i = 0; $i < LO_REQUIRED_COUNT; $i++): ?>
                <div class="mb-3">
                    <label for="f-lo-<?= $i ?>" class="form-label">
                        <?= e(__t('learning_outcomes.field_n', ['n' => (string) ($i + 1)])) ?>
                    </label>
                    <textarea name="descriptions[]" id="f-lo-<?= $i ?>"
                              class="form-control<?= isset($errors[$i]) ? ' is-invalid' : '' ?>"
                              rows="2"
                              maxlength="<?= LO_MAX_LENGTH ?>"
                              placeholder="<?= e(__t('learning_outcomes.placeholder')) ?>"
                              <?= $isArchived ? 'disabled' : '' ?>><?= e($old[$i]) ?></textarea>
                    <?php if (isset($errors[$i])): ?>
                        <div class="invalid-feedback"><?= e(__t($errors[$i])) ?></div>
                    <?php endif; ?>
                </div>
            <?php endfor; ?>

            <?php if (!$isArchived && $gradesCount > 0): ?>
                <div class="alert alert-warning" role="alert">
                    <strong><?= e(__t('learning_outcomes.cascade_warning_title')) ?></strong>
                    <p class="mb-2 mt-1">
                        <?= e(__t('learning_outcomes.cascade_warning_body', ['n' => (string) $gradesCount])) ?>
                    </p>
                    <div class="form-check">
                        <input type="checkbox" name="confirm_drop_grades" value="1"
                               id="f-confirm-drop"
                               class="form-check-input<?= $confirmError !== null ? ' is-invalid' : '' ?>">
                        <label class="form-check-label" for="f-confirm-drop">
                            <?= e(__t('learning_outcomes.cascade_warning_confirm')) ?>
                        </label>
                        <?php if ($confirmError !== null): ?>
                            <div class="invalid-feedback d-block"><?= e(__t($confirmError)) ?></div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!$isArchived): ?>
                <div class="d-flex flex-wrap gap-2">
                    <button type="submit" class="btn btn-primary">
                        <?= e(__t('common.save')) ?>
                    </button>
                    <a href="/teacher/cu/<?= $cuId ?>" class="btn btn-outline-secondary">
                        <?= e(__t('common.cancel')) ?>
                    </a>
                </div>
            <?php endif; ?>
        </form>
    </div>
</div>
<?php
$page_content = ob_get_clean();
require LMS_ROOT . '/src/templates/layout.php';
