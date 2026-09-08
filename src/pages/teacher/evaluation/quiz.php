<?php
declare(strict_types=1);

/**
 * /teacher/evaluation/{id}/quiz — form de criar/editar quiz da avaliação
 * (E20-02). Validação ownership + delega pra TeacherQuizController.
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
$ctx = TeacherQuizController::loadOwner('evaluation', $evaluationId, $tenantId);
if ($ctx === null) {
    http_response_code(404);
    require LMS_ROOT . '/src/templates/errors/404.php';
    return;
}

$evaluation = $ctx['owner'];
$courseId   = $ctx['course_id'];
$ownerName  = $ctx['owner_name'];

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_verify();
    } catch (RuntimeException) {
        flash('danger', __t('auth.forbidden'));
        header('Location: /teacher/evaluation/' . $evaluationId . '/quiz', true, 303);
        exit;
    }

    $v = TeacherQuizController::validate($_POST);
    if ($v['errors'] === []) {
        TeacherQuizController::saveBulk('evaluation', $evaluationId, $tenantId, $v['data']);
        flash('success', __t('quiz.form.saved'));
        // E23-02: redirect pra CU pai (era a mesma URL — fluxo concluído deve
        // levar de volta ao ponto de retorno natural do professor).
        header('Location: /teacher/cu/' . (int) $evaluation['competence_unit_id'], true, 303);
        exit;
    }
    $errors = $v['errors'];
    // Re-renderiza com o input cru re-injetado no Alpine state.
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
    // Carrega quiz existente (se houver) pra preencher o form.
    $quiz = Quiz::findByOwner('evaluation', $evaluationId, $tenantId);
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

$formAction  = '/teacher/evaluation/' . $evaluationId . '/quiz';
$cancelUrl   = '/teacher/cu/' . (int) $evaluation['competence_unit_id'];
$settingsUrl = '/teacher/evaluation/' . $evaluationId . '/edit';

// A lista da CU manda avaliacao do tipo quiz direto pra esta tela — era o
// unico link, e ela so editava as questoes: nao havia caminho de UI nenhum ate
// a exclusao (o bug que o PO reportou). O link "Editar dados gerais" acima
// resolve o acesso; a zona aqui existe porque quem esta trabalhando no quiz
// nao deveria ter de navegar pra outra tela pra apagar. Sao duas portas pro
// mesmo /delete, como em qualquer tela de edicao do objeto — deliberado.
$isArchived            = (int) ($evaluation['course_archived'] ?? 0) === 1;
$evaluationName        = (string) $evaluation['title'];
$deleteCounts          = Evaluation::countForDelete($evaluationId);
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
            <h2 class="h6 mb-2 text-danger"><?= e(__t('evaluations.delete.zone')) ?></h2>
            <p class="small text-muted mb-3">
                <?= e(__t('evaluations.delete.warning')) ?>
            </p>
            <div>
                <button type="button" class="btn btn-outline-danger"
                        data-bs-toggle="modal" data-bs-target="#deleteConfirmModal"
                        data-item-name="<?= e($evaluationName) ?>"
                        data-action-url="/teacher/evaluation/<?= $evaluationId ?>/delete"
                        data-counts="<?= e(json_encode($deleteCountsFormatted, JSON_UNESCAPED_UNICODE)) ?>"
                        data-return-url="/teacher/evaluation/<?= $evaluationId ?>/quiz">
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
