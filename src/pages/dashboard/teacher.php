<?php
declare(strict_types=1);

/**
 * /teacher — home pós-login do professor (E11-01).
 *
 * Totalizadores + submissões recentes + alunos sem acesso + atalhos
 * pros hubs. Queries via `TeacherDashboard` model; tudo filtrado por
 * `current_tenant_id()`.
 */

// Auth + papel garantidos pelo front controller.
$user     = current_user();
$tenantId = current_tenant_id();
if ($tenantId === null) {
    http_response_code(403);
    require LMS_ROOT . '/src/templates/errors/403.php';
    return;
}

$totals    = TeacherDashboard::totalsForTenant($tenantId);
$recent    = TeacherDashboard::recentSubmissions($tenantId, 10);
$inactive  = TeacherDashboard::inactiveStudents($tenantId, 10);
$firstName = explode(' ', trim((string) ($user['name'] ?? '')))[0] ?? '';

/** Helper local: formata "há X dias" ou "nunca acessou" */
$fmtInactivity = static function (?string $lastAccess): string {
    if ($lastAccess === null || $lastAccess === '') {
        return __t('teacher_dashboard.inactive.never');
    }
    try {
        $dt   = new \DateTimeImmutable($lastAccess);
        $now  = new \DateTimeImmutable();
        $days = (int) $now->diff($dt)->days;
        return __t('teacher_dashboard.inactive.days_ago', ['days' => (string) $days]);
    } catch (\Exception) {
        return __t('teacher_dashboard.inactive.never');
    }
};

$page_title = __t('dashboard.teacher.title');

ob_start();
?>
<header class="lms-dashboard-header">
    <div>
        <span class="lms-dashboard-eyebrow"><?= e(__t('teacher.dashboard.eyebrow')) ?></span>
        <h1 class="lms-dashboard-title"><?= e(__t('dashboard.teacher.title')) ?></h1>
        <p class="lms-dashboard-subtitle"><?= e(__t('dashboard.teacher.welcome', ['name' => $firstName])) ?></p>
    </div>
</header>
<div class="py-1">

    <!-- Atalhos pros hubs -->
    <h2 class="h5 mb-3"><?= e(__t('teacher_dashboard.hubs.title')) ?></h2>
    <div class="row g-3 mb-4">
        <div class="col-12 col-md-6 col-lg-3">
            <a href="/teacher/courses" class="card card-body shadow-sm h-100 text-decoration-none">
                <h3 class="h6 mb-1"><?= e(__t('courses.index.title')) ?></h3>
                <p class="text-muted small mb-0"><?= e(__t('dashboard.teacher.courses_hint')) ?></p>
            </a>
        </div>
        <div class="col-12 col-md-6 col-lg-3">
            <a href="/teacher/students" class="card card-body shadow-sm h-100 text-decoration-none">
                <h3 class="h6 mb-1"><?= e(__t('students.title')) ?></h3>
                <p class="text-muted small mb-0"><?= e(__t('dashboard.teacher.students_hint')) ?></p>
            </a>
        </div>
        <div class="col-12 col-md-6 col-lg-3">
            <a href="/teacher/groups" class="card card-body shadow-sm h-100 text-decoration-none">
                <h3 class="h6 mb-1"><?= e(__t('groups.title')) ?></h3>
                <p class="text-muted small mb-0"><?= e(__t('dashboard.teacher.groups_hint')) ?></p>
            </a>
        </div>
        <div class="col-12 col-md-6 col-lg-3">
            <a href="/teacher/ranks" class="card card-body shadow-sm h-100 text-decoration-none">
                <h3 class="h6 mb-1"><?= e(__t('ranks.title')) ?></h3>
                <p class="text-muted small mb-0"><?= e(__t('dashboard.teacher.ranks_hint')) ?></p>
            </a>
        </div>
    </div>

    <!-- Totalizadores -->
    <div class="row g-3 mb-4">
        <div class="col-12 col-md-4">
            <div class="card card-body shadow-sm h-100">
                <small class="text-muted text-uppercase fw-semibold"><?= e(__t('teacher_dashboard.totals.courses')) ?></small>
                <div class="display-6 fw-bold mb-0"><?= (int) $totals['courses'] ?></div>
            </div>
        </div>
        <div class="col-12 col-md-4">
            <div class="card card-body shadow-sm h-100">
                <small class="text-muted text-uppercase fw-semibold"><?= e(__t('teacher_dashboard.totals.students')) ?></small>
                <div class="display-6 fw-bold mb-0"><?= (int) $totals['students'] ?></div>
            </div>
        </div>
        <div class="col-12 col-md-4">
            <div class="card card-body shadow-sm h-100 <?= $totals['pending_submissions'] > 0 ? 'border-warning' : '' ?>">
                <small class="text-muted text-uppercase fw-semibold"><?= e(__t('teacher_dashboard.totals.pending')) ?></small>
                <div class="display-6 fw-bold mb-0 <?= $totals['pending_submissions'] > 0 ? 'text-warning-emphasis' : '' ?>">
                    <?= (int) $totals['pending_submissions'] ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Submissões recentes + inativos -->
    <div class="row g-3 mb-4">
        <div class="col-12 col-lg-8">
            <div class="card shadow-sm h-100">
                <div class="card-header d-flex justify-content-between align-items-center gap-2">
                    <h2 class="h6 mb-0"><?= e(__t('teacher_dashboard.recent.title')) ?></h2>
                    <a href="/teacher/submissions" class="small text-decoration-none">
                        <?= e(__t('teacher_dashboard.recent.view_all')) ?>
                    </a>
                </div>
                <?php if ($recent === []): ?>
                    <div class="card-body text-center text-muted py-4">
                        <p class="small mb-0"><?= e(__t('teacher_dashboard.recent.empty')) ?></p>
                    </div>
                <?php else: ?>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($recent as $r): ?>
                            <?php
                                $src        = (string) $r['src'];
                                $refId      = (int) $r['ref_id'];
                                $studentId  = (int) $r['student_id'];
                                $feedbacked = $r['feedback_at'] !== null;
                                $url = $src === 'activity'
                                    ? '/teacher/activity/' . $refId . '/submission/' . $studentId . '?from=' . urlencode('/teacher')
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
                <?php endif; ?>
            </div>
        </div>

        <div class="col-12 col-lg-4">
            <div class="card shadow-sm h-100">
                <div class="card-header">
                    <h2 class="h6 mb-0"><?= e(__t('teacher_dashboard.inactive.title')) ?></h2>
                </div>
                <?php if ($inactive === []): ?>
                    <div class="card-body text-center text-muted py-4">
                        <p class="small mb-0"><?= e(__t('teacher_dashboard.inactive.empty')) ?></p>
                    </div>
                <?php else: ?>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($inactive as $s): ?>
                            <?php $sFullName = (string) $s['name']; ?>
                            <li class="list-group-item">
                                <a href="/teacher/students/<?= (int) $s['id'] ?>"
                                   class="fw-semibold text-decoration-none"
                                   title="<?= e($sFullName) ?>">
                                    <?= e(format_short_name($sFullName)) ?>
                                </a>
                                <div class="small text-muted">
                                    <?= e((string) $s['email']) ?>
                                </div>
                                <div class="small text-warning-emphasis">
                                    <?= e($fmtInactivity($s['last_access_at'])) ?>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>

</div>
<?php
$page_content = ob_get_clean();
require LMS_ROOT . '/src/templates/layout.php';
