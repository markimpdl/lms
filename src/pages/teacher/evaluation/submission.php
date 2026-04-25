<?php
declare(strict_types=1);

/**
 * /teacher/evaluation/{id}/submission/{student_id} — correção de avaliação
 * pelo professor (E7-03).
 *
 * GET mostra avaliação + aluno + submissão corrente + histórico + form.
 * POST grava nota, feedback, retry_allowed via `EvaluationSubmissionService::grade`.
 *
 * Regras aplicadas no service (clamp no backend, espelhado no front):
 *  - grade ≥ 6 → força retry_allowed = 0 (aprovado não reenvia)
 *  - grade ≥ 8 → credita XP (idempotente)
 */

$tenantId = current_tenant_id();
if ($tenantId === null) {
    http_response_code(403);
    require LMS_ROOT . '/src/templates/errors/403.php';
    return;
}

$evaluationId = (int) ($_REQUEST['id']         ?? 0);
$studentId    = (int) ($_REQUEST['student_id'] ?? 0);

$ctx = EvaluationSubmission::findForGrading($evaluationId, $studentId, $tenantId);
if ($ctx === null) {
    http_response_code(404);
    require LMS_ROOT . '/src/templates/errors/404.php';
    return;
}

$evaluation = $ctx['evaluation'];
$student    = $ctx['student'];
$current    = $ctx['current'];
$history    = $ctx['history'];

if ($current === null) {
    http_response_code(404);
    require LMS_ROOT . '/src/templates/errors/404.php';
    return;
}

$old = [
    'grade'         => $current['grade']         !== null ? (string) $current['grade']   : '',
    'feedback'      => $current['feedback']      !== null ? (string) $current['feedback'] : '',
    'retry_allowed' => (int) ($current['retry_allowed'] ?? 0) === 1,
];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_verify();
    } catch (RuntimeException) {
        flash('danger', __t('auth.forbidden'));
        header('Location: /teacher/evaluation/' . $evaluationId . '/submission/' . $studentId);
        return;
    }

    $rawGrade    = trim((string) ($_POST['grade']    ?? ''));
    $rawFeedback = trim((string) ($_POST['feedback'] ?? ''));
    $rawRetry    = isset($_POST['retry_allowed']);

    $old = [
        'grade'         => $rawGrade,
        'feedback'      => $rawFeedback,
        'retry_allowed' => $rawRetry,
    ];

    $normalized = str_replace(',', '.', $rawGrade);
    if ($normalized === '' || !is_numeric($normalized)) {
        $errors['grade'] = 'evaluations.grade.err.grade_required';
    } else {
        $gradeVal = (float) $normalized;
        if ($gradeVal < 0.0 || $gradeVal > 10.0) {
            $errors['grade'] = 'evaluations.grade.err.grade_range';
        }
    }

    if ($rawFeedback === '') {
        $errors['feedback'] = 'evaluations.grade.err.feedback_required';
    } elseif (mb_strlen($rawFeedback) > 4000) {
        $errors['feedback'] = 'evaluations.grade.err.feedback_too_long';
    }

    if ($errors === []) {
        $gradeVal = (float) $normalized;
        $result = EvaluationSubmissionService::grade(
            (int) $current['id'],
            $tenantId,
            $gradeVal,
            $rawFeedback,
            $rawRetry
        );

        if ($result['status'] === 'ok') {
            // Conquistas (E18-04). Best-effort.
            try {
                AchievementsService::evaluateForEvent(
                    $studentId, $tenantId, 'evaluation_graded',
                    ['grade' => $gradeVal]
                );
                AchievementsService::evaluateForEvent($studentId, $tenantId, 'rank_first_promotion');
                student_progression_check($studentId, $tenantId, (int) $evaluation['cu_id']);
            } catch (\Throwable) {
                // swallow
            }

            // Fanouts E10-04 — 1 destinatário (aluno autor da submissão). Idioma
            // do email via `courses.language` usando courseId da avaliação.
            $courseId = (int) $evaluation['course_id'];
            $evalLink = '/student/evaluation/' . $evaluationId;

            NotificationService::fanout(
                'grade_evaluation',
                [$studentId],
                (string) $evaluation['title'],
                null,
                $evalLink,
                $courseId
            );

            if ((int) $result['retry_effective'] === 1) {
                NotificationService::fanout(
                    'retry_enabled',
                    [$studentId],
                    (string) $evaluation['title'],
                    null,
                    $evalLink,
                    $courseId
                );
            }

            $msgKey = 'evaluations.grade.saved';
            if ($rawRetry && $result['retry_effective'] === 0) {
                $msgKey = 'evaluations.grade.saved_retry_clamped';
            } elseif (!empty($result['xp_awarded'])) {
                $msgKey = 'evaluations.grade.saved_xp';
            }
            flash('success', __t($msgKey, ['name' => (string) $student['name']]));
            header('Location: /teacher/evaluation/' . $evaluationId . '/submission/' . $studentId, true, 303);
            return;
        }

        if ($result['status'] === 'not_current') {
            flash('danger', __t('evaluations.grade.err.not_current'));
            header('Location: /teacher/evaluation/' . $evaluationId . '/submission/' . $studentId);
            return;
        }

        http_response_code(404);
        require LMS_ROOT . '/src/templates/errors/404.php';
        return;
    }
}

$page_title = __t('evaluations.grade.page_title', ['name' => (string) $student['name']]);

ob_start();
?>
<div class="row justify-content-center">
    <div class="col-12 col-lg-10">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
            <div>
                <h1 class="h4 mb-1">
                    <?= e(__t('evaluations.grade.page_title', ['name' => (string) $student['name']])) ?>
                </h1>
                <small class="text-muted">
                    <?= e((string) $evaluation['title']) ?>
                    · <?= e((string) $evaluation['course_name']) ?>
                    · <?= e((string) $evaluation['cu_name']) ?>
                </small>
            </div>
            <a href="/teacher/evaluation/<?= $evaluationId ?>/edit" class="btn btn-sm btn-outline-secondary">
                <?= e(__t('common.back')) ?>
            </a>
        </div>

        <!-- Metadados da submissão corrente -->
        <div class="card shadow-sm mb-3">
            <div class="card-header d-flex align-items-center justify-content-between gap-2 flex-wrap">
                <h2 class="h6 mb-0">
                    <?= e(__t('evaluations.grade.current_submission', ['n' => (string) $current['attempt']])) ?>
                </h2>
                <?php if ($current['feedback_at'] !== null): ?>
                    <span class="badge text-bg-success">
                        <?= e(__t('evaluations.grade.already_graded')) ?>
                    </span>
                <?php else: ?>
                    <span class="badge text-bg-warning">
                        <?= e(__t('evaluations.grade.awaiting')) ?>
                    </span>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <dl class="row mb-0 small">
                    <dt class="col-4 col-md-3"><?= e(__t('evaluations.grade.student')) ?></dt>
                    <dd class="col-8 col-md-9">
                        <?= e((string) $student['name']) ?>
                        <span class="text-muted">&lt;<?= e((string) $student['email']) ?>&gt;</span>
                    </dd>
                    <dt class="col-4 col-md-3"><?= e(__t('evaluations.grade.file')) ?></dt>
                    <dd class="col-8 col-md-9">
                        <a href="/teacher/evaluation/<?= $evaluationId ?>/submission/<?= (int) $current['id'] ?>/file">
                            <?= e((string) $current['filename']) ?>
                        </a>
                    </dd>
                    <dt class="col-4 col-md-3"><?= e(__t('evaluations.grade.sent_at')) ?></dt>
                    <dd class="col-8 col-md-9">
                        <?= e(substr((string) $current['created_at'], 0, 16)) ?>
                    </dd>
                </dl>
            </div>
        </div>

        <!-- Form de correção -->
        <?php if ($errors !== []): ?>
            <div class="alert alert-danger" role="alert">
                <?= e(__t('evaluations.grade.has_errors')) ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="/teacher/evaluation/<?= $evaluationId ?>/submission/<?= $studentId ?>"
              class="card card-body shadow-sm mb-3" novalidate>
            <?= csrf_field() ?>

            <div class="row g-3">
                <div class="col-12 col-md-4 mb-3">
                    <label for="f-grade" class="form-label"><?= e(__t('evaluations.grade.field_grade')) ?></label>
                    <input type="number" name="grade" id="f-grade"
                           class="form-control form-control-lg<?= isset($errors['grade']) ? ' is-invalid' : '' ?>"
                           value="<?= e((string) $old['grade']) ?>"
                           min="0" max="10" step="0.1" required>
                    <div class="form-text">
                        <?= e(__t('evaluations.grade.field_grade_hint')) ?>
                    </div>
                    <?php if (isset($errors['grade'])): ?>
                        <div class="invalid-feedback"><?= e(__t($errors['grade'])) ?></div>
                    <?php endif; ?>
                </div>
                <div class="col-12 col-md-8 mb-3 d-flex align-items-end">
                    <div class="form-check" id="retry-group">
                        <input class="form-check-input" type="checkbox" id="f-retry" name="retry_allowed" value="1"
                               <?= $old['retry_allowed'] ? 'checked' : '' ?>>
                        <label class="form-check-label" for="f-retry">
                            <?= e(__t('evaluations.grade.field_retry')) ?>
                        </label>
                        <div class="form-text"><?= e(__t('evaluations.grade.field_retry_hint')) ?></div>
                    </div>
                </div>
            </div>

            <div class="mb-3">
                <label for="f-feedback" class="form-label"><?= e(__t('evaluations.grade.field_feedback')) ?></label>
                <textarea name="feedback" id="f-feedback" rows="8" maxlength="4000"
                          class="form-control<?= isset($errors['feedback']) ? ' is-invalid' : '' ?>"
                          required><?= e((string) $old['feedback']) ?></textarea>
                <div class="form-text"><?= e(__t('evaluations.grade.field_feedback_hint')) ?></div>
                <?php if (isset($errors['feedback'])): ?>
                    <div class="invalid-feedback"><?= e(__t($errors['feedback'])) ?></div>
                <?php endif; ?>
            </div>

            <div class="alert alert-info small mb-3">
                <?= e(__t('evaluations.grade.rules')) ?>
            </div>

            <div class="d-flex flex-wrap gap-2">
                <button type="submit" class="btn btn-primary btn-lg">
                    <?= e(__t('evaluations.grade.submit')) ?>
                </button>
                <a href="/teacher/evaluation/<?= $evaluationId ?>/edit" class="btn btn-outline-secondary btn-lg">
                    <?= e(__t('common.cancel')) ?>
                </a>
            </div>
        </form>

        <!-- Histórico de tentativas anteriores -->
        <?php if ($history !== []): ?>
            <div class="card shadow-sm">
                <div class="card-header">
                    <h2 class="h6 mb-0"><?= e(__t('evaluations.grade.history')) ?></h2>
                </div>
                <ul class="list-group list-group-flush">
                    <?php foreach ($history as $h): ?>
                        <li class="list-group-item">
                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                <span class="fw-semibold">
                                    <?= e(__t('evaluations.grade.attempt_n', ['n' => (string) $h['attempt']])) ?>
                                </span>
                                <small class="text-muted flex-grow-1">
                                    <?= e(substr((string) $h['created_at'], 0, 16)) ?>
                                    <?php if ($h['grade'] !== null): ?>
                                        · <?= e(__t('evaluations.grade.grade_label')) ?>:
                                        <strong><?= e(number_format((float) $h['grade'], 1, ',', '')) ?></strong>
                                    <?php endif; ?>
                                </small>
                                <a href="/teacher/evaluation/<?= $evaluationId ?>/submission/<?= (int) $h['id'] ?>/file"
                                   class="btn btn-sm btn-outline-secondary">
                                    <?= e((string) $h['filename']) ?>
                                </a>
                            </div>
                            <?php if ($h['feedback'] !== null && $h['feedback'] !== ''): ?>
                                <p class="mt-2 mb-0 small" style="white-space: pre-wrap;">
                                    <?= e((string) $h['feedback']) ?>
                                </p>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
// Clamp visual: quando grade >= 6, retry_allowed some/desabilita (o backend
// re-aplica o clamp independente do que vier do form — fonte única de verdade).
(function () {
    var grade = document.getElementById('f-grade');
    var retry = document.getElementById('f-retry');
    var group = document.getElementById('retry-group');
    if (!grade || !retry || !group) return;

    function sync() {
        var raw = grade.value.replace(',', '.');
        var n = parseFloat(raw);
        var approved = !Number.isNaN(n) && n >= 6;
        group.style.opacity = approved ? '0.5' : '1';
        retry.disabled = approved;
        if (approved) retry.checked = false;
    }

    grade.addEventListener('input', sync);
    sync();
})();
</script>
<?php
$page_content = ob_get_clean();
require LMS_ROOT . '/src/templates/layout.php';
