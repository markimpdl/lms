<?php
declare(strict_types=1);

/**
 * /teacher/ranking            — ranking do tenant inteiro (E9-05).
 * /teacher/courses/{id}/ranking — ranking restrito ao curso (E9-06).
 *
 * Reusa o `RankingService` (E9-02). Mesmos filtros do aluno (janela + grupo
 * + ano + course_id) + coluna extra "última entrega". Sem destaque de linha
 * (professor não compete).
 *
 * Auth + papel garantidos pelo front controller; tenant_id vem de
 * `current_tenant_id()` (helper do professor). Diferente do aluno, o
 * professor não precisa de wrap visual `lms-student-area` — usa Bootstrap
 * default (`.table table-hover`) seguindo o padrão das outras telas do
 * professor (ex.: `/teacher/students`, `/teacher/groups`).
 */

$tenantId = current_tenant_id();
if ($tenantId === null) {
    http_response_code(403);
    require LMS_ROOT . '/src/templates/errors/403.php';
    return;
}

// Escopo de curso (vindo do route pattern /teacher/courses/{id}/ranking).
// Quando presente, valida que o curso pertence ao tenant. 404 amigável caso
// contrário (cross-tenant ou curso inexistente).
$courseId   = (int) ($_REQUEST['course_id'] ?? 0);
$courseName = null;
if ($courseId > 0) {
    $course = Course::findForTenant($courseId, $tenantId);
    if ($course === null) {
        http_response_code(404);
        require LMS_ROOT . '/src/templates/errors/404.php';
        return;
    }
    $courseName = (string) $course['name'];
}

$window = (string) ($_GET['window'] ?? 'all');
if (!in_array($window, RankingService::WINDOWS, true)) {
    $window = 'all';
}

$groups = Group::listForSelect($tenantId);

// Anos disponíveis no dropdown = anos de matrícula no tenant (mudou em
// 2026-04-27 — antes lia data de XP). Alinhado com a nova semântica do
// filtro year no RankingService (alunos matriculados no ano).
$years = [];
$stmt = Database::pdo()->prepare(
    'SELECT DISTINCT YEAR(e.enrolled_at) AS y
       FROM enrollments e
       INNER JOIN courses c ON c.id = e.course_id AND c.tenant_id = ?
      ORDER BY y DESC'
);
$stmt->execute([$tenantId]);
$years = array_map(static fn (array $r): int => (int) $r['y'], $stmt->fetchAll());

$rawGroupId = (int) ($_GET['group'] ?? 0);
$groupId    = null;
if ($rawGroupId > 0) {
    foreach ($groups as $g) {
        if ((int) $g['id'] === $rawGroupId) {
            $groupId = $rawGroupId;
            break;
        }
    }
}

$currentYear = (int) date('Y');
$rawYear     = $_GET['year'] ?? null;
if ($rawYear === 'all') {
    $year = null;
} elseif ($rawYear === null) {
    $year = $currentYear;
} else {
    $rawYearInt = (int) $rawYear;
    $year = ($rawYearInt >= 2020 && $rawYearInt <= 2099) ? $rawYearInt : $currentYear;
}

$page    = max(1, (int) ($_GET['page'] ?? 1));
$perPage = RankingService::DEFAULT_PER_PAGE;

$filters = [];
if ($groupId !== null) { $filters['group_id']  = $groupId; }
if ($year    !== null) { $filters['year']      = $year; }
if ($courseId > 0)     { $filters['course_id'] = $courseId; }

$result   = RankingService::compute($tenantId, $window, $filters, $page, $perPage);
$rows     = $result['rows'];
$total    = $result['total'];
$lastPage = max(1, (int) ceil($total / $perPage));

$qs = static function (array $overrides = []) use ($window, $groupId, $year, $page): string {
    $params = ['window' => $window, 'page' => (string) $page];
    if ($groupId !== null) { $params['group'] = (string) $groupId; }
    if ($year    !== null) { $params['year']  = (string) $year; }
    foreach ($overrides as $k => $v) {
        if ($v === null) {
            unset($params[$k]);
        } else {
            $params[$k] = (string) $v;
        }
    }
    return '?' . http_build_query($params);
};

$page_title = $courseName !== null
    ? __t('ranking.title.course', ['name' => $courseName])
    : __t('ranking.title');

ob_start();
?>
<?php if ($courseName !== null): ?>
    <?= breadcrumbs([
        ['label' => __t('courses.index.title'),     'url' => '/teacher/courses'],
        ['label' => $courseName,                    'url' => '/teacher/courses/' . (int) $courseId],
        ['label' => __t('ranking.title')],
    ]) ?>
<?php endif; ?>

<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
    <div>
        <h1 class="h3 m-0">
            <?= $courseName !== null ? e($courseName) : e(__t('ranking.title')) ?>
        </h1>
        <p class="text-muted mb-0">
            <?= e(__t($courseName !== null ? 'ranking.scope.course' : 'ranking.subtitle.teacher')) ?>
        </p>
    </div>

    <div class="btn-group" role="tablist" aria-label="<?= e(__t('ranking.window.aria')) ?>">
        <?php foreach (RankingService::WINDOWS as $w): ?>
            <a class="btn btn-sm <?= $w === $window ? 'btn-primary' : 'btn-outline-secondary' ?>"
               href="<?= e($qs(['window' => $w, 'page' => 1])) ?>"
               role="tab"
               aria-selected="<?= $w === $window ? 'true' : 'false' ?>">
                <?= e(__t('ranking.window.' . $w)) ?>
            </a>
        <?php endforeach; ?>
    </div>
</div>

<form method="get" class="card card-body mb-3 py-2" aria-label="<?= e(__t('ranking.filter.aria')) ?>">
    <input type="hidden" name="window" value="<?= e($window) ?>">
    <div class="row g-2 align-items-end">
        <div class="col-12 col-md-5">
            <label for="rkg-group" class="form-label small text-muted text-uppercase mb-1">
                <?= e(__t('ranking.filter.group')) ?>
            </label>
            <select id="rkg-group" name="group" class="form-select form-select-sm">
                <option value=""><?= e(__t('ranking.filter.all_groups')) ?></option>
                <?php foreach ($groups as $g): ?>
                    <option value="<?= (int) $g['id'] ?>" <?= $groupId === (int) $g['id'] ? 'selected' : '' ?>>
                        <?= e((string) $g['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-12 col-md-5">
            <label for="rkg-year" class="form-label small text-muted text-uppercase mb-1">
                <?= e(__t('ranking.filter.year')) ?>
            </label>
            <select id="rkg-year" name="year" class="form-select form-select-sm">
                <option value="all" <?= $year === null ? 'selected' : '' ?>>
                    <?= e(__t('ranking.filter.all_years')) ?>
                </option>
                <?php foreach ($years as $y): ?>
                    <option value="<?= (int) $y ?>" <?= $year === (int) $y ? 'selected' : '' ?>>
                        <?= (int) $y ?>
                    </option>
                <?php endforeach; ?>
                <?php if ($year !== null && !in_array($year, $years, true)): ?>
                    <option value="<?= (int) $year ?>" selected><?= (int) $year ?></option>
                <?php endif; ?>
            </select>
        </div>
        <div class="col-12 col-md-2">
            <button type="submit" class="btn btn-primary btn-sm w-100">
                <?= e(__t('ranking.filter.apply')) ?>
            </button>
        </div>
    </div>
</form>

<?php if ($rows === []): ?>
    <div class="alert alert-light border" role="status">
        <strong><?= e(__t('ranking.empty')) ?></strong>
        <p class="mb-0 small text-muted"><?= e(__t('ranking.empty_hint')) ?></p>
    </div>
<?php else: ?>
    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width: 80px;"><?= e(__t('ranking.col.position')) ?></th>
                        <th><?= e(__t('ranking.col.name')) ?></th>
                        <th class="d-none d-md-table-cell" style="width: 130px;"><?= e(__t('ranking.col.rank')) ?></th>
                        <th class="d-none d-md-table-cell"><?= e(__t('ranking.col.groups')) ?></th>
                        <th class="text-end" style="width: 110px;"><?= e(__t('ranking.col.xp')) ?></th>
                        <th class="d-none d-lg-table-cell" style="width: 140px;">
                            <?= e(__t('ranking.col.last_event')) ?>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $row): ?>
                        <?php
                        $rankName  = $row['rank_name'];
                        $rankColor = is_string($row['rank_color_hex']) && preg_match('/^#[0-9A-Fa-f]{6}$/', $row['rank_color_hex'])
                            ? $row['rank_color_hex']
                            : '#6B7280';
                        ?>
                        <tr>
                            <td><strong>#<?= (int) $row['position'] ?></strong></td>
                            <td>
                                <img src="<?= e(student_avatar_url((int) $row['student_id'])) ?>"
                                     class="lms-ranking-avatar d-none d-md-inline-block me-2"
                                     alt="" width="32" height="32" loading="lazy">
                                <span title="<?= e((string) $row['name']) ?>"><?= e(format_short_name((string) $row['name'])) ?></span>
                                <?php if ($rankName !== null): ?>
                                    <span class="lms-rank-pill lms-rank-pill--inline d-md-none"
                                          style="background: <?= e($rankColor) ?>"><?= e($rankName) ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="d-none d-md-table-cell">
                                <?php if ($rankName !== null): ?>
                                    <span class="lms-rank-pill" style="background: <?= e($rankColor) ?>"><?= e($rankName) ?></span>
                                <?php else: ?>
                                    <span class="text-muted" title="<?= e(__t('ranking.no_rank')) ?>">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="d-none d-md-table-cell text-muted small">
                                <?= $row['group_names'] !== '' ? e($row['group_names']) : '<span class="text-muted">—</span>' ?>
                            </td>
                            <td class="text-end">
                                <strong><?= e(number_format((int) $row['xp'], 0, ',', '.')) ?></strong>
                                <span class="text-muted small ms-1">XP</span>
                            </td>
                            <td class="d-none d-lg-table-cell text-muted small">
                                <?= $row['last_event_at'] !== null
                                    ? e(format_short_date((string) $row['last_event_at']))
                                    : '<span class="text-muted">—</span>' ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($lastPage > 1): ?>
        <nav aria-label="<?= e(__t('ranking.pagination.aria')) ?>" class="mt-3">
            <ul class="pagination justify-content-center mb-0">
                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                    <a class="page-link" href="<?= e($qs(['page' => max(1, $page - 1)])) ?>">
                        <?= e(__t('ranking.pagination.prev')) ?>
                    </a>
                </li>
                <li class="page-item disabled">
                    <span class="page-link">
                        <?= e(__t('ranking.pagination.page_x_of_y', ['x' => (string) $page, 'y' => (string) $lastPage])) ?>
                    </span>
                </li>
                <li class="page-item <?= $page >= $lastPage ? 'disabled' : '' ?>">
                    <a class="page-link" href="<?= e($qs(['page' => min($lastPage, $page + 1)])) ?>">
                        <?= e(__t('ranking.pagination.next')) ?>
                    </a>
                </li>
            </ul>
        </nav>
    <?php endif; ?>
<?php endif; ?>
<?php
$page_content = ob_get_clean();
require LMS_ROOT . '/src/templates/layout.php';
