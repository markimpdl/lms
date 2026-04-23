<?php
declare(strict_types=1);

/**
 * /teacher/groups — listagem de grupos do tenant (E4-03).
 * Busca por nome, ordenação clicável, paginação 20/pg.
 * Tabela em ≥lg; cartões empilhados em <lg.
 * Modais: novo (standalone), renomear (único, populado via show.bs.modal),
 * excluir (partial genérico de confirmação por digitação).
 */

$tenantId = current_tenant_id();
if ($tenantId === null) {
    http_response_code(403);
    require LMS_ROOT . '/src/templates/errors/403.php';
    return;
}

$filters = [
    'q'    => trim((string) ($_GET['q']    ?? '')),
    'sort' => (string)       ($_GET['sort'] ?? 'created_at'),
    'dir'  => (string)       ($_GET['dir']  ?? 'DESC'),
];
$page = max(1, (int) ($_GET['page'] ?? 1));
$data = Group::listByTenant($tenantId, $filters, $page);
$noFilters = $filters['q'] === '';

$qsBase = array_filter([
    'q' => $filters['q'] !== '' ? $filters['q'] : null,
], static fn($v) => $v !== null);

$sortLink = static function (string $col, string $label) use ($filters, $qsBase): string {
    $isActive = $filters['sort'] === $col;
    $nextDir  = $isActive && strtoupper($filters['dir']) === 'ASC' ? 'DESC' : 'ASC';
    $q = array_merge($qsBase, ['sort' => $col, 'dir' => $nextDir]);
    $url = '/teacher/groups?' . http_build_query($q);
    $arrow = $isActive ? (strtoupper($filters['dir']) === 'ASC' ? ' ↑' : ' ↓') : '';
    return '<a href="' . e($url) . '" class="text-decoration-none text-reset">' . e($label) . $arrow . '</a>';
};

$page_title = __t('groups.title');

ob_start();
?>
<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
    <h1 class="h4 mb-0"><?= e(__t('groups.title')) ?></h1>
    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newGroupModal">
        + <?= e(__t('groups.new_button')) ?>
    </button>
</div>

<form method="GET" action="/teacher/groups" class="row g-2 mb-3">
    <div class="col-12 col-md-9">
        <input type="search" name="q" value="<?= e($filters['q']) ?>"
               class="form-control" placeholder="<?= e(__t('groups.search_placeholder')) ?>">
    </div>
    <div class="col-12 col-md-3 d-grid">
        <button type="submit" class="btn btn-outline-secondary"><?= e(__t('common.search')) ?></button>
    </div>
</form>

<?php if ($data['total'] === 0): ?>
    <div class="card card-body text-center text-muted py-5">
        <?php if ($noFilters): ?>
            <p class="lead mb-2"><?= e(__t('groups.empty.title')) ?></p>
            <p class="small mb-3"><?= e(__t('groups.empty.hint')) ?></p>
            <div>
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newGroupModal">
                    + <?= e(__t('groups.new_button')) ?>
                </button>
            </div>
        <?php else: ?>
            <p class="mb-0"><?= e(__t('groups.no_results')) ?></p>
        <?php endif; ?>
    </div>
<?php else: ?>

    <!-- ≥ lg: tabela -->
    <div class="d-none d-lg-block table-responsive">
        <table class="table align-middle">
            <thead>
                <tr>
                    <th><?= $sortLink('name', __t('groups.col.name')) ?></th>
                    <th class="text-end"><?= $sortLink('members', __t('groups.col.members')) ?></th>
                    <th><?= $sortLink('created_at', __t('groups.col.created_at')) ?></th>
                    <th class="text-end"><?= e(__t('groups.col.actions')) ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($data['rows'] as $row): ?>
                <?php
                    $id    = (int)    $row['id'];
                    $name  = (string) $row['name'];
                    $count = (int)    $row['members_count'];
                    $countsJson = htmlspecialchars(
                        json_encode([
                            __t('groups.delete.counts.members', ['count' => (string) $count]),
                        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                        ENT_QUOTES, 'UTF-8'
                    );
                ?>
                <tr>
                    <td class="fw-semibold"><?= e($name) ?></td>
                    <td class="text-end"><?= $count ?></td>
                    <td class="small text-muted"><?= e(substr((string) $row['created_at'], 0, 10)) ?></td>
                    <td class="text-end">
                        <div class="d-flex justify-content-end gap-1">
                            <button type="button" class="btn btn-sm btn-outline-secondary"
                                    data-bs-toggle="modal" data-bs-target="#renameGroupModal"
                                    data-group-id="<?= $id ?>"
                                    data-group-name="<?= e($name) ?>">
                                <?= e(__t('groups.action.rename')) ?>
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-danger"
                                    data-bs-toggle="modal" data-bs-target="#deleteConfirmModal"
                                    data-item-name="<?= e($name) ?>"
                                    data-action-url="/teacher/groups/<?= $id ?>/delete"
                                    data-counts="<?= $countsJson ?>">
                                <?= e(__t('groups.action.delete')) ?>
                            </button>
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
            <?php
                $id    = (int)    $row['id'];
                $name  = (string) $row['name'];
                $count = (int)    $row['members_count'];
                $countsJson = htmlspecialchars(
                    json_encode([
                        __t('groups.delete.counts.members', ['count' => (string) $count]),
                    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    ENT_QUOTES, 'UTF-8'
                );
            ?>
            <div class="card shadow-sm mb-2">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                        <h2 class="h6 mb-0"><?= e($name) ?></h2>
                        <span class="badge text-bg-secondary"><?= e(__t('groups.col.members_short', ['count' => (string) $count])) ?></span>
                    </div>
                    <div class="small text-muted mb-2">
                        <?= e(__t('groups.col.created_at')) ?>: <?= e(substr((string) $row['created_at'], 0, 10)) ?>
                    </div>
                    <div class="d-flex gap-2 flex-wrap">
                        <button type="button" class="btn btn-sm btn-outline-secondary"
                                data-bs-toggle="modal" data-bs-target="#renameGroupModal"
                                data-group-id="<?= $id ?>"
                                data-group-name="<?= e($name) ?>">
                            <?= e(__t('groups.action.rename')) ?>
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-danger"
                                data-bs-toggle="modal" data-bs-target="#deleteConfirmModal"
                                data-item-name="<?= e($name) ?>"
                                data-action-url="/teacher/groups/<?= $id ?>/delete"
                                data-counts="<?= $countsJson ?>">
                            <?= e(__t('groups.action.delete')) ?>
                        </button>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if ($data['total_pages'] > 1): ?>
        <nav aria-label="<?= e(__t('groups.pagination')) ?>" class="mt-3">
            <ul class="pagination pagination-sm justify-content-center">
                <?php for ($p = 1; $p <= $data['total_pages']; $p++): ?>
                    <li class="page-item <?= $p === $data['page'] ? 'active' : '' ?>">
                        <a class="page-link" href="/teacher/groups?<?= e(http_build_query(array_merge($qsBase, ['sort' => $filters['sort'], 'dir' => $filters['dir'], 'page' => $p]))) ?>"><?= $p ?></a>
                    </li>
                <?php endfor; ?>
            </ul>
        </nav>
    <?php endif; ?>
<?php endif; ?>

<!-- Modal: novo grupo -->
<div class="modal fade" id="newGroupModal" tabindex="-1" aria-hidden="true" aria-labelledby="newGroupModalLabel">
    <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down">
        <form method="POST" action="/teacher/groups/new" class="modal-content" novalidate>
            <?= csrf_field() ?>
            <div class="modal-header">
                <h5 class="modal-title" id="newGroupModalLabel"><?= e(__t('groups.new.title')) ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= e(__t('common.cancel')) ?>"></button>
            </div>
            <div class="modal-body">
                <label for="newGroupName" class="form-label"><?= e(__t('groups.form.name')) ?></label>
                <input type="text" name="name" id="newGroupName" class="form-control"
                       minlength="2" maxlength="100" required autocomplete="off">
                <div class="form-text"><?= e(__t('groups.form.name_hint')) ?></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?= e(__t('common.cancel')) ?></button>
                <button type="submit" class="btn btn-primary"><?= e(__t('groups.new.submit')) ?></button>
            </div>
        </form>
    </div>
</div>

<!-- Modal único: renomear grupo (populado via show.bs.modal) -->
<div class="modal fade" id="renameGroupModal" tabindex="-1" aria-hidden="true" aria-labelledby="renameGroupModalLabel">
    <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down">
        <form id="renameGroupForm" method="POST" action="" class="modal-content" novalidate>
            <?= csrf_field() ?>
            <div class="modal-header">
                <h5 class="modal-title" id="renameGroupModalLabel"><?= e(__t('groups.rename.title')) ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= e(__t('common.cancel')) ?>"></button>
            </div>
            <div class="modal-body">
                <label for="renameGroupName" class="form-label"><?= e(__t('groups.form.name')) ?></label>
                <input type="text" name="name" id="renameGroupName" class="form-control"
                       minlength="2" maxlength="100" required autocomplete="off">
                <div class="form-text"><?= e(__t('groups.form.name_hint')) ?></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?= e(__t('common.cancel')) ?></button>
                <button type="submit" class="btn btn-primary"><?= e(__t('groups.rename.submit')) ?></button>
            </div>
        </form>
    </div>
</div>

<?php require LMS_ROOT . '/src/templates/partials/delete_confirm_modal.php'; ?>

<script>
(function () {
    var modal = document.getElementById('renameGroupModal');
    if (!modal) return;
    var form    = document.getElementById('renameGroupForm');
    var nameEl  = document.getElementById('renameGroupName');
    modal.addEventListener('show.bs.modal', function (event) {
        var btn = event.relatedTarget;
        if (!btn) return;
        var gid  = btn.getAttribute('data-group-id')   || '';
        var gnm  = btn.getAttribute('data-group-name') || '';
        form.action   = '/teacher/groups/' + gid + '/rename';
        nameEl.value  = gnm;
        setTimeout(function () { nameEl.focus(); nameEl.select(); }, 250);
    });
})();
</script>
<?php
$page_content = ob_get_clean();
require LMS_ROOT . '/src/templates/layout.php';
