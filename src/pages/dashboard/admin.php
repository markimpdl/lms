<?php
declare(strict_types=1);

// Auth + papel garantidos pelo front controller via src/routes.php (E1-05).
$user = current_user();

/**
 * Painel de métricas agregadas do super-admin (E2-06). Substitui o stub
 * anterior porque `/admin` já é a rota de redirect pós-login para o
 * super-admin; concentrar aqui evita duas URLs "home".
 *
 * Cache leve de 5 min via `cached_json` — como todas as métricas são
 * globais, um único arquivo serve todos os super-admins.
 */
$metrics = cached_json('admin-metrics', 300, static fn (): array => AdminMetricsService::snapshot());

$daily    = $metrics['daily'];
$maxCount = 0;
foreach ($daily as $day) {
    if ($day['count'] > $maxCount) {
        $maxCount = $day['count'];
    }
}

$page_title = __t('dashboard.admin.title');

ob_start();
?>
<header class="lms-dashboard-header">
    <div>
        <span class="lms-dashboard-eyebrow"><?= e(__t('admin.dashboard.eyebrow')) ?></span>
        <h1 class="lms-dashboard-title"><?= e(__t('dashboard.admin.title')) ?></h1>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a href="/admin/teachers" class="btn btn-sm btn-outline-primary">
            <?= e(__t('admin.teachers.title')) ?>
        </a>
        <a href="/admin/settings" class="btn btn-sm btn-outline-secondary">
            <?= e(__t('admin.settings.title')) ?>
        </a>
    </div>
</header>

<p class="text-muted small"><?= e(__t('dashboard.admin.welcome', ['name' => $user['name']])) ?></p>

<div class="row g-3 mb-3">
    <?php
    $card = static function (string $label, string $value, ?string $hint = null): string {
        $hintHtml = $hint !== null
            ? '<div class="small text-muted mt-1">' . e($hint) . '</div>'
            : '';
        return '<div class="col-6 col-lg-4">'
             . '<div class="card shadow-sm h-100"><div class="card-body">'
             . '<div class="small text-uppercase text-muted">' . e($label) . '</div>'
             . '<div class="h3 mb-0">' . e($value) . '</div>'
             . $hintHtml
             . '</div></div></div>';
    };
    ?>
    <?= $card(
        __t('admin.dash.teachers_active'),
        (string) $metrics['teachers_active'],
        __t('admin.dash.teachers_inactive_hint', ['count' => $metrics['teachers_inactive']])
    ) ?>
    <?= $card(
        __t('admin.dash.students_active'),
        (string) $metrics['students_active']
    ) ?>
    <?= $card(
        __t('admin.dash.courses_active'),
        (string) $metrics['courses_active']
    ) ?>
    <?= $card(
        __t('admin.dash.submissions_30d'),
        (string) $metrics['submissions_30d']
    ) ?>
    <?= $card(
        __t('admin.dash.last_login'),
        $metrics['last_login_at'] !== null
            ? substr((string) $metrics['last_login_at'], 0, 16)
            : __t('admin.teachers.never')
    ) ?>
    <?= $card(
        __t('admin.dash.emails_30d'),
        $metrics['emails_sent_30d'] !== null ? (string) $metrics['emails_sent_30d'] : '—',
        __t('admin.dash.emails_pending_hint')
    ) ?>
</div>

<div class="card shadow-sm">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-baseline flex-wrap gap-2 mb-2">
            <h2 class="h6 mb-0"><?= e(__t('admin.dash.chart_title')) ?></h2>
            <span class="small text-muted"><?= e(__t('admin.dash.chart_subtitle')) ?></span>
        </div>
        <?php if ($maxCount === 0): ?>
            <p class="small text-muted mb-0"><?= e(__t('admin.dash.chart_empty')) ?></p>
        <?php else: ?>
            <div class="d-flex align-items-end gap-1 lms-chart">
                <?php foreach ($daily as $day): ?>
                    <?php $h = (int) round(($day['count'] / $maxCount) * 100); ?>
                    <div class="lms-chart-bar flex-grow-1"
                         style="height: <?= $h ?>%; min-height: <?= $day['count'] > 0 ? 2 : 1 ?>px;"
                         title="<?= e($day['date']) ?>: <?= (int) $day['count'] ?>"></div>
                <?php endforeach; ?>
            </div>
            <div class="d-flex justify-content-between small text-muted mt-1">
                <span><?= e($daily[0]['date']) ?></span>
                <span><?= e(end($daily)['date']) ?></span>
            </div>
        <?php endif; ?>
    </div>
</div>

<style>
.lms-chart { height: 140px; }
.lms-chart-bar {
    background-color: #0d6efd;
    border-radius: 2px 2px 0 0;
    opacity: .75;
    transition: opacity .15s;
}
.lms-chart-bar:hover { opacity: 1; }
</style>
<?php
$page_content = ob_get_clean();

require LMS_ROOT . '/src/templates/layout.php';
