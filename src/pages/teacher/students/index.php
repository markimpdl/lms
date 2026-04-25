<?php
declare(strict_types=1);

/**
 * /teacher/students — listagem de alunos do tenant (E4-01).
 * Busca, filtro ativo/inativo/todos, ordenação clicável, paginação 20/pg.
 * Tabela em ≥lg; cartões empilhados em <lg (mobile-first).
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
if (!in_array($filters['status'], ['active', 'inactive', 'all'], true)) {
    $filters['status'] = 'active';
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$data = Student::listByTenant($tenantId, $filters, $page);
$noFilters = $filters['q'] === '' && $filters['status'] === 'active';

$qsBase = array_filter([
    'q'      => $filters['q']      !== ''        ? $filters['q']      : null,
    'status' => $filters['status'] !== 'active'  ? $filters['status'] : null,
], static fn($v) => $v !== null);

$sortLink = static function (string $col, string $label) use ($filters, $qsBase): string {
    $isActive = $filters['sort'] === $col;
    $nextDir  = $isActive && strtoupper($filters['dir']) === 'ASC' ? 'DESC' : 'ASC';
    $q = array_merge($qsBase, ['sort' => $col, 'dir' => $nextDir]);
    $url = '/teacher/students?' . http_build_query($q);
    $arrow = $isActive ? (strtoupper($filters['dir']) === 'ASC' ? ' ↑' : ' ↓') : '';
    return '<a href="' . e($url) . '" class="text-decoration-none text-reset">' . e($label) . $arrow . '</a>';
};

$toggleButton = static function (array $row): string {
    $id = (int) $row['id'];
    if ((int) $row['active'] === 1) {
        return '<button type="button" class="btn btn-sm btn-outline-secondary"'
             . ' data-bs-toggle="modal" data-bs-target="#toggle-modal"'
             . ' data-student-id="' . $id . '"'
             . ' data-student-name="' . e((string) $row['name']) . '">'
             . e(__t('students.action.deactivate')) . '</button>';
    }
    return '<form method="POST" action="/teacher/students/' . $id . '/toggle" class="d-inline m-0">'
         . csrf_field()
         . '<button type="submit" class="btn btn-sm btn-outline-success">'
         . e(__t('students.action.reactivate'))
         . '</button></form>';
};

$page_title = __t('students.title');

ob_start();
?>
<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
    <h1 class="h4 mb-0"><?= e(__t('students.title')) ?></h1>
    <a href="/teacher/students/new" class="btn btn-primary">
        + <?= e(__t('students.new_button')) ?>
    </a>
</div>

<form method="GET" action="/teacher/students" class="row g-2 mb-3">
    <div class="col-12 col-md-6">
        <input type="search" name="q" value="<?= e($filters['q']) ?>"
               class="form-control" placeholder="<?= e(__t('students.search_placeholder')) ?>">
    </div>
    <div class="col-8 col-md-4">
        <select name="status" class="form-select">
            <option value="active"   <?= $filters['status'] === 'active'   ? 'selected' : '' ?>><?= e(__t('students.filter.active')) ?></option>
            <option value="inactive" <?= $filters['status'] === 'inactive' ? 'selected' : '' ?>><?= e(__t('students.filter.inactive')) ?></option>
            <option value="all"      <?= $filters['status'] === 'all'      ? 'selected' : '' ?>><?= e(__t('students.filter.all')) ?></option>
        </select>
    </div>
    <div class="col-4 col-md-2 d-grid">
        <button type="submit" class="btn btn-outline-secondary"><?= e(__t('common.search')) ?></button>
    </div>
</form>

<?php if ($data['total'] === 0): ?>
    <div class="card card-body text-center text-muted py-5">
        <?php if ($noFilters): ?>
            <p class="lead mb-2"><?= e(__t('students.empty.title')) ?></p>
            <p class="small mb-3"><?= e(__t('students.empty.hint')) ?></p>
            <div><a href="/teacher/students/new" class="btn btn-primary">+ <?= e(__t('students.new_button')) ?></a></div>
        <?php else: ?>
            <p class="mb-0"><?= e(__t('students.no_results')) ?></p>
        <?php endif; ?>
    </div>
<?php else: ?>

    <!-- ≥ lg: tabela -->
    <div class="d-none d-lg-block table-responsive">
        <table class="table align-middle">
            <thead>
                <tr>
                    <th><?= $sortLink('name', __t('students.col.name')) ?></th>
                    <th><?= e(__t('students.col.id_document')) ?></th>
                    <th><?= $sortLink('email', __t('students.col.email')) ?></th>
                    <th><?= $sortLink('language', __t('students.col.language')) ?></th>
                    <th><?= $sortLink('active', __t('students.col.status')) ?></th>
                    <th class="text-end"><?= e(__t('students.col.enrollments')) ?></th>
                    <th class="text-end"><?= e(__t('students.col.groups')) ?></th>
                    <th><?= $sortLink('created_at', __t('students.col.created_at')) ?></th>
                    <th><?= $sortLink('last_login_at', __t('students.col.last_login')) ?></th>
                    <th class="text-end"><?= e(__t('students.col.actions')) ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($data['rows'] as $row): ?>
                <?php $fullName = (string) $row['name']; $shortName = format_short_name($fullName); ?>
                <tr>
                    <td>
                        <a href="/teacher/students/<?= (int) $row['id'] ?>"
                           class="fw-semibold text-decoration-none"
                           title="<?= e($fullName) ?>">
                            <?= e($shortName) ?>
                        </a>
                    </td>
                    <td class="small text-muted">
                        <?= !empty($row['id_document'])
                            ? e((string) $row['id_document'])
                            : '<span class="text-muted">—</span>' ?>
                    </td>
                    <td class="text-break"><?= e((string) $row['email']) ?></td>
                    <td><span class="badge text-bg-secondary"><?= e(strtoupper((string) $row['language'])) ?></span></td>
                    <td>
                        <?php if ((int) $row['active'] === 1): ?>
                            <span class="badge text-bg-success"><?= e(__t('students.status.active')) ?></span>
                        <?php else: ?>
                            <span class="badge text-bg-secondary"><?= e(__t('students.status.inactive')) ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end"><?= (int) $row['enrollments_count'] ?></td>
                    <td class="text-end"><?= (int) $row['groups_count'] ?></td>
                    <td class="small text-muted"><?= e(substr((string) $row['created_at'], 0, 10)) ?></td>
                    <td class="small text-muted">
                        <?= $row['last_login_at'] !== null
                            ? e(substr((string) $row['last_login_at'], 0, 16))
                            : '<span class="text-muted">' . e(__t('students.never')) . '</span>' ?>
                    </td>
                    <td class="text-end">
                        <div class="d-flex justify-content-end gap-1">
                            <a href="/teacher/students/<?= (int) $row['id'] ?>" class="btn btn-sm btn-outline-primary">
                                <?= e(__t('students.action.view')) ?>
                            </a>
                            <?= $toggleButton($row) ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- < lg: cartões -->
    <div class="d-lg-none">
        <?php foreach ($data['rows'] as $row): ?>
            <div class="card shadow-sm mb-2">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                        <a href="/teacher/students/<?= (int) $row['id'] ?>" class="h6 mb-0 text-decoration-none">
                            <?= e((string) $row['name']) ?>
                        </a>
                        <?php if ((int) $row['active'] === 1): ?>
                            <span class="badge text-bg-success"><?= e(__t('students.status.active')) ?></span>
                        <?php else: ?>
                            <span class="badge text-bg-secondary"><?= e(__t('students.status.inactive')) ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="small text-muted text-break mb-2"><?= e((string) $row['email']) ?></div>
                    <div class="d-flex flex-wrap gap-3 small mb-2">
                        <div><strong><?= e(__t('students.col.language')) ?>:</strong> <?= e(strtoupper((string) $row['language'])) ?></div>
                        <div><strong><?= e(__t('students.col.enrollments')) ?>:</strong> <?= (int) $row['enrollments_count'] ?></div>
                        <div><strong><?= e(__t('students.col.groups')) ?>:</strong> <?= (int) $row['groups_count'] ?></div>
                    </div>
                    <div class="d-flex gap-2 flex-wrap">
                        <a href="/teacher/students/<?= (int) $row['id'] ?>" class="btn btn-sm btn-outline-primary">
                            <?= e(__t('students.action.view')) ?>
                        </a>
                        <?= $toggleButton($row) ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if ($data['total_pages'] > 1): ?>
        <nav aria-label="<?= e(__t('students.pagination')) ?>" class="mt-3">
            <ul class="pagination pagination-sm justify-content-center">
                <?php for ($p = 1; $p <= $data['total_pages']; $p++): ?>
                    <li class="page-item <?= $p === $data['page'] ? 'active' : '' ?>">
                        <a class="page-link" href="/teacher/students?<?= e(http_build_query(array_merge($qsBase, ['sort' => $filters['sort'], 'dir' => $filters['dir'], 'page' => $p]))) ?>"><?= $p ?></a>
                    </li>
                <?php endfor; ?>
            </ul>
        </nav>
    <?php endif; ?>
<?php endif; ?>

<!-- Modal único de confirmação "Desativar aluno" -->
<div class="modal fade" id="toggle-modal" tabindex="-1" aria-hidden="true" aria-labelledby="toggle-modal-title">
    <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title h5" id="toggle-modal-title"><?= e(__t('students.deactivate.title')) ?></h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= e(__t('common.cancel')) ?>"></button>
            </div>
            <div class="modal-body">
                <p><?= e(__t('students.deactivate.intro_prefix')) ?><strong id="toggle-name"></strong><?= e(__t('students.deactivate.intro_suffix')) ?></p>
                <p class="small text-muted mb-0"><?= e(__t('students.deactivate.note')) ?></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                    <?= e(__t('common.cancel')) ?>
                </button>
                <form id="toggle-form" method="POST" action="" class="m-0">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-danger">
                        <?= e(__t('students.deactivate.confirm')) ?>
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var modal = document.getElementById('toggle-modal');
    if (!modal) return;
    var nameEl = document.getElementById('toggle-name');
    var form   = document.getElementById('toggle-form');
    modal.addEventListener('show.bs.modal', function (event) {
        var btn = event.relatedTarget;
        if (!btn) return;
        nameEl.textContent = btn.getAttribute('data-student-name') || '';
        form.action = '/teacher/students/' + btn.getAttribute('data-student-id') + '/toggle';
    });
})();
</script>
<?php
$page_content = ob_get_clean();
require LMS_ROOT . '/src/templates/layout.php';
