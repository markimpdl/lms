<?php
declare(strict_types=1);

/**
 * /student/evaluation/{id} — aluno vê a avaliação da CU (E7-02).
 *
 * Regras:
 *  - Matrícula ativa (validada por `EvaluationSubmission::findForStudentEvaluation`).
 *  - Sempre mostra link para baixar o PDF do enunciado.
 *  - Form de upload aparece quando:
 *      (a) não tem tentativa e `submission_open=1`; ou
 *      (b) tem tentativa e `retry_allowed=1` (reenvio liberado na correção).
 *  - Placeholder de nota/feedback: fica vazio até E7-03 gravar.
 *
 * POST do mesmo caminho encaminha para o service `submit`.
 */

$user = current_user();
if ($user === null || ($user['role'] ?? '') !== 'student') {
    http_response_code(403);
    require LMS_ROOT . '/src/templates/errors/403.php';
    return;
}

$studentId    = (int) $user['id'];
$evaluationId = (int) ($_REQUEST['id'] ?? 0);

$ctx = EvaluationSubmission::findForStudentEvaluation($evaluationId, $studentId);
if ($ctx === null) {
    http_response_code(404);
    require LMS_ROOT . '/src/templates/errors/404.php';
    return;
}

$evaluation = $ctx['evaluation'];

// Gate de disponibilidade do curso (E17-03).
$availability = enrollment_access_status($studentId, (int) $evaluation['course_id']);
if (!$availability['available']) {
    flash('warning', $availability['message'] ?? __t('enrollment.unavailable.generic'));
    header('Location: /student', true, 303);
    exit;
}
$current    = $ctx['current'];
$tenantId   = (int) $evaluation['tenant_id'];
$isOpen     = (int) $evaluation['submission_open'] === 1;
$history    = EvaluationSubmission::listHistoryForStudent($evaluationId, $studentId);

$canSubmitNew    = $current === null && $isOpen;
$canResubmit     = $current !== null && (int) $current['retry_allowed'] === 1;
$canShowForm     = $canSubmitNew || $canResubmit;
$hasFeedback     = $current !== null && $current['feedback_at'] !== null;

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_verify();
    } catch (RuntimeException) {
        flash('danger', __t('auth.forbidden'));
        header('Location: /student/evaluation/' . $evaluationId);
        return;
    }

    if (!$canShowForm) {
        flash('danger', __t($canSubmitNew ? 'evaluations.student.err.generic' : 'evaluations.student.err.no_retry'));
        header('Location: /student/evaluation/' . $evaluationId);
        return;
    }

    $file = $_FILES['file'] ?? null;
    if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        $errors['file'] = 'evaluations.student.err.no_file';
    }

    $nextAttempt = ($current === null ? 0 : (int) $current['attempt']) + 1;
    $upload = null;

    if ($errors === []) {
        $upload = EvaluationSubmissionStorage::store(
            $file,
            $evaluationId,
            $studentId,
            $nextAttempt,
            $tenantId
        );
        if ($upload['status'] !== 'ok') {
            $errors['file'] = $upload['error_key'] ?? 'evaluations.student.err.generic';
        }
    }

    if ($errors === []) {
        $result = EvaluationSubmissionService::submit(
            $evaluationId,
            $tenantId,
            $studentId,
            (string) $upload['filename'],
            (string) $upload['stored_path']
        );

        if ($result['status'] === 'ok') {
            flash('success', __t($current === null ? 'evaluations.student.submitted' : 'evaluations.student.resubmitted'));
            header('Location: /student/evaluation/' . $evaluationId, true, 303);
            return;
        }

        if ($result['status'] === 'closed') {
            flash('danger', __t('evaluations.student.err.closed'));
        } elseif ($result['status'] === 'no_retry') {
            flash('danger', __t('evaluations.student.err.no_retry'));
        } else {
            flash('danger', __t('evaluations.student.err.generic'));
        }
        header('Location: /student/evaluation/' . $evaluationId, true, 303);
        return;
    }
}

$page_title = (string) $evaluation['title'];

ob_start();
?>
<?= breadcrumbs([
    ['label' => __t('dashboard.student.title'),    'url' => '/student'],
    ['label' => (string) $evaluation['course_name'], 'url' => '/student/course/' . (int) $evaluation['course_id']],
    ['label' => (string) $evaluation['cu_name'],     'url' => '/student/cu/' . (int) $evaluation['cu_id']],
    ['label' => (string) $evaluation['title']],
]) ?>

<div class="row justify-content-center">
    <div class="col-12 col-lg-10">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
            <div>
                <h1 class="h4 mb-1"><?= e((string) $evaluation['title']) ?></h1>
                <small class="text-muted">
                    <?= (int) $evaluation['xp_value'] ?> XP
                    <?= e(__t('evaluations.student.xp_note')) ?>
                </small>
            </div>
            <?php
                // Mesma derivação de estado do card em student/cu/show.php (E7-05).
                if ($current === null) {
                    $evState = 'none';
                    $evBadge = 'secondary';
                } elseif ($current['feedback_at'] === null) {
                    $evState = 'awaiting';
                    $evBadge = 'warning';
                } elseif ($current['grade'] !== null && (float) $current['grade'] >= 6.0) {
                    $evState = 'approved';
                    $evBadge = 'success';
                } elseif ((int) ($current['retry_allowed'] ?? 0) === 1) {
                    $evState = 'retry';
                    $evBadge = 'info';
                } else {
                    $evState = 'failed';
                    $evBadge = 'danger';
                }
                $evGradeStr = ($current !== null && $current['grade'] !== null)
                    ? number_format((float) $current['grade'], 1, ',', '')
                    : '';
            ?>
            <span class="badge text-bg-<?= e($evBadge) ?>">
                <?php if (in_array($evState, ['approved', 'retry', 'failed'], true)): ?>
                    <?= e(__t('evaluations.student.state.' . $evState, ['grade' => $evGradeStr])) ?>
                <?php else: ?>
                    <?= e(__t('evaluations.student.state.' . $evState)) ?>
                <?php endif; ?>
            </span>
        </div>

        <?php if ($evaluation['instructions'] !== null && $evaluation['instructions'] !== ''): ?>
            <div class="card shadow-sm mb-3">
                <div class="card-body content-render">
                    <?php /* HTML já sanitizado pelo professor via HTML Purifier em E7-01 */ ?>
                    <?= (string) $evaluation['instructions'] ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- PDF do enunciado -->
        <?php if ($evaluation['pdf_path'] !== null): ?>
            <div class="card shadow-sm mb-3">
                <div class="card-body d-flex align-items-center gap-3 flex-wrap">
                    <div class="flex-grow-1">
                        <strong><?= e(__t('evaluations.student.brief_title')) ?></strong>
                        <div class="small text-muted">
                            <?= e(__t('evaluations.student.brief_hint')) ?>
                        </div>
                    </div>
                    <a href="/student/evaluation/<?= $evaluationId ?>/brief"
                       class="btn btn-outline-primary">
                        <?= e(__t('evaluations.student.brief_download')) ?>
                    </a>
                </div>
            </div>
        <?php endif; ?>

        <!-- Feedback + nota (quando existe) -->
        <?php if ($current !== null && $hasFeedback): ?>
            <div class="card shadow-sm mb-3 border-success">
                <div class="card-header bg-success-subtle d-flex align-items-center justify-content-between gap-2 flex-wrap">
                    <h2 class="h6 mb-0"><?= e(__t('evaluations.student.feedback_title')) ?></h2>
                    <?php if ($current['grade'] !== null): ?>
                        <span class="badge text-bg-light border">
                            <?= e(__t('evaluations.student.grade_label')) ?>:
                            <strong><?= e(number_format((float) $current['grade'], 1, ',', '')) ?></strong>
                            / 10
                        </span>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <?php if ($current['feedback'] !== null && $current['feedback'] !== ''): ?>
                        <p class="mb-2" style="white-space: pre-wrap;"><?= e((string) $current['feedback']) ?></p>
                    <?php endif; ?>
                    <small class="text-muted d-block">
                        <?= e(__t('evaluations.student.feedback_at', ['date' => substr((string) $current['feedback_at'], 0, 16)])) ?>
                    </small>
                </div>
            </div>
        <?php elseif ($current !== null): ?>
            <div class="alert alert-info" role="alert">
                <?= e(__t('evaluations.student.waiting_grade')) ?>
            </div>
        <?php endif; ?>

        <!-- Tentativa corrente -->
        <?php if ($current !== null): ?>
            <div class="card shadow-sm mb-3">
                <div class="card-header">
                    <h2 class="h6 mb-0">
                        <?= e(__t('evaluations.student.current_attempt', ['n' => (string) $current['attempt']])) ?>
                    </h2>
                </div>
                <div class="card-body">
                    <dl class="row mb-0 small">
                        <dt class="col-4 col-md-3"><?= e(__t('evaluations.student.form.file')) ?></dt>
                        <dd class="col-8 col-md-9">
                            <a href="/student/evaluation/<?= $evaluationId ?>/submission/<?= (int) $current['id'] ?>/file">
                                <?= e((string) $current['filename']) ?>
                            </a>
                        </dd>
                        <dt class="col-4 col-md-3"><?= e(__t('evaluations.student.sent_at')) ?></dt>
                        <dd class="col-8 col-md-9">
                            <?= e(substr((string) $current['created_at'], 0, 16)) ?>
                        </dd>
                    </dl>
                </div>
            </div>
        <?php endif; ?>

        <!-- Form de envio / reenvio. Só renderiza quando há ação ou mensagem
             útil pro aluno — aprovado (grade ≥ 6) não tem o que fazer aqui. -->
        <?php
            // $evState foi derivado no header: none | awaiting | approved | retry | failed.
            // - canShowForm abrange: none com submission_open=1 (envio novo) + retry (reenvio).
            // - approved: aluno já concluiu, sem card de ação.
            // - awaiting: aguardando correção, só mensagem.
            // - failed: reprovado sem reenvio, ciclo fechado.
            // - none com submission_open=0: entrega fechada, sem ter enviado.
            $showFormCard = $canShowForm || in_array($evState, ['awaiting', 'failed'], true)
                         || ($evState === 'none' && !$isOpen);
        ?>
        <?php if ($showFormCard): ?>
        <div class="card shadow-sm mb-3">
            <div class="card-header">
                <h2 class="h6 mb-0">
                    <?php if ($canResubmit): ?>
                        <?= e(__t('evaluations.student.resubmit_title')) ?>
                    <?php elseif ($evState === 'awaiting'): ?>
                        <?= e(__t('evaluations.student.awaiting_title')) ?>
                    <?php elseif ($evState === 'failed'): ?>
                        <?= e(__t('evaluations.student.failed_title')) ?>
                    <?php else: ?>
                        <?= e(__t('evaluations.student.submit_title')) ?>
                    <?php endif; ?>
                </h2>
            </div>
            <div class="card-body">
                <?php if ($canShowForm): ?>
                    <?php if ($errors !== []): ?>
                        <div class="alert alert-danger" role="alert">
                            <?php foreach ($errors as $key): ?>
                                <div><?= e(__t($key)) ?></div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <form method="POST" action="/student/evaluation/<?= $evaluationId ?>"
                          enctype="multipart/form-data" class="mb-0" novalidate>
                        <?= csrf_field() ?>
                        <div class="mb-3">
                            <label for="f-file" class="form-label">
                                <?= e(__t('evaluations.student.form.file')) ?>
                            </label>
                            <input type="file" name="file" id="f-file"
                                   accept=".pdf,.zip,.txt"
                                   class="form-control<?= isset($errors['file']) ? ' is-invalid' : '' ?>" required>
                            <div class="form-text"><?= e(__t('evaluations.student.form.file_hint')) ?></div>
                        </div>
                        <button type="submit" class="btn btn-primary btn-lg">
                            <?= e(__t($canResubmit ? 'evaluations.student.form.resubmit' : 'evaluations.student.form.submit')) ?>
                        </button>
                    </form>
                <?php elseif ($evState === 'awaiting'): ?>
                    <p class="small text-muted mb-0">
                        <?= e(__t('evaluations.student.awaiting_notice')) ?>
                    </p>
                <?php elseif ($evState === 'failed'): ?>
                    <p class="small text-muted mb-0">
                        <?= e(__t('evaluations.student.failed_notice')) ?>
                    </p>
                <?php else: ?>
                    <div class="alert alert-warning mb-0" role="alert">
                        <?= e(__t('evaluations.student.closed_notice')) ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Histórico de tentativas (E7-05: com feedback expandido). -->
        <?php
            $priorAttempts = array_filter($history, static fn ($h) => (int) $h['is_current'] !== 1);
        ?>
        <?php if ($priorAttempts !== []): ?>
            <div class="card shadow-sm">
                <div class="card-header">
                    <h2 class="h6 mb-0"><?= e(__t('evaluations.student.history_title')) ?></h2>
                </div>
                <ul class="list-group list-group-flush">
                    <?php foreach ($priorAttempts as $h): ?>
                        <li class="list-group-item">
                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                <span class="fw-semibold">
                                    <?= e(__t('evaluations.student.attempt_n', ['n' => (string) $h['attempt']])) ?>
                                </span>
                                <small class="text-muted flex-grow-1">
                                    <?= e(substr((string) $h['created_at'], 0, 16)) ?>
                                    <?php if ($h['grade'] !== null): ?>
                                        · <?= e(__t('evaluations.student.grade_label')) ?>:
                                        <strong><?= e(number_format((float) $h['grade'], 1, ',', '')) ?></strong>
                                    <?php endif; ?>
                                </small>
                                <a href="/student/evaluation/<?= $evaluationId ?>/submission/<?= (int) $h['id'] ?>/file"
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

<style>
.content-render img          { max-width: 100%; height: auto; }
.content-render table        { width: 100%; }
.content-render pre          { background: #f6f8fa; padding: .75rem; border-radius: .25rem; overflow-x: auto; }
.content-render blockquote   { border-left: 3px solid #dee2e6; padding-left: 1rem; color: #6c757d; }
</style>
<?php
$page_content = ob_get_clean();
require LMS_ROOT . '/src/templates/layout.php';
