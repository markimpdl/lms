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

if ($current === null) {
    http_response_code(404);
    require LMS_ROOT . '/src/templates/errors/404.php';
    return;
}

// E20-06: avaliação tipo Quiz tem fluxo próprio (nota auto-calculada,
// professor só decide retry). Delega pro handler dedicado.
if ((string) ($evaluation['type'] ?? 'projeto') === 'quiz') {
    require LMS_ROOT . '/src/pages/teacher/evaluation/submission_quiz.php';
    return;
}

// E25-03: curso em LO mode renderiza UI alternativa (5 inputs por LO,
// média auto = nota final). Caso de borda: UC sem 5 LOs cadastrados
// (curso virou LO depois) — exibe alerta com link pro cadastro.
$isLoMode  = (string) ($evaluation['grading_mode'] ?? 'grade') === 'learning_outcomes';
$cuId      = (int) $evaluation['cu_id'];
$loList    = $isLoMode ? LearningOutcome::findByCu($cuId) : [];
$loReady   = $isLoMode && count($loList) === 5;
$loGradesExisting = ($isLoMode && $loReady)
    ? LearningOutcome::findGradesBySubmission((int) $current['id'])
    : [];

$old = [
    'grade'         => $current['grade']         !== null ? (string) $current['grade']   : '',
    'feedback'      => $current['feedback']      !== null ? (string) $current['feedback'] : '',
    'retry_allowed' => (int) ($current['retry_allowed'] ?? 0) === 1,
    // E25-03: array indexado por loId com nota como string (vazio se primeiro feedback)
    'lo_grades'     => [],
];
foreach ($loList as $lo) {
    $loId = (int) $lo['id'];
    $old['lo_grades'][$loId] = isset($loGradesExisting[$loId])
        ? (string) $loGradesExisting[$loId]
        : '';
}
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_verify();
    } catch (RuntimeException) {
        flash('danger', __t('auth.forbidden'));
        header('Location: /teacher/evaluation/' . $evaluationId . '/submission/' . $studentId);
        return;
    }

    // LO mode com UC sem 5 LOs: bloqueia POST com alerta.
    if ($isLoMode && !$loReady) {
        flash('danger', __t('evaluations.grade.err.lo_uc_not_ready'));
        header('Location: /teacher/evaluation/' . $evaluationId . '/submission/' . $studentId);
        return;
    }

    $rawGrade    = trim((string) ($_POST['grade']    ?? ''));
    $rawFeedback = trim((string) ($_POST['feedback'] ?? ''));
    $rawRetry    = isset($_POST['retry_allowed']);

    // E25-03: em LO mode, lê 5 inputs nomeados lo_grade[<lo_id>] e calcula
    // a média (substitui o input único de grade).
    $loGradesByLo = [];
    if ($isLoMode && $loReady) {
        $rawLoGrades = is_array($_POST['lo_grade'] ?? null) ? $_POST['lo_grade'] : [];
        foreach ($loList as $lo) {
            $loId = (int) $lo['id'];
            $val  = trim((string) ($rawLoGrades[$loId] ?? ''));
            $loGradesByLo[$loId] = $val;
        }
    }

    $old = [
        'grade'         => $rawGrade,
        'feedback'      => $rawFeedback,
        'retry_allowed' => $rawRetry,
        'lo_grades'     => $loGradesByLo !== [] ? $loGradesByLo : ($old['lo_grades'] ?? []),
    ];

    if ($isLoMode && $loReady) {
        // Valida cada nota por LO; ignora `grade` clássico (não vai pro UI)
        $loGradesParsed = [];
        foreach ($loGradesByLo as $loId => $raw) {
            $normalized = str_replace(',', '.', $raw);
            if ($normalized === '' || !is_numeric($normalized)) {
                $errors['lo_grade_' . $loId] = 'evaluations.grade.err.lo_grade_required';
                continue;
            }
            $g = (float) $normalized;
            if ($g < 0.0 || $g > 10.0) {
                $errors['lo_grade_' . $loId] = 'evaluations.grade.err.lo_grade_range';
                continue;
            }
            $loGradesParsed[$loId] = $g;
        }
    } else {
        $normalized = str_replace(',', '.', $rawGrade);
        if ($normalized === '' || !is_numeric($normalized)) {
            $errors['grade'] = 'evaluations.grade.err.grade_required';
        } else {
            $gradeVal = (float) $normalized;
            if ($gradeVal < 0.0 || $gradeVal > 10.0) {
                $errors['grade'] = 'evaluations.grade.err.grade_range';
            }
        }
    }

    if ($rawFeedback === '') {
        $errors['feedback'] = 'evaluations.grade.err.feedback_required';
    } elseif (mb_strlen($rawFeedback) > 4000) {
        $errors['feedback'] = 'evaluations.grade.err.feedback_too_long';
    }

    if ($errors === []) {
        if ($isLoMode && $loReady) {
            $result = EvaluationSubmissionService::gradeByLo(
                (int) $current['id'],
                $tenantId,
                $loGradesParsed,
                $rawFeedback,
                $rawRetry
            );
            $gradeVal = (float) ($result['average'] ?? 0);
        } else {
            $gradeVal = (float) $normalized;
            $result = EvaluationSubmissionService::grade(
                (int) $current['id'],
                $tenantId,
                $gradeVal,
                $rawFeedback,
                $rawRetry
            );
        }

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
                NotificationService::EVENT_GRADE_EVALUATION,
                [$studentId],
                (string) $evaluation['title'],
                null,
                $evalLink,
                $courseId
            );

            if ((int) $result['retry_effective'] === 1) {
                NotificationService::fanout(
                    NotificationService::EVENT_RETRY_ENABLED,
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

        // 'not_current' não existe mais (v0.30.0: só há 1 submissão por par
        // eval/student) — caí em 404 se status != 'ok' / 'not_found'.
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
            <a href="/teacher/evaluation/<?= $evaluationId ?>/submissions" class="btn btn-sm btn-outline-secondary">
                <?= e(__t('common.back')) ?>
            </a>
        </div>

        <!-- Metadados da submissão corrente -->
        <div class="card shadow-sm mb-3">
            <div class="card-header d-flex align-items-center justify-content-between gap-2 flex-wrap">
                <h2 class="h6 mb-0">
                    <?= e(__t('evaluations.grade.current_submission', ['n' => (string) $current['attempt']])) ?>
                </h2>
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <?php if (!empty($current['report_pdf_path'])): ?>
                        <a href="/teacher/evaluation/<?= $evaluationId ?>/submission/<?= $studentId ?>/report.pdf"
                           target="_blank" rel="noopener"
                           class="btn btn-sm btn-outline-primary">
                            <?= e(__t('evaluations.grade.report_download')) ?>
                        </a>
                    <?php endif; ?>
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

        <?php if ($isLoMode && !$loReady): ?>
            <div class="alert alert-warning" role="alert">
                <strong><?= e(__t('evaluations.grade.lo_uc_not_ready_title')) ?></strong>
                <p class="mb-2 mt-1 small"><?= e(__t('evaluations.grade.lo_uc_not_ready_help')) ?></p>
                <a href="/teacher/cu/<?= $cuId ?>/learning-outcomes" class="btn btn-sm btn-warning">
                    <?= e(__t('learning_outcomes.edit_link')) ?>
                </a>
            </div>
        <?php endif; ?>

        <form method="POST" action="/teacher/evaluation/<?= $evaluationId ?>/submission/<?= $studentId ?>"
              class="card card-body shadow-sm mb-3" novalidate
              <?php if ($isLoMode && $loReady): ?>x-data="{
                  grades: <?= e(json_encode(array_map(static fn($v) => (string) $v, $old['lo_grades']), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)) ?>,
                  get average() {
                      const vals = Object.values(this.grades).map(v => parseFloat(String(v).replace(',', '.'))).filter(n => !Number.isNaN(n));
                      if (vals.length === 0) return '—';
                      const sum = vals.reduce((a, b) => a + b, 0);
                      return (sum / vals.length).toFixed(1).replace('.', ',');
                  }
              }"<?php endif; ?>>
            <?= csrf_field() ?>

            <?php if ($isLoMode && $loReady): ?>
                <h2 class="h6 mb-2"><?= e(__t('evaluations.grade.lo_mode_title')) ?></h2>
                <p class="text-muted small mb-3"><?= e(__t('evaluations.grade.lo_mode_help')) ?></p>

                <?php foreach ($loList as $i => $lo): $loId = (int) $lo['id']; ?>
                    <div class="row g-2 align-items-start mb-3">
                        <div class="col-12 col-md-9">
                            <label for="f-lo-<?= $loId ?>" class="form-label small fw-semibold mb-1">
                                <?= e(__t('evaluations.grade.lo_field_n', ['n' => (string) ($i + 1)])) ?>
                            </label>
                            <p class="small mb-0"><?= e((string) $lo['description']) ?></p>
                        </div>
                        <div class="col-12 col-md-3">
                            <input type="number" name="lo_grade[<?= $loId ?>]" id="f-lo-<?= $loId ?>"
                                   x-model="grades[<?= $loId ?>]"
                                   class="form-control form-control-lg<?= isset($errors['lo_grade_' . $loId]) ? ' is-invalid' : '' ?>"
                                   value="<?= e((string) ($old['lo_grades'][$loId] ?? '')) ?>"
                                   min="0" max="10" step="0.1" required>
                            <?php if (isset($errors['lo_grade_' . $loId])): ?>
                                <div class="invalid-feedback"><?= e(__t($errors['lo_grade_' . $loId])) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>

                <div class="row g-3 align-items-end">
                    <div class="col-12 col-md-4">
                        <label class="form-label fw-semibold"><?= e(__t('evaluations.grade.lo_average_label')) ?></label>
                        <div class="form-control form-control-lg bg-light text-center fw-bold" x-text="average">—</div>
                    </div>
                    <div class="col-12 col-md-8 mb-2">
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
            <?php else: ?>
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
            <?php endif; ?>

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
