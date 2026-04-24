<?php
declare(strict_types=1);

/**
 * /teacher/activity/{id}/submission/{student_id} — GET: tela de correção
 * com instrução + arquivo/código do aluno + form de feedback.
 * POST: grava feedback + feedback_at + dispara fanout de notification stub.
 */

$tenantId = current_tenant_id();
if ($tenantId === null) {
    http_response_code(403);
    require LMS_ROOT . '/src/templates/errors/403.php';
    return;
}

$activityId = (int) ($_REQUEST['id'] ?? 0);
$studentId  = (int) ($_REQUEST['student_id'] ?? 0);

$ctx = ActivitySubmission::findForTeacher($activityId, $studentId, $tenantId);
if ($ctx === null) {
    http_response_code(404);
    require LMS_ROOT . '/src/templates/errors/404.php';
    return;
}

$activity   = $ctx['activity'];
$submission = $ctx['submission'];
$student    = $ctx['student'];
$oldFeedback = (string) ($submission['feedback'] ?? '');
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_verify();
    } catch (RuntimeException) {
        flash('danger', __t('auth.forbidden'));
        header('Location: /teacher/activity/' . $activityId . '/submission/' . $studentId);
        return;
    }

    $oldFeedback = trim((string) ($_POST['feedback'] ?? ''));
    if ($oldFeedback === '') {
        $errors['feedback'] = 'submissions.teacher.err.empty';
    } elseif (mb_strlen($oldFeedback) > 4000) {
        $errors['feedback'] = 'submissions.teacher.err.too_long';
    }

    if ($errors === []) {
        ActivitySubmission::saveFeedback($activityId, $studentId, $oldFeedback);

        // Fanout via NotificationService (E10-00). courseId fica null por
        // ora — `findForTeacher` só retorna cu_id; E10-03 adiciona o id do
        // curso ao retorno quando o email for cabeado, pra resolver o
        // idioma via `courses.language`.
        NotificationService::fanout(
            'activity_feedback',
            [$studentId],
            (string) $activity['title'],
            __t('submissions.teacher.notification_body'),
            '/student/activity/' . $activityId
        );

        flash('success', __t('submissions.teacher.feedback_saved', ['name' => (string) $student['name']]));
        header('Location: /teacher/activity/' . $activityId . '/submissions', true, 303);
        return;
    }
}

$cuId   = (int) $activity['cu_id'];
$isCode = $activity['type'] === 'codigo';
$wasReviewed = $submission['feedback_at'] !== null;

$page_title = __t('submissions.teacher.review_title', ['name' => (string) $student['name']]);

ob_start();
?>
<div class="row justify-content-center">
    <div class="col-12 col-lg-10">
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

        <!-- Instrução da atividade (context readonly) -->
        <div class="card shadow-sm mb-3">
            <div class="card-header"><h2 class="h6 mb-0"><?= e(__t('submissions.teacher.instruction')) ?></h2></div>
            <div class="card-body content-render">
                <?= (string) $activity['instruction'] ?>
            </div>
        </div>

        <!-- Arquivo + código do aluno -->
        <div class="card shadow-sm mb-3">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <h2 class="h6 mb-0"><?= e(__t('submissions.teacher.submission_title')) ?></h2>
                <small class="text-muted">
                    <?= e(__t('submissions.teacher.submitted_at', ['date' => substr((string) $submission['created_at'], 0, 16)])) ?>
                </small>
            </div>
            <div class="card-body">
                <?php if ($submission['filename'] !== null): ?>
                    <p class="mb-2">
                        <strong><?= e(__t('submissions.form.file')) ?>:</strong>
                        <a href="/teacher/activity/<?= $activityId ?>/submission/<?= (int) $student['id'] ?>/file">
                            <?= e((string) $submission['filename']) ?>
                        </a>
                    </p>
                <?php endif; ?>
                <?php if ($submission['code_text'] !== null): ?>
                    <p class="mb-1 small text-muted"><?= e(__t('submissions.form.code')) ?>:</p>
                    <pre class="bg-light p-3 rounded small mb-0" style="max-height: 400px; overflow: auto;"><?= e((string) $submission['code_text']) ?></pre>
                <?php endif; ?>
                <?php if ($submission['filename'] === null && $submission['code_text'] === null): ?>
                    <p class="text-muted mb-0"><?= e(__t('submissions.teacher.empty_submission')) ?></p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Form de feedback -->
        <?php if ($errors !== []): ?>
            <div class="alert alert-danger" role="alert">
                <?php foreach ($errors as $key): ?>
                    <div><?= e(__t($key)) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="/teacher/activity/<?= $activityId ?>/submission/<?= (int) $student['id'] ?>"
              class="card card-body shadow-sm" novalidate>
            <?= csrf_field() ?>

            <label for="f-feedback" class="form-label">
                <?= e(__t($wasReviewed ? 'submissions.teacher.feedback_edit' : 'submissions.teacher.feedback_new')) ?>
            </label>
            <textarea id="f-feedback" name="feedback" rows="8"
                      class="form-control<?= isset($errors['feedback']) ? ' is-invalid' : '' ?>"
                      maxlength="4000" required><?= e($oldFeedback) ?></textarea>
            <div class="form-text">
                <?= e(__t('submissions.teacher.feedback_hint')) ?>
                <?php if ($wasReviewed): ?>
                    · <?= e(__t('submissions.teacher.feedback_at', ['date' => substr((string) $submission['feedback_at'], 0, 16)])) ?>
                <?php endif; ?>
            </div>

            <div class="d-flex gap-2 flex-wrap mt-3">
                <button type="submit" class="btn btn-primary btn-lg">
                    <?= e(__t('common.save')) ?>
                </button>
                <a href="/teacher/activity/<?= $activityId ?>/submissions" class="btn btn-outline-secondary btn-lg">
                    <?= e(__t('common.cancel')) ?>
                </a>
            </div>
        </form>
    </div>
</div>
<?php
$page_content = ob_get_clean();
require LMS_ROOT . '/src/templates/layout.php';
