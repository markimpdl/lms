<?php
declare(strict_types=1);

/**
 * Handler pro quiz na tela do professor `/teacher/activity/{id}/submission/{student_id}`
 * quando `activity.type='quiz'` (fix #280). Inclui-se de submission-review.php
 * via require + return.
 *
 * Atividade-quiz e auto-corrigida: nota auto-calculada via QuizSubmissionService
 * e feedback_at=NOW() ja gravado no submit do aluno. Professor so visualiza
 * o snapshot (com gabarito sempre) — sem form de feedback.
 *
 * Espera no escopo (vindas de submission-review.php):
 *   $activityId int
 *   $studentId  int
 *   $tenantId   int
 *   $activity   array
 *   $submission array — com quiz_snapshot
 *   $student    array
 */

$snapshotJson = (string) ($submission['quiz_snapshot'] ?? '');
$snapshot     = $snapshotJson !== '' ? json_decode($snapshotJson, true) : null;
$grade        = is_array($snapshot) ? QuizSubmissionService::calculateGrade($snapshot) : null;

$page_title = __t('submissions.teacher.review_title', ['name' => (string) $student['name']]);

ob_start();
?>
<div class="row justify-content-center">
    <div class="col-12 col-lg-9">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
            <div>
                <h1 class="h4 mb-1"><?= e((string) $student['name']) ?></h1>
                <small class="text-muted">
                    <?= e((string) $student['email']) ?> ·
                    <?= e((string) $activity['title']) ?>
                </small>
            </div>
            <a href="/teacher/activity/<?= $activityId ?>/submissions" class="btn btn-sm btn-outline-secondary">
                <?= e(__t('common.back')) ?>
            </a>
        </div>

        <?php if ($grade !== null): ?>
            <div class="card card-body shadow-sm mb-3">
                <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                    <div>
                        <strong><?= e(__t('quiz.teacher.auto_grade')) ?>:</strong>
                        <span class="h5 m-0 ms-2 <?= $grade >= 6 ? 'text-success' : '' ?>">
                            <?= e(number_format($grade, 1, ',', '')) ?> / 10
                        </span>
                    </div>
                    <small class="text-muted">
                        <?= e(__t('submissions.teacher.submitted_at', ['date' => substr((string) $submission['created_at'], 0, 16)])) ?>
                    </small>
                </div>
                <p class="text-muted small mb-0 mt-2">
                    <?= e(__t('quiz.teacher.activity_no_feedback_note')) ?>
                </p>
            </div>
        <?php endif; ?>

        <?php if (is_array($snapshot)): ?>
            <?php
                // Reusa partial student_quiz_form em modo readOnly + gabarito sempre
                // visivel pro professor.
                $fullQuiz       = $snapshot;
                $fullQuiz['show_answers'] = 1;
                $previousAnswers = (array) ($snapshot['answers'] ?? []);
                $errors          = [];
                $readOnly        = true;
                $formAction      = null;
                $titleText       = (string) $activity['title'];
                require LMS_ROOT . '/src/templates/partials/student_quiz_form.php';
            ?>
        <?php else: ?>
            <div class="alert alert-warning"><?= e(__t('quiz.teacher.no_snapshot')) ?></div>
        <?php endif; ?>
    </div>
</div>
<?php
$page_content = ob_get_clean();
require LMS_ROOT . '/src/templates/layout.php';
