<?php
declare(strict_types=1);

/**
 * Handler pro quiz na tela do professor `/teacher/evaluation/{id}/submission/{student_id}`
 * quando `evaluation.type='quiz'` (E20-06). Inclui-se de submission.php
 * via require + return.
 *
 * Diferenças do fluxo de projeto:
 *  - Nota já foi calculada automaticamente no submit do aluno (E20-04).
 *    Professor não digita nota nem feedback — apenas decide se libera retry.
 *  - Form simplificado com toggle `retry_allowed` + botão Salvar.
 *  - Render do snapshot read-only (com gabarito sempre — professor sempre vê).
 *
 * Espera no escopo (vindas de submission.php):
 *   $evaluationId int
 *   $studentId    int
 *   $tenantId     int
 *   $evaluation   array
 *   $student      array
 *   $current      array — submission corrente (com quiz_snapshot)
 */

$errors = [];
$old = [
    'retry_allowed' => (int) ($current['retry_allowed'] ?? 0) === 1,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_verify();
    } catch (RuntimeException) {
        flash('danger', __t('auth.forbidden'));
        header('Location: /teacher/evaluation/' . $evaluationId . '/submission/' . $studentId);
        return;
    }

    $newRetry  = isset($_POST['retry_allowed']) ? 1 : 0;
    $grade     = (float) ($current['grade'] ?? 0);
    $oldRetry  = (int) ($current['retry_allowed'] ?? 0);

    // grade ≥ 6 força retry=0 (aprovado não reenvia — mesma regra de projeto).
    if ($grade >= 6.0 && $newRetry === 1) {
        $newRetry = 0;
    }

    try {
        Database::pdo()->prepare(
            'UPDATE evaluation_submissions
                SET retry_allowed = ?
              WHERE id = ?'
        )->execute([$newRetry, (int) $current['id']]);
    } catch (\Throwable) {
        flash('danger', __t('evaluations.grade.err.generic'));
        header('Location: /teacher/evaluation/' . $evaluationId . '/submission/' . $studentId);
        return;
    }

    // Notif retry_enabled — apenas quando vira 1 (transição off→on).
    if ($newRetry === 1 && $oldRetry !== 1) {
        $courseId = (int) $evaluation['course_id'];
        $evalLink = '/student/evaluation/' . $evaluationId;
        try {
            NotificationService::fanout(
                NotificationService::EVENT_RETRY_ENABLED,
                [$studentId],
                (string) $evaluation['title'],
                null,
                $evalLink,
                $courseId
            );
        } catch (\Throwable) {
            // best-effort
        }
    }

    flash('success', __t('quiz.teacher.saved'));
    header('Location: /teacher/evaluation/' . $evaluationId . '/submission/' . $studentId, true, 303);
    exit;
}

// Render do snapshot pra reusar a partial student_quiz_form.
$snapshotJson = (string) ($current['quiz_snapshot'] ?? '');
$snapshot     = $snapshotJson !== '' ? json_decode($snapshotJson, true) : null;

$page_title = __t('quiz.teacher.title', ['name' => (string) $evaluation['title']]);

ob_start();
?>
<?= breadcrumbs([
    ['label' => (string) $evaluation['title'],      'url' => '/teacher/evaluation/' . $evaluationId . '/edit'],
    ['label' => __t('evaluations.grade.page_title', ['name' => (string) $student['name']])],
]) ?>

<div class="row justify-content-center">
    <div class="col-12 col-lg-9">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
            <div>
                <h1 class="h4 mb-1"><?= e((string) $evaluation['title']) ?></h1>
                <small class="text-muted">
                    <?= e((string) $student['name']) ?> · <?= e((string) $student['email']) ?>
                </small>
            </div>
            <div class="text-end">
                <div class="text-muted small"><?= e(__t('quiz.teacher.auto_grade')) ?></div>
                <div class="h3 mb-0 <?= ($current['grade'] ?? 0) >= 6 ? 'text-success' : 'text-danger' ?>">
                    <?= e(number_format((float) ($current['grade'] ?? 0), 1, ',', '')) ?> / 10
                </div>
            </div>
        </div>

        <?php if (is_array($snapshot)): ?>
            <?php
                // Reusa a partial do aluno em modo readOnly+show_answers
                // forçado pra professor sempre ver as respostas certas.
                $fullQuiz       = $snapshot;
                $fullQuiz['show_answers'] = 1;
                $previousAnswers = (array) ($snapshot['answers'] ?? []);
                $errors          = [];
                $readOnly        = true;
                $formAction      = null;
                $titleText       = (string) $evaluation['title'];
                require LMS_ROOT . '/src/templates/partials/student_quiz_form.php';
            ?>
        <?php else: ?>
            <div class="alert alert-warning"><?= e(__t('quiz.teacher.no_snapshot')) ?></div>
        <?php endif; ?>

        <div class="card card-body shadow-sm mt-3">
            <h2 class="h6 mb-3"><?= e(__t('quiz.teacher.actions_title')) ?></h2>

            <?php if (((float) ($current['grade'] ?? 0)) >= 6.0): ?>
                <div class="alert alert-success small mb-3">
                    <?= e(__t('quiz.teacher.approved_note')) ?>
                </div>
            <?php else: ?>
                <form method="POST" action="/teacher/evaluation/<?= $evaluationId ?>/submission/<?= $studentId ?>" novalidate>
                    <?= csrf_field() ?>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="f-retry"
                               name="retry_allowed" value="1"
                               <?= $old['retry_allowed'] ? 'checked' : '' ?>>
                        <label class="form-check-label" for="f-retry">
                            <?= e(__t('quiz.teacher.retry_allowed')) ?>
                        </label>
                        <div class="form-text"><?= e(__t('quiz.teacher.retry_hint')) ?></div>
                    </div>
                    <button type="submit" class="btn btn-primary"><?= e(__t('common.save')) ?></button>
                </form>
            <?php endif; ?>
        </div>

        <div class="mt-3">
            <a href="/teacher/evaluation/<?= $evaluationId ?>/submissions" class="btn btn-outline-secondary">
                <?= e(__t('common.back')) ?>
            </a>
        </div>
    </div>
</div>
<?php
$page_content = ob_get_clean();
require LMS_ROOT . '/src/templates/layout.php';
