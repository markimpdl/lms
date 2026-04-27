<?php
declare(strict_types=1);

/**
 * Handler do quiz pro aluno em /student/evaluation/{id} quando
 * `evaluation.type='quiz'` (E20-03 + E20-04). Inclui-se de show.php
 * via require + return — não é uma rota independente.
 *
 * Espera no escopo (vindas de show.php):
 *   $studentId    int
 *   $tenantId     int
 *   $evaluationId int
 *   $evaluation   array — fetched via EvaluationSubmission::findForStudentEvaluation
 *   $current      array|null — submission corrente do aluno ou null
 *
 * Fluxo:
 *  - Se aluno já submeteu: renderiza snapshot read-only + nota + status.
 *  - GET sem submission: carrega quiz via Quiz::findFullById e renderiza form.
 *  - POST: valida respostas, monta snapshot, calcula nota, INSERT em
 *    evaluation_submissions com grade + quiz_snapshot. Se grade ≥ 8 dispara
 *    XP via XpEvents::awardEvaluation. Best-effort hooks de conquistas.
 */

$courseId = (int) $evaluation['course_id'];
$cuId     = (int) $evaluation['cu_id'];
$evType   = (string) ($evaluation['type'] ?? 'projeto');

$fullQuiz = Quiz::findByOwner('evaluation', $evaluationId, $tenantId);
$fullQuiz = $fullQuiz === null ? null : Quiz::findFullById((int) $fullQuiz['id'], $tenantId);

if ($fullQuiz === null || ($fullQuiz['questions'] ?? []) === []) {
    flash('warning', __t('quiz.student.not_ready'));
    header('Location: /student/cu/' . $cuId, true, 303);
    exit;
}

$errors          = [];
$previousAnswers = [];

// E20-06: retry pelo professor. Quando $current['retry_allowed']=1, aluno
// pode refazer o quiz (gera nova attempt + novo snapshot). Caso contrário,
// view fica readOnly após primeiro submit.
$canRetry = $current !== null && (int) ($current['retry_allowed'] ?? 0) === 1;
$readOnly = $current !== null && !$canRetry;

// POST → submit + grade calc + snapshot. Permite re-submit quando retry_allowed.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$readOnly) {
    try {
        csrf_verify();
    } catch (RuntimeException) {
        flash('danger', __t('auth.forbidden'));
        header('Location: /student/evaluation/' . $evaluationId, true, 303);
        exit;
    }

    if ((int) $evaluation['submission_open'] !== 1) {
        flash('danger', __t('evaluations.student.err.closed'));
        header('Location: /student/evaluation/' . $evaluationId, true, 303);
        exit;
    }

    $rawAnswers = $_POST['answers'] ?? [];
    if (!is_array($rawAnswers)) {
        $rawAnswers = [];
    }
    $v = QuizSubmissionService::validateAnswers($fullQuiz, $rawAnswers);
    $errors          = $v['errors'];
    $previousAnswers = $v['answers'];

    if ($errors === []) {
        $snapshot = QuizSubmissionService::buildSnapshot($fullQuiz, $v['answers']);
        $grade    = QuizSubmissionService::calculateGrade($snapshot);

        // E20-06: nova attempt quando retry_allowed; primeira submissão = 1.
        $nextAttempt = $current === null ? 1 : ((int) $current['attempt'] + 1);

        try {
            Database::tx(static function () use (
                $evaluationId, $studentId, $tenantId, $snapshot, $grade, $nextAttempt
            ): void {
                $pdo = Database::pdo();
                // Hard-delete de submissão anterior (v0.29.0+: sem histórico
                // de tentativas). Quiz não tem arquivo físico (snapshot é JSON
                // inline), então não precisa de safe_unlink_storage. Cascade
                // limpa lo_grades automaticamente.
                $pdo->prepare(
                    'DELETE FROM evaluation_submissions
                      WHERE evaluation_id = ? AND student_user_id = ?'
                )->execute([$evaluationId, $studentId]);

                $pdo->prepare(
                    'INSERT INTO evaluation_submissions
                        (tenant_id, evaluation_id, student_user_id, attempt,
                         filename, stored_path, quiz_snapshot, grade,
                         feedback, feedback_at, retry_allowed)
                     VALUES (?, ?, ?, ?, NULL, NULL, ?, ?, NULL, NOW(), 0)'
                )->execute([
                    $tenantId,
                    $evaluationId,
                    $studentId,
                    $nextAttempt,
                    json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    $grade,
                ]);
            });
        } catch (\Throwable) {
            flash('danger', __t('evaluations.student.err.generic'));
            header('Location: /student/evaluation/' . $evaluationId, true, 303);
            exit;
        }

        // XP quando grade ≥ 8.0 (ADR-002, mesma regra de avaliação-projeto).
        if ($grade >= 8.0) {
            try {
                XpEvents::awardEvaluation($studentId, $evaluationId, $tenantId, $courseId);
            } catch (\Throwable) {
                // best-effort
            }
        }

        // Conquistas (E18). evaluation_submitted + evaluation_graded com grade.
        try {
            AchievementsService::evaluateForEvent($studentId, $tenantId, 'evaluation_submitted');
            AchievementsService::evaluateForEvent(
                $studentId, $tenantId, 'evaluation_graded',
                ['grade' => $grade]
            );
        } catch (\Throwable) {
            // swallow
        }

        flash('success', __t('quiz.student.submitted', ['grade' => number_format($grade, 1, ',', '')]));
        header('Location: /student/evaluation/' . $evaluationId, true, 303);
        exit;
    }
}

// Read-only mode: usa snapshot da submissão anterior pra render.
if ($readOnly) {
    $snapshotJson = (string) ($current['quiz_snapshot'] ?? '');
    $snapshot     = $snapshotJson !== '' ? json_decode($snapshotJson, true) : null;
    if (is_array($snapshot)) {
        $fullQuiz        = $snapshot; // mesma forma (questions[].options[])
        $previousAnswers = (array) ($snapshot['answers'] ?? []);
    }
    $gradeShown = $current['grade'] !== null ? (float) $current['grade'] : null;
} else {
    $gradeShown = null;
}

$page_title = (string) $evaluation['title'];

ob_start();
?>
<?= breadcrumbs([
    ['label' => __t('dashboard.student.title'),    'url' => '/student'],
    ['label' => (string) $evaluation['course_name'], 'url' => '/student/course/' . $courseId],
    ['label' => (string) $evaluation['cu_name'],     'url' => '/student/cu/' . $cuId],
    ['label' => (string) $evaluation['title']],
]) ?>

<div class="row">
    <div class="col-12">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
            <div>
                <h1 class="h4 mb-1"><?= e((string) $evaluation['title']) ?></h1>
                <small class="text-muted">
                    <?= (int) $evaluation['xp_value'] ?> XP ·
                    <?= e(__t('quiz.student.type_label')) ?>
                </small>
            </div>
            <?php if ($gradeShown !== null): ?>
                <div class="text-end">
                    <div class="text-muted small"><?= e(__t('quiz.student.your_grade')) ?></div>
                    <div class="h3 mb-0 <?= $gradeShown >= 6 ? 'text-success' : 'text-danger' ?>">
                        <?= e(number_format($gradeShown, 1, ',', '')) ?> / 10
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <?php
            $titleText  = (string) $evaluation['title'];
            $formAction = '/student/evaluation/' . $evaluationId;
            require LMS_ROOT . '/src/templates/partials/student_quiz_form.php';
        ?>
    </div>
</div>
<?php
$page_content = ob_get_clean();
require LMS_ROOT . '/src/templates/layout.php';
