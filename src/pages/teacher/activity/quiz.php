<?php
declare(strict_types=1);

/**
 * /teacher/activity/{id}/quiz — form de criar/editar quiz da atividade
 * (E20-02). Mesma estrutura de evaluation/quiz.php; só muda owner_type
 * e URLs de cancelamento.
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
$ctx = TeacherQuizController::loadOwner('activity', $activityId, $tenantId);
if ($ctx === null) {
    http_response_code(404);
    require LMS_ROOT . '/src/templates/errors/404.php';
    return;
}

$activity   = $ctx['owner'];
$courseId   = $ctx['course_id'];
$ownerName  = $ctx['owner_name'];

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_verify();
    } catch (RuntimeException) {
        flash('danger', __t('auth.forbidden'));
        header('Location: /teacher/activity/' . $activityId . '/quiz', true, 303);
        exit;
    }

    $v = TeacherQuizController::validate($_POST);
    if ($v['errors'] === []) {
        TeacherQuizController::saveBulk('activity', $activityId, $tenantId, $v['data']);
        flash('success', __t('quiz.form.saved'));
        // E23-02: redirect pra CU pai (era a mesma URL).
        header('Location: /teacher/cu/' . (int) $activity['competence_unit_id'], true, 303);
        exit;
    }
    $errors = $v['errors'];
    $questions = array_map(static function ($q) {
        $q = is_array($q) ? $q : [];
        $opts = $q['options'] ?? [];
        return [
            'text'        => (string) ($q['text'] ?? ''),
            'weight'      => (float)  ($q['weight'] ?? 1.0),
            'correct_idx' => (int)    ($q['correct'] ?? 0),
            'options'     => array_map(static function ($o) {
                return ['text' => (string) ($o['text'] ?? '')];
            }, is_array($opts) ? array_values($opts) : []),
        ];
    }, array_values((array) ($_POST['questions'] ?? [])));
    $showAnswers = !empty($_POST['show_answers']);
} else {
    $quiz = Quiz::findByOwner('activity', $activityId, $tenantId);
    $showAnswers = $quiz !== null && (int) $quiz['show_answers'] === 1;
    $questions = [];
    if ($quiz !== null) {
        $full = Quiz::findFullById((int) $quiz['id'], $tenantId);
        if ($full !== null) {
            foreach ($full['questions'] as $q) {
                $correctIdx = 0;
                $optsOut    = [];
                foreach ($q['options'] as $oIdx => $opt) {
                    $optsOut[] = ['text' => (string) $opt['text']];
                    if ((int) $opt['is_correct'] === 1) {
                        $correctIdx = $oIdx;
                    }
                }
                $questions[] = [
                    'text'        => (string) $q['text'],
                    'weight'      => (float)  $q['weight'],
                    'correct_idx' => $correctIdx,
                    'options'     => $optsOut,
                ];
            }
        }
    }
}

$formAction  = '/teacher/activity/' . $activityId . '/quiz';
$cancelUrl   = '/teacher/cu/' . (int) $activity['competence_unit_id'];
$settingsUrl = '/teacher/activity/' . $activityId . '/edit';

// Mesma lacuna de evaluation/quiz.php: a lista da CU manda atividade quiz
// direto pra ca, e ate o link "Editar dados gerais" desta mesma mudanca nao
// havia caminho de UI ate a exclusao. A zona fica aqui de proposito, pra quem
// edita o quiz nao ter de sair da tela pra apagar.
$isArchived            = (int) ($activity['course_archived'] ?? 0) === 1;
$activityName          = (string) $activity['title'];
$deleteCounts          = Activity::countForDelete($activityId);
$deleteCountsFormatted = format_delete_counts([
    'submissions' => $deleteCounts['submissions'],
    'xp_events'   => $deleteCounts['xp_events'],
]);

$page_title = __t('quiz.form.title', ['name' => $ownerName]);
ob_start();
require LMS_ROOT . '/src/templates/partials/teacher_quiz_form.php';
?>

<?php if (!$isArchived): ?>
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
                        data-counts="<?= e(json_encode($deleteCountsFormatted, JSON_UNESCAPED_UNICODE)) ?>"
                        data-return-url="/teacher/activity/<?= $activityId ?>/quiz">
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
