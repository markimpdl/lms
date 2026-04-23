<?php
declare(strict_types=1);

/**
 * /teacher/activity/{id}/submissions — professor vê a lista de entregas
 * dos alunos (E6-04). Pendentes de feedback no topo.
 */

$tenantId = current_tenant_id();
if ($tenantId === null) {
    http_response_code(403);
    require LMS_ROOT . '/src/templates/errors/403.php';
    return;
}

$activityId = (int) ($_REQUEST['id'] ?? 0);
$activity   = Activity::findForTenant($activityId, $tenantId);
if ($activity === null) {
    http_response_code(404);
    require LMS_ROOT . '/src/templates/errors/404.php';
    return;
}

$submissions = ActivitySubmission::listForActivity($activityId, $tenantId);
$cuId        = (int) $activity['competence_unit_id'];

$page_title = __t('submissions.teacher.title', ['name' => (string) $activity['title']]);

ob_start();
?>
<div class="row justify-content-center">
    <div class="col-12 col-lg-10">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
            <div>
                <h1 class="h4 mb-1"><?= e((string) $activity['title']) ?></h1>
                <small class="text-muted">
                    <?= e(__t('activities.type.' . $activity['type'])) ?> ·
                    <?= (int) $activity['xp_value'] ?> XP ·
                    <?= count($submissions) ?> <?= e(__t('activities.submissions_label')) ?>
                </small>
            </div>
            <div class="d-flex gap-2 flex-wrap">
                <a href="/teacher/activity/<?= $activityId ?>/edit" class="btn btn-sm btn-outline-secondary">
                    <?= e(__t('common.edit')) ?>
                </a>
                <a href="/teacher/cu/<?= $cuId ?>" class="btn btn-sm btn-outline-secondary">
                    <?= e(__t('common.back')) ?>
                </a>
            </div>
        </div>

        <?php if ($submissions === []): ?>
            <div class="card card-body text-center text-muted py-5">
                <p class="mb-0"><?= e(__t('submissions.teacher.empty')) ?></p>
            </div>
        <?php else: ?>
            <div class="card shadow-sm">
                <ul class="list-group list-group-flush">
                    <?php foreach ($submissions as $sub): ?>
                        <?php
                            $sid       = (int) $sub['student_user_id'];
                            $pending   = $sub['feedback_at'] === null;
                            $hasFile   = $sub['filename'] !== null;
                            $hasCode   = $sub['code_text'] !== null;
                        ?>
                        <li class="list-group-item d-flex align-items-center gap-2 flex-wrap">
                            <div class="flex-grow-1">
                                <a href="/teacher/activity/<?= $activityId ?>/submission/<?= $sid ?>"
                                   class="fw-semibold text-decoration-none">
                                    <?= e((string) $sub['student_name']) ?>
                                </a>
                                <small class="text-muted d-block">
                                    <?= e((string) $sub['student_email']) ?> ·
                                    <?= e(__t('submissions.teacher.submitted_at', ['date' => substr((string) $sub['created_at'], 0, 16)])) ?>
                                </small>
                            </div>

                            <?php if ($hasFile): ?>
                                <span class="badge text-bg-light border" title="<?= e((string) $sub['filename']) ?>">📎</span>
                            <?php endif; ?>
                            <?php if ($hasCode): ?>
                                <span class="badge text-bg-light border" title="<?= e(__t('submissions.teacher.has_code')) ?>">&lt;/&gt;</span>
                            <?php endif; ?>

                            <?php if ($pending): ?>
                                <span class="badge text-bg-warning">
                                    <?= e(__t('submissions.teacher.pending')) ?>
                                </span>
                            <?php else: ?>
                                <span class="badge text-bg-success">
                                    <?= e(__t('submissions.teacher.reviewed')) ?>
                                </span>
                            <?php endif; ?>

                            <a href="/teacher/activity/<?= $activityId ?>/submission/<?= $sid ?>"
                               class="btn btn-sm btn-primary">
                                <?= e(__t($pending ? 'submissions.teacher.review' : 'submissions.teacher.view')) ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php
$page_content = ob_get_clean();
require LMS_ROOT . '/src/templates/layout.php';
