<?php
declare(strict_types=1);

/**
 * GET /teacher/activity/{id}/edit — form de edição da atividade (E6-01).
 * POST atualiza os campos. Alert amarelo quando há submissões (doc 06).
 */

// E32 (ADR-033): conteúdo via tenant do dono (dono ou colaborador).
$__activityId = (int) ($_REQUEST['id'] ?? 0);
$__courseId   = Activity::courseIdOf($__activityId);
$tenantId     = $__courseId !== null ? effective_authoring_tenant($__courseId) : null;
if ($tenantId === null) {
    http_response_code(404);
    require LMS_ROOT . '/src/templates/errors/404.php';
    return;
}

$activityId = (int) ($_REQUEST['id'] ?? 0);
$activity   = Activity::findForTenant($activityId, $tenantId);
if ($activity === null) {
    http_response_code(404);
    require LMS_ROOT . '/src/templates/errors/404.php';
    return;
}

$cuId       = (int) $activity['competence_unit_id'];
$isArchived = (int) $activity['course_archived'] === 1;

$old = [
    'title'                 => (string) $activity['title'],
    'instruction'           => (string) $activity['instruction'],
    'type'                  => (string) $activity['type'],
    'code_language'         => $activity['code_language'] !== null ? (string) $activity['code_language'] : null,
    'xp_value'              => (int)    $activity['xp_value'],
    'submission_open'       => (int)    $activity['submission_open'] === 1,
    'allow_online_code_run' => (int)    $activity['allow_online_code_run'] === 1,
];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_verify();
    } catch (RuntimeException) {
        flash('danger', __t('auth.forbidden'));
        header('Location: /teacher/activity/' . $activityId . '/edit');
        return;
    }

    if ($isArchived) {
        flash('danger', __t('courses.edit.archived_notice'));
        header('Location: /teacher/cu/' . $cuId);
        return;
    }

    $rawLang = trim((string) ($_POST['code_language'] ?? ''));
    $old = [
        'title'                 => trim((string) ($_POST['title']       ?? '')),
        'instruction'           => (string)       ($_POST['instruction'] ?? ''),
        'type'                  => (string)       ($_POST['type']        ?? ''),
        'code_language'         => $rawLang !== '' ? $rawLang : null,
        'xp_value'              => (int)          ($_POST['xp_value']    ?? 0),
        'submission_open'       => isset($_POST['submission_open']),
        'allow_online_code_run' => isset($_POST['allow_online_code_run']),
    ];

    if (mb_strlen($old['title']) < 3 || mb_strlen($old['title']) > 200) {
        $errors['title'] = 'activities.form.err.title';
    }
    if (!in_array($old['type'], Activity::TYPES, true)) {
        $errors['type'] = 'activities.form.err.type';
    }
    if ($old['code_language'] !== null && !in_array($old['code_language'], Activity::CODE_LANGUAGES, true)) {
        $errors['code_language'] = 'activities.form.err.code_language';
    }
    if ($old['xp_value'] < 0 || $old['xp_value'] > 9999) {
        $errors['xp_value'] = 'activities.form.err.xp';
    }
    if ($old['allow_online_code_run'] && $old['type'] !== 'codigo') {
        $old['allow_online_code_run'] = false;
    }
    if ($old['type'] !== 'codigo') {
        $old['code_language'] = null;
    }

    // Brief PDF/ZIP — só pra type=projeto (v0.30.0).
    // pdfPathArg semântica do Activity::update: null=manter, ''=remover, string=novo
    $pdfPathArg = null;
    $fileField  = $_FILES['pdf'] ?? null;
    $hasUpload  = is_array($fileField) && (int) ($fileField['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    $wantRemove = isset($_POST['pdf_remove']);

    if ($errors === [] && $old['type'] === 'projeto') {
        if ($hasUpload) {
            $upload = ActivityBriefStorage::store($fileField, $activityId, $tenantId);
            if ($upload['status'] !== 'ok') {
                $errors['pdf'] = $upload['error_key'] ?? 'activities.err.brief_generic';
            } else {
                $pdfPathArg = $upload['stored_path'];
            }
        } elseif ($wantRemove && $activity['pdf_path'] !== null) {
            $pdfPathArg = ''; // sentinel "remover" pro Activity::update
        }
    } elseif ($errors === [] && $old['type'] !== 'projeto' && $activity['pdf_path'] !== null) {
        // Trocou tipo de projeto pra outro: zera pdf_path automaticamente.
        $pdfPathArg = '';
    }

    if ($errors === []) {
        $clean = ContentSanitizer::purify($old['instruction']);
        $result = Activity::update($activityId, $tenantId, [
            'title'                 => $old['title'],
            'instruction'           => $clean,
            'type'                  => $old['type'],
            'code_language'         => $old['code_language'],
            'pdf_path'              => $pdfPathArg,
            'xp_value'              => $old['xp_value'],
            'submission_open'       => $old['submission_open'],
            'allow_online_code_run' => $old['allow_online_code_run'],
        ]);

        if ($result === 'ok') {
            // Se apagou pdf_path do banco, apaga o arquivo do disco (best-effort).
            if ($pdfPathArg === '' && $activity['pdf_path'] !== null) {
                ActivityBriefStorage::delete($activityId, $tenantId);
            }
            course_audit((int) $__courseId, 'update', 'activity', $activityId, $old['title']);
            flash('success', __t('activities.updated', ['name' => $old['title']]));
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

$mode           = 'edit';
$formAction     = '/teacher/activity/' . $activityId . '/edit';
$submissions    = Activity::countSubmissions($activityId);
$activityName   = (string) $activity['title'];
$currentPdfPath = $activity['pdf_path'] !== null ? (string) $activity['pdf_path'] : null;
$maxMb          = (int) ((ActivityBriefStorage::maxBytes()) / (1024 * 1024));

// E6-05: contagens e counts formatados pra o modal de exclusão.
$deleteCounts = Activity::countForDelete($activityId);
$deleteCountsFormatted = format_delete_counts([
    'submissions' => $deleteCounts['submissions'],
    'xp_events'   => $deleteCounts['xp_events'],
]);

$page_title = __t('activities.edit.title', ['name' => $activityName]);
ob_start();
require LMS_ROOT . '/src/pages/teacher/activity/_form.php';
?>

<?php if (!$isArchived): ?>
<!-- Ações destrutivas (E6-05) -->
<div class="row justify-content-center">
    <div class="col-12 col-lg-10">
        <div class="card card-body shadow-sm mt-3 border-danger-subtle">
            <h2 class="h6 mb-2 text-danger"><?= e(__t('activities.delete.zone')) ?></h2>
            <p class="small text-muted mb-3">
                <?= e(__t('activities.delete.warning')) ?>
            </p>
            <div>
                <button type="button" class="btn btn-outline-danger"
                        data-bs-toggle="modal" data-bs-target="#deleteConfirmModal"
                        data-item-name="<?= e($activityName) ?>"
                        data-action-url="/teacher/activity/<?= $activityId ?>/delete"
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
