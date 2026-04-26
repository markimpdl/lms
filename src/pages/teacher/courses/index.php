<?php
declare(strict_types=1);

/**
 * /teacher/courses — listagem de cursos do tenant (E3-01).
 * Busca, filtro de status (ativos/arquivados/todos), ordenação clicável e
 * paginação 20/pg. Tabela em ≥lg; cartões empilhados em <lg (mobile-first).
 */

$tenantId = current_tenant_id();
if ($tenantId === null) {
    http_response_code(403);
    require LMS_ROOT . '/src/templates/errors/403.php';
    return;
}

$filters = [
    'q'      => trim((string) ($_GET['q']      ?? '')),
    'status' => (string)       ($_GET['status'] ?? 'active'),
    'sort'   => (string)       ($_GET['sort']   ?? 'created_at'),
    'dir'    => (string)       ($_GET['dir']    ?? 'DESC'),
];
if (!in_array($filters['status'], ['active', 'archived', 'all'], true)) {
    $filters['status'] = 'active';
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$data = Course::listByTenant($tenantId, $filters, $page);

$qsBase = array_filter([
    'q'      => $filters['q']  !== '' ? $filters['q']  : null,
    'status' => $filters['status'] !== 'active' ? $filters['status'] : null,
], static fn($v) => $v !== null);

function sort_link(string $col, string $label, array $filters, array $qsBase): string
{
    $isActive = $filters['sort'] === $col;
    $nextDir  = $isActive && strtoupper($filters['dir']) === 'ASC' ? 'DESC' : 'ASC';
    $q = array_merge($qsBase, ['sort' => $col, 'dir' => $nextDir]);
    $url = '/teacher/courses?' . http_build_query($q);
    $arrow = $isActive ? (strtoupper($filters['dir']) === 'ASC' ? ' ↑' : ' ↓') : '';
    return '<a href="' . e($url) . '" class="text-decoration-none text-reset">' . e($label) . $arrow . '</a>';
}

$page_title = __t('courses.index.title');

ob_start();
?>
<header class="lms-dashboard-header">
    <div>
        <span class="lms-dashboard-eyebrow"><?= e(__t('teacher.courses.eyebrow')) ?></span>
        <h1 class="lms-dashboard-title"><?= e(__t('courses.index.title')) ?></h1>
    </div>
    <a href="/teacher/courses/new" class="btn btn-primary">
        + <?= e(__t('courses.new_button')) ?>
    </a>
</header>

<form method="GET" action="/teacher/courses" class="row g-2 mb-3">
    <div class="col-12 col-md-6">
        <input type="search" name="q" value="<?= e($filters['q']) ?>"
               class="form-control" placeholder="<?= e(__t('courses.search_placeholder')) ?>">
    </div>
    <div class="col-8 col-md-4">
        <select name="status" class="form-select">
            <option value="active"   <?= $filters['status'] === 'active'   ? 'selected' : '' ?>><?= e(__t('courses.filter.active')) ?></option>
            <option value="archived" <?= $filters['status'] === 'archived' ? 'selected' : '' ?>><?= e(__t('courses.filter.archived')) ?></option>
            <option value="all"      <?= $filters['status'] === 'all'      ? 'selected' : '' ?>><?= e(__t('courses.filter.all')) ?></option>
        </select>
    </div>
    <div class="col-4 col-md-2 d-grid">
        <button type="submit" class="btn btn-outline-secondary"><?= e(__t('common.filter')) ?></button>
    </div>
</form>

<?php if ($data['total'] === 0): ?>
    <div class="card card-body text-center text-muted py-5">
        <p class="mb-2"><?= e(__t('courses.empty.title')) ?></p>
        <p class="small mb-3"><?= e(__t('courses.empty.hint')) ?></p>
        <div><a href="/teacher/courses/new" class="btn btn-primary">+ <?= e(__t('courses.new_button')) ?></a></div>
    </div>
<?php else: ?>
    <div class="d-none d-lg-block table-responsive">
        <table class="table align-middle">
            <thead>
                <tr>
                    <th><?= sort_link('name', __t('courses.col.name'), $filters, $qsBase) ?></th>
                    <th><?= sort_link('year', __t('courses.col.year'), $filters, $qsBase) ?></th>
                    <th><?= e(__t('courses.col.language')) ?></th>
                    <th class="text-end"><?= e(__t('courses.col.cc_count')) ?></th>
                    <th class="text-end"><?= e(__t('courses.col.cu_count')) ?></th>
                    <th><?= e(__t('courses.col.status')) ?></th>
                    <th><?= sort_link('created_at', __t('courses.col.created_at'), $filters, $qsBase) ?></th>
                    <th class="text-end"><?= e(__t('courses.col.actions')) ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($data['rows'] as $c): ?>
                <tr>
                    <td><a href="/teacher/courses/<?= (int) $c['id'] ?>" class="text-decoration-none fw-semibold"><?= e((string) $c['name']) ?></a></td>
                    <td><?= (int) $c['year'] ?></td>
                    <td><span class="badge text-bg-secondary"><?= e(strtoupper((string) $c['language'])) ?></span></td>
                    <td class="text-end"><?= (int) $c['cc_count'] ?></td>
                    <td class="text-end"><?= (int) $c['cu_count'] ?></td>
                    <td>
                        <?php if ((int) $c['archived'] === 1): ?>
                            <span class="badge text-bg-warning"><?= e(__t('courses.status.archived')) ?></span>
                        <?php else: ?>
                            <span class="badge text-bg-success"><?= e(__t('courses.status.active')) ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="small text-muted"><?= e(date('Y-m-d', strtotime((string) $c['created_at']))) ?></td>
                    <td class="text-end">
                        <a href="/teacher/courses/<?= (int) $c['id'] ?>" class="btn btn-sm btn-outline-secondary"><?= e(__t('courses.action.view')) ?></a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="d-lg-none">
        <?php foreach ($data['rows'] as $c): ?>
            <div class="card mb-2">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start gap-2">
                        <div>
                            <a href="/teacher/courses/<?= (int) $c['id'] ?>" class="h6 mb-1 d-block text-decoration-none"><?= e((string) $c['name']) ?></a>
                            <small class="text-muted"><?= (int) $c['year'] ?> · <?= e(strtoupper((string) $c['language'])) ?></small>
                        </div>
                        <?php if ((int) $c['archived'] === 1): ?>
                            <span class="badge text-bg-warning"><?= e(__t('courses.status.archived')) ?></span>
                        <?php else: ?>
                            <span class="badge text-bg-success"><?= e(__t('courses.status.active')) ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="small text-muted mt-2">
                        <?= (int) $c['cc_count'] ?> <?= e(__t('courses.col.cc_count')) ?> ·
                        <?= (int) $c['cu_count'] ?> <?= e(__t('courses.col.cu_count')) ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if ($data['total_pages'] > 1): ?>
        <nav aria-label="pagination" class="mt-3">
            <ul class="pagination pagination-sm justify-content-center">
                <?php for ($p = 1; $p <= $data['total_pages']; $p++): ?>
                    <li class="page-item <?= $p === $data['page'] ? 'active' : '' ?>">
                        <a class="page-link" href="/teacher/courses?<?= e(http_build_query(array_merge($qsBase, ['sort' => $filters['sort'], 'dir' => $filters['dir'], 'page' => $p]))) ?>"><?= $p ?></a>
                    </li>
                <?php endfor; ?>
            </ul>
        </nav>
    <?php endif; ?>
<?php endif; ?>
<?php
$page_content = ob_get_clean();
require LMS_ROOT . '/src/templates/layout.php';
