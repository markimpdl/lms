<?php
declare(strict_types=1);

/**
 * GET /teacher/evaluation/{id}/edit — form de edição (E7-01).
 * POST atualiza os campos. PDF é opcional (mantém o atual se não enviar).
 * Alert amarelo quando já há submissões.
 */

// E32 (ADR-033): conteúdo via tenant do dono (dono ou colaborador).
$__evaluationId = (int) ($_REQUEST['id'] ?? 0);
$__courseId     = Evaluation::courseIdOf($__evaluationId);
$tenantId       = $__courseId !== null ? effective_authoring_tenant($__courseId) : null;
if ($tenantId === null) {
    http_response_code(404);
    require LMS_ROOT . '/src/templates/errors/404.php';
    return;
}

$evaluationId = (int) ($_REQUEST['id'] ?? 0);
$evaluation   = Evaluation::findForTenant($evaluationId, $tenantId);
if ($evaluation === null) {
    http_response_code(404);
    require LMS_ROOT . '/src/templates/errors/404.php';
    return;
}

$cuId       = (int) $evaluation['competence_unit_id'];
$isArchived = (int) $evaluation['course_archived'] === 1;
// E25-04: passa pro _form.php (type é imutável em edit, mas o hint adapta).
$isLoMode   = (string) ($evaluation['course_grading_mode'] ?? 'grade') === 'learning_outcomes';

// type é imutável em edit — sempre o do registro original. Não vem do POST
// pra evitar tampering e pra blindar quiz vs projeto (conteúdo incompatível).
$evaluationType = (string) $evaluation['type'];

$old = [
    'title'           => (string) $evaluation['title'],
    'instructions'    => (string) ($evaluation['instructions'] ?? ''),
    'type'            => $evaluationType,
    'xp_value'        => (int)    $evaluation['xp_value'],
    'submission_open' => (int)    $evaluation['submission_open'] === 1,
];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_verify();
    } catch (RuntimeException) {
        flash('danger', __t('auth.forbidden'));
        header('Location: /teacher/evaluation/' . $evaluationId . '/edit');
        return;
    }

    if ($isArchived) {
        flash('danger', __t('courses.edit.archived_notice'));
        header('Location: /teacher/cu/' . $cuId);
        return;
    }

    $old = [
        'title'           => trim((string) ($_POST['title']         ?? '')),
        'instructions'    => (string)       ($_POST['instructions'] ?? ''),
        'type'            => $evaluationType,
        'xp_value'        => (int)          ($_POST['xp_value']     ?? 0),
        'submission_open' => isset($_POST['submission_open']),
    ];

    if (mb_strlen($old['title']) < 3 || mb_strlen($old['title']) > 200) {
        $errors['title'] = 'evaluations.form.err.title';
    }
    if ($old['xp_value'] < 0 || $old['xp_value'] > 9999) {
        $errors['xp_value'] = 'evaluations.form.err.xp';
    }

    $fileField = $_FILES['pdf'] ?? null;
    $hasUpload = is_array($fileField) && (int) ($fileField['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

    $newPdfPath = null;
    if ($hasUpload) {
        $upload = EvaluationBriefStorage::store($fileField, $evaluationId, $tenantId);
        if ($upload['status'] !== 'ok') {
            $errors['pdf'] = $upload['error_key'] ?? 'evaluations.err.brief_generic';
        } else {
            $newPdfPath = $upload['stored_path'];
        }
    }

    if ($errors === []) {
        $clean  = ContentSanitizer::purify($old['instructions']);
        $result = Evaluation::update($evaluationId, $tenantId, [
            'title'           => $old['title'],
            'instructions'    => $clean,
            'type'            => $evaluationType,
            'pdf_path'        => $newPdfPath,
            'xp_value'        => $old['xp_value'],
            'submission_open' => $old['submission_open'],
        ]);

        if ($result === 'ok') {
            flash('success', __t('evaluations.updated', ['name' => $old['title']]));
            header('Location: /teacher/cu/' . $cuId, true, 303);
            return;
        }
        if ($result === 'course_archived') {
            flash('danger', __t('courses.edit.archived_notice'));
            header('Location: /teacher/cu/' . $cuId);
            return;
        }
        http_response_code(404);
        require LMS_ROOT . '/src/templates/errors/404.php';
        return;
    }
}

$mode            = 'edit';
$formAction      = '/teacher/evaluation/' . $evaluationId . '/edit';
$submissions     = Evaluation::countSubmissions($evaluationId);
$pendingGrading  = EvaluationSubmission::countPendingForEvaluation($evaluationId);
$evaluationName  = (string) $evaluation['title'];
$currentPdfPath  = $evaluation['pdf_path'] !== null ? (string) $evaluation['pdf_path'] : null;

$deleteCounts = Evaluation::countForDelete($evaluationId);
$deleteCountsFormatted = format_delete_counts([
    'submissions' => $deleteCounts['submissions'],
    'xp_events'   => $deleteCounts['xp_events'],
]);

$page_title = __t('evaluations.edit.title', ['name' => $evaluationName]);
ob_start();
require LMS_ROOT . '/src/pages/teacher/evaluation/_form.php';
?>

<?php if ($submissions > 0): ?>
<div class="row justify-content-center">
    <div class="col-12 col-lg-10">
        <a href="/teacher/evaluation/<?= $evaluationId ?>/submissions"
           class="btn btn-outline-primary btn-lg w-100 mt-3">
            <?= e(__t('evaluations.submissions.cta', [
                'pending' => (string) $pendingGrading,
                'total'   => (string) $submissions,
            ])) ?>
        </a>
    </div>
</div>
<?php endif; ?>

<?php if (!$isArchived): ?>
<div class="row justify-content-center">
    <div class="col-12 col-lg-10">
        <div class="card card-body shadow-sm mt-3 border-danger-subtle">
            <h2 class="h6 mb-2 text-danger"><?= e(__t('evaluations.delete.zone')) ?></h2>
            <p class="small text-muted mb-3">
                <?= e(__t('evaluations.delete.warning')) ?>
            </p>
            <div>
                <button type="button" class="btn btn-outline-danger"
                        data-bs-toggle="modal" data-bs-target="#deleteConfirmModal"
                        data-item-name="<?= e($evaluationName) ?>"
                        data-action-url="/teacher/evaluation/<?= $evaluationId ?>/delete"
                        data-counts="<?= e(json_encode($deleteCountsFormatted, JSON_UNESCAPED_UNICODE)) ?>">
                    <?= e(__t('delete.action')) ?>
                </button>
            </div>
        </div>
    </div>
</div>

<?php require LMS_ROOT . '/src/templates/partials/delete_confirm_modal.php'; ?>
<?php endif; ?>

<?php
$page_content = ob_get_clean();
require LMS_ROOT . '/src/templates/layout.php';
