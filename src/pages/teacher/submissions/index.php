<?php
declare(strict_types=1);

/**
 * /teacher/submissions — listagem completa e paginada de submissões do tenant.
 *
 * Mix de activities + evaluations (tentativa corrente) ordenado por
 * `created_at DESC`. Filtro "all | pending" via querystring `status`.
 * Acessada pelo link "ver todas" do card de submissões recentes do dashboard.
 */

require_role('teacher');

$tenantId = current_tenant_id();
if ($tenantId === null) {
    http_response_code(403);
    require LMS_ROOT . '/src/templates/errors/403.php';
    return;
}

$status = (string) ($_GET['status'] ?? 'all');
if (!in_array($status, ['all', 'pending'], true)) {
    $status = 'all';
}
$pendingOnly = ($status === 'pending');

$perPage = 20;
$page    = max(1, (int) ($_GET['page'] ?? 1));
$offset  = ($page - 1) * $perPage;

$total     = TeacherDashboard::countAllSubmissions($tenantId, $pendingOnly);
$rows      = TeacherDashboard::findAllSubmissions($tenantId, $pendingOnly, $perPage, $offset);
$lastPage  = max(1, (int) ceil($total / $perPage));

$qs = static fn (array $extra): string => http_build_query(array_merge(
    ['status' => $status],
    $extra
));

$page_title = __t('teacher_submissions.page.title');

ob_start();
?>
<?= breadcrumbs([
    ['label' => __t('dashboard.teacher.title'), 'url' => '/teacher'],
    ['label' => __t('teacher_submissions.page.title')],
]) ?>
<header class="lms-dashboard-header">
    <div>
        <span class="lms-dashboard-eyebrow"><?= e(__t('teacher.dashboard.eyebrow')) ?></span>
        <h1 class="lms-dashboard-title"><?= e(__t('teacher_submissions.page.title')) ?></h1>
    </div>
    <a href="/teacher" class="btn btn-outline-secondary">
        <?= e(__t('common.back')) ?>
    </a>
</header>

<ul class="nav nav-pills mb-3">
    <li class="nav-item">
        <a class="nav-link <?= $status === 'all' ? 'active' : '' ?>"
           href="/teacher/submissions?status=all"><?= e(__t('teacher_submissions.filter.all')) ?></a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $status === 'pending' ? 'active' : '' ?>"
           href="/teacher/submissions?status=pending"><?= e(__t('teacher_submissions.filter.pending')) ?></a>
    </li>
</ul>

<?php if ($rows === []): ?>
    <div class="card card-body text-center text-muted py-5">
        <p class="mb-0">
            <?= e($pendingOnly
                ? __t('teacher_submissions.empty.pending')
                : __t('teacher_submissions.empty.all')) ?>
        </p>
    </div>
<?php else: ?>
    <div class="card shadow-sm">
        <ul class="list-group list-group-flush">
            <?php foreach ($rows as $r): ?>
                <?php
                    $src        = (string) $r['src'];
                    $refId      = (int) $r['ref_id'];
                    $studentId  = (int) $r['student_id'];
                    $feedbacked = $r['feedback_at'] !== null;
                    $url = $src === 'activity'
                        ? '/teacher/activity/' . $refId . '/submission/' . $studentId
                        : '/teacher/evaluation/' . $refId . '/submission/' . $studentId;
                    $badgeClass = $src === 'activity' ? 'text-bg-primary' : 'text-bg-info';
                    $badgeLabel = $src === 'activity'
                        ? __t('teacher_dashboard.recent.badge.activity')
                        : __t('teacher_dashboard.recent.badge.evaluation');
                ?>
                <li class="list-group-item">
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <span class="badge <?= e($badgeClass) ?>"><?= e($badgeLabel) ?></span>
                        <a href="<?= e($url) ?>" class="fw-semibold text-decoration-none flex-grow-1">
                            <?= e((string) $r['ref_title']) ?>
                        </a>
                        <?php if ($feedbacked): ?>
                            <span class="badge text-bg-success"><?= e(__t('teacher_dashboard.recent.with_feedback')) ?></span>
                        <?php else: ?>
                            <span class="badge text-bg-warning"><?= e(__t('teacher_dashboard.recent.pending')) ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="small text-muted mt-1">
                        <?= e((string) $r['student_name']) ?> · <?= e(format_short_datetime((string) $r['created_at'])) ?>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>

    <?php if ($lastPage > 1): ?>
        <nav aria-label="<?= e(__t('teacher_submissions.pagination_label')) ?>" class="mt-3">
            <ul class="pagination justify-content-center">
                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                    <a class="page-link" href="?<?= e($qs(['page' => max(1, $page - 1)])) ?>"><?= e(__t('teacher_submissions.prev')) ?></a>
                </li>
                <li class="page-item disabled">
                    <span class="page-link"><?= e(__t('teacher_submissions.page_x_of_y', ['x' => (string) $page, 'y' => (string) $lastPage])) ?></span>
                </li>
                <li class="page-item <?= $page >= $lastPage ? 'disabled' : '' ?>">
                    <a class="page-link" href="?<?= e($qs(['page' => min($lastPage, $page + 1)])) ?>"><?= e(__t('teacher_submissions.next')) ?></a>
                </li>
            </ul>
        </nav>
    <?php endif; ?>
<?php endif; ?>
<?php
$page_content = ob_get_clean();
require LMS_ROOT . '/src/templates/layout.php';
