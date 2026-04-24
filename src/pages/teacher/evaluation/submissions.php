<?php
declare(strict_types=1);

/**
 * /teacher/evaluation/{id}/submissions — listagem de alunos + status da
 * avaliação (E7-04).
 *
 * LEFT JOIN garante que todos os alunos matriculados aparecem, mesmo sem
 * submissão. Status é derivado no PHP a partir das colunas (grade,
 * feedback_at, retry_allowed, submission_id IS NULL).
 */

$tenantId = current_tenant_id();
if ($tenantId === null) {
    http_response_code(403);
    require LMS_ROOT . '/src/templates/errors/403.php';
    return;
}

$evaluationId = (int) ($_REQUEST['id'] ?? 0);
$evaluation   = Evaluation::findForTenant($evaluationId, $tenantId);
if ($evaluation === null) {
    http_response_code(404);
    require LMS_ROOT . '/src/templates/errors/404.php';
    return;
}

$rows    = EvaluationSubmission::listForEvaluation($evaluationId, $tenantId);
$metrics = CourseMetrics::forEvaluation($evaluationId, $tenantId);

$totals = ['none' => 0, 'awaiting' => 0, 'approved' => 0, 'retry' => 0, 'failed' => 0];
$decorated = [];
foreach ($rows as $r) {
    $hasSub = $r['submission_id'] !== null;
    $grade  = $r['grade']       !== null ? (float) $r['grade'] : null;
    $fb     = $r['feedback_at'] !== null;
    $retry  = (int) ($r['retry_allowed'] ?? 0) === 1;

    if (!$hasSub) {
        $status = 'none';
    } elseif (!$fb) {
        $status = 'awaiting';
    } elseif ($grade !== null && $grade >= 6.0) {
        $status = 'approved';
    } elseif ($retry) {
        $status = 'retry';
    } else {
        $status = 'failed';
    }

    $totals[$status]++;
    $r['status'] = $status;
    $decorated[] = $r;
}

$total     = count($decorated);
$pending   = $totals['awaiting'];

$page_title = __t('evaluations.submissions.page_title', ['name' => (string) $evaluation['title']]);

ob_start();
?>
<div class="row justify-content-center">
    <div class="col-12 col-lg-11">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
            <div>
                <h1 class="h4 mb-1">
                    <?= e(__t('evaluations.submissions.page_title', ['name' => (string) $evaluation['title']])) ?>
                </h1>
                <small class="text-muted">
                    <?= e(__t('evaluations.submissions.summary', [
                        'total'    => (string) $total,
                        'pending'  => (string) $pending,
                        'approved' => (string) $totals['approved'],
                    ])) ?>
                </small>
            </div>
            <a href="/teacher/evaluation/<?= $evaluationId ?>/edit" class="btn btn-sm btn-outline-secondary">
                <?= e(__t('common.back')) ?>
            </a>
        </div>

        <?php if ($metrics !== null && $metrics['enrolled'] > 0): ?>
            <div class="card card-body shadow-sm mb-3">
                <div class="row text-center g-3">
                    <div class="col-6 col-md-3">
                        <small class="text-muted text-uppercase fw-semibold d-block"><?= e(__t('metrics.eval.enrolled')) ?></small>
                        <div class="h4 mb-0"><?= (int) $metrics['enrolled'] ?></div>
                    </div>
                    <div class="col-6 col-md-3">
                        <small class="text-muted text-uppercase fw-semibold d-block"><?= e(__t('metrics.eval.pct_approved')) ?></small>
                        <div class="h4 mb-0 <?= $metrics['pct_approved'] >= 80 ? 'text-success' : ($metrics['pct_approved'] >= 50 ? 'text-warning-emphasis' : 'text-danger-emphasis') ?>">
                            <?= (int) $metrics['pct_approved'] ?>%
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <small class="text-muted text-uppercase fw-semibold d-block"><?= e(__t('metrics.eval.avg_grade')) ?></small>
                        <div class="h4 mb-0">
                            <?= $metrics['avg_grade'] !== null
                                ? e(number_format($metrics['avg_grade'], 1, current_lang() === 'pt' ? ',' : '.', ''))
                                : '—' ?>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <small class="text-muted text-uppercase fw-semibold d-block"><?= e(__t('metrics.eval.avg_feedback')) ?></small>
                        <div class="h4 mb-0"><?= e(format_duration_minutes($metrics['avg_feedback_minutes'])) ?></div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($total === 0): ?>
            <div class="card card-body shadow-sm text-center text-muted py-5">
                <p class="mb-0"><?= e(__t('evaluations.submissions.empty')) ?></p>
            </div>
        <?php else: ?>
            <div class="card shadow-sm">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th scope="col"><?= e(__t('evaluations.submissions.col_student')) ?></th>
                                <th scope="col"><?= e(__t('evaluations.submissions.col_status')) ?></th>
                                <th scope="col" class="text-end d-none d-md-table-cell">
                                    <?= e(__t('evaluations.submissions.col_attempts')) ?>
                                </th>
                                <th scope="col" class="d-none d-md-table-cell">
                                    <?= e(__t('evaluations.submissions.col_sent_at')) ?>
                                </th>
                                <th scope="col" class="text-end"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($decorated as $r): ?>
                                <?php
                                    $status = (string) $r['status'];
                                    $badge  = match ($status) {
                                        'approved' => 'success',
                                        'awaiting' => 'warning',
                                        'retry'    => 'info',
                                        'failed'   => 'danger',
                                        default    => 'secondary',
                                    };
                                    $hasSub   = $r['submission_id'] !== null;
                                    $gradeStr = $r['grade'] !== null
                                        ? number_format((float) $r['grade'], 1, ',', '')
                                        : '';
                                ?>
                                <tr>
                                    <td>
                                        <div class="fw-semibold"><?= e((string) $r['student_name']) ?></div>
                                        <small class="text-muted"><?= e((string) $r['student_email']) ?></small>
                                    </td>
                                    <td>
                                        <span class="badge text-bg-<?= e($badge) ?>">
                                            <?php if ($status === 'approved' || $status === 'retry' || $status === 'failed'): ?>
                                                <?= e(__t('evaluations.submissions.status.' . $status, ['grade' => $gradeStr])) ?>
                                            <?php else: ?>
                                                <?= e(__t('evaluations.submissions.status.' . $status)) ?>
                                            <?php endif; ?>
                                        </span>
                                    </td>
                                    <td class="text-end d-none d-md-table-cell">
                                        <?= $hasSub ? (int) $r['attempts_count'] : '—' ?>
                                    </td>
                                    <td class="d-none d-md-table-cell">
                                        <?= $hasSub ? e(substr((string) $r['submitted_at'], 0, 16)) : '—' ?>
                                    </td>
                                    <td class="text-end">
                                        <?php if ($hasSub): ?>
                                            <a href="/teacher/evaluation/<?= $evaluationId ?>/submission/<?= (int) $r['student_id'] ?>"
                                               class="btn btn-sm btn-outline-primary">
                                                <?= e(__t($status === 'awaiting' ? 'evaluations.submissions.action_grade' : 'evaluations.submissions.action_review')) ?>
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted small">—</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php
$page_content = ob_get_clean();
require LMS_ROOT . '/src/templates/layout.php';
