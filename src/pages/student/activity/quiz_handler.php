<?php
declare(strict_types=1);

/**
 * Handler do quiz pro aluno em /student/activity/{id} quando
 * `activity.type='quiz'` (E20-03 + E20-04). Inclui-se de show.php
 * via require + return — não é uma rota independente.
 *
 * Espera no escopo (vindas de show.php):
 *   $studentId  int
 *   $tenantId   int
 *   $activityId int
 *   $activity   array — fetched via ActivitySubmission::findForStudentActivity
 *   $courseId   int
 *   $cuId       int
 *   $ctx        array com 'submission' (já carregada)
 *
 * Comportamento:
 *  - Atividade-quiz: entregar = ganha XP (sem retry; nota é informativa).
 *  - Sem submissão: renderiza form pra responder.
 *  - Com submissão: renderiza snapshot read-only (sem permitir alterar).
 */

$existingSub = $ctx['submission'] ?? null;
$readOnly    = $existingSub !== null;

$fullQuiz = Quiz::findByOwner('activity', $activityId, $tenantId);
$fullQuiz = $fullQuiz === null ? null : Quiz::findFullById((int) $fullQuiz['id'], $tenantId);

if ($fullQuiz === null || ($fullQuiz['questions'] ?? []) === []) {
    flash('warning', __t('quiz.student.not_ready'));
    header('Location: /student/cu/' . $cuId, true, 303);
    exit;
}

$errors          = [];
$previousAnswers = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$readOnly) {
    try {
        csrf_verify();
    } catch (RuntimeException) {
        flash('danger', __t('auth.forbidden'));
        header('Location: /student/activity/' . $activityId, true, 303);
        exit;
    }

    if ((int) $activity['submission_open'] !== 1) {
        flash('danger', __t('activities.student.err.closed'));
        header('Location: /student/activity/' . $activityId, true, 303);
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

        try {
            // Atividade-quiz e auto-corrigida: feedback_at=NOW() marca a
            // submissao como ja-revisada (a propria nota e o feedback) — assim
            // a UI nao mostra "aguardando correcao" pro aluno e o professor
            // nao precisa abrir feedback form. ADR-027 diz "feedback_at IS NULL
            // = aluno pode editar"; aqui forcamos NOW pra travar (auto-graded).
            Database::pdo()->prepare(
                'INSERT INTO activity_submissions
                    (activity_id, student_user_id, filename, stored_path,
                     code_text, quiz_snapshot, feedback_at)
                 VALUES (?, ?, NULL, NULL, NULL, ?, NOW())'
            )->execute([
                $activityId,
                $studentId,
                json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        } catch (\Throwable) {
            flash('danger', __t('activities.student.err.generic'));
            header('Location: /student/activity/' . $activityId, true, 303);
            exit;
        }

        // XP por entregar (ADR-002, igual atividade tipo projeto/codigo).
        try {
            XpEvents::awardActivity($studentId, $activityId, $tenantId, $courseId);
        } catch (\Throwable) {
            // best-effort
        }

        // Conquistas (E18). activity_submitted + cascade de progressão
        // via student_progression_check (uc/cc/course completed).
        try {
            AchievementsService::evaluateForEvent($studentId, $tenantId, 'activity_submitted');
            student_progression_check($studentId, $tenantId, $cuId);
        } catch (\Throwable) {
            // swallow
        }

        flash('success', __t('quiz.student.submitted', ['grade' => number_format($grade, 1, ',', '')]));
        header('Location: /student/activity/' . $activityId, true, 303);
        exit;
    }
}

// Read-only mode: usa snapshot da submissão pra render.
$gradeShown = null;
if ($readOnly) {
    $snapshotJson = (string) ($existingSub['quiz_snapshot'] ?? '');
    $snapshot     = $snapshotJson !== '' ? json_decode($snapshotJson, true) : null;
    if (is_array($snapshot)) {
        $fullQuiz        = $snapshot;
        $previousAnswers = (array) ($snapshot['answers'] ?? []);
        $gradeShown      = QuizSubmissionService::calculateGrade($snapshot);
    }
}

$page_title = (string) $activity['title'];

ob_start();
?>
<?= breadcrumbs([
    ['label' => __t('dashboard.student.title'),  'url' => '/student'],
    ['label' => (string) $activity['course_name'], 'url' => '/student/course/' . $courseId],
    ['label' => (string) $activity['cu_name'],     'url' => '/student/cu/' . $cuId],
    ['label' => (string) $activity['title']],
]) ?>

<div class="row">
    <div class="col-12">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
            <div>
                <h1 class="h4 mb-1"><?= e((string) $activity['title']) ?></h1>
                <small class="text-muted">
                    <?= (int) $activity['xp_value'] ?> XP ·
                    <?= e(__t('quiz.student.type_label')) ?>
                </small>
            </div>
            <?php if ($gradeShown !== null): ?>
                <div class="text-end">
                    <div class="text-muted small"><?= e(__t('quiz.student.your_grade')) ?></div>
                    <div class="h3 mb-0">
                        <?= e(number_format($gradeShown, 1, ',', '')) ?> / 10
                    </div>
                    <div class="text-muted small"><?= e(__t('quiz.student.activity_grade_note')) ?></div>
                </div>
            <?php endif; ?>
        </div>

        <?php
            $titleText  = (string) $activity['title'];
            $formAction = '/student/activity/' . $activityId;
            require LMS_ROOT . '/src/templates/partials/student_quiz_form.php';
        ?>
    </div>
</div>
<?php
$page_content = ob_get_clean();
require LMS_ROOT . '/src/templates/layout.php';
