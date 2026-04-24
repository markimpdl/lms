<?php
declare(strict_types=1);

/**
 * /teacher/ranks — CRUD de patentes por tenant (E9-01).
 *
 * GET only — lista ranks ordenados por position + modais de criar/editar +
 * botões de mover e excluir. POSTs saem pra `save.php`, `delete.php`,
 * `move.php`. Sem paginação: esperamos <20 patentes por tenant na prática.
 */

$tenantId = current_tenant_id();
if ($tenantId === null) {
    http_response_code(403);
    require LMS_ROOT . '/src/templates/errors/403.php';
    return;
}

$ranks = Rank::listForTenant($tenantId);

$page_title = __t('ranks.title');

ob_start();
?>
<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
    <div>
        <h1 class="h4 mb-0"><?= e(__t('ranks.title')) ?></h1>
        <small class="text-muted"><?= e(__t('ranks.subtitle')) ?></small>
    </div>
    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#rankFormModal" data-rank-mode="new">
        + <?= e(__t('ranks.new_button')) ?>
    </button>
</div>

<?php if ($ranks === []): ?>
    <div class="card card-body text-center text-muted py-5">
        <p class="lead mb-2"><?= e(__t('ranks.empty.title')) ?></p>
        <p class="small mb-3"><?= e(__t('ranks.empty.hint')) ?></p>
        <div>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#rankFormModal" data-rank-mode="new">
                + <?= e(__t('ranks.new_button')) ?>
            </button>
        </div>
    </div>
<?php else: ?>
    <?php $lastIdx = count($ranks) - 1; ?>

    <!-- ≥ md: tabela -->
    <div class="d-none d-md-block table-responsive">
        <table class="table align-middle">
            <thead>
                <tr>
                    <th style="width:40px"></th>
                    <th><?= e(__t('ranks.col.name')) ?></th>
                    <th><?= e(__t('ranks.col.range')) ?></th>
                    <th class="text-end" style="width:220px"><?= e(__t('ranks.col.actions')) ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($ranks as $i => $r): ?>
                <?php
                    $id    = (int)    $r['id'];
                    $name  = (string) $r['name'];
                    $xmin  = (int)    $r['xp_min'];
                    $xmax  = $r['xp_max'] === null ? null : (int) $r['xp_max'];
                    $color = (string) $r['color_hex'];
                    $rangeLabel = $xmax === null
                        ? __t('ranks.range.top', ['min' => (string) $xmin])
                        : __t('ranks.range.band', ['min' => (string) $xmin, 'max' => (string) ($xmax - 1)]);
                ?>
                <tr>
                    <td>
                        <span class="d-inline-block rounded-circle" style="width:18px;height:18px;background:<?= e($color) ?>;border:2px solid #fff;box-shadow:0 0 0 1px #E5E7EB;"></span>
                    </td>
                    <td class="fw-semibold"><?= e($name) ?></td>
                    <td class="text-muted"><?= e($rangeLabel) ?></td>
                    <td class="text-end">
                        <div class="d-flex justify-content-end gap-1">
                            <form method="POST" action="/teacher/ranks/<?= $id ?>/move-up" class="m-0">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-sm btn-outline-secondary" <?= $i === 0 ? 'disabled' : '' ?> title="<?= e(__t('ranks.action.move_up')) ?>">↑</button>
                            </form>
                            <form method="POST" action="/teacher/ranks/<?= $id ?>/move-down" class="m-0">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-sm btn-outline-secondary" <?= $i === $lastIdx ? 'disabled' : '' ?> title="<?= e(__t('ranks.action.move_down')) ?>">↓</button>
                            </form>
                            <button type="button" class="btn btn-sm btn-outline-secondary"
                                    data-bs-toggle="modal" data-bs-target="#rankFormModal"
                                    data-rank-mode="edit"
                                    data-rank-id="<?= $id ?>"
                                    data-rank-name="<?= e($name) ?>"
                                    data-rank-xp-min="<?= $xmin ?>"
                                    data-rank-xp-max="<?= $xmax === null ? '' : $xmax ?>"
                                    data-rank-color="<?= e($color) ?>">
                                <?= e(__t('common.edit')) ?>
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-danger"
                                    data-bs-toggle="modal" data-bs-target="#rankDeleteModal"
                                    data-rank-id="<?= $id ?>"
                                    data-rank-name="<?= e($name) ?>">
                                <?= e(__t('common.delete')) ?>
                            </button>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- < md: cartões -->
    <div class="d-md-none">
        <?php foreach ($ranks as $i => $r): ?>
            <?php
                $id    = (int)    $r['id'];
                $name  = (string) $r['name'];
                $xmin  = (int)    $r['xp_min'];
                $xmax  = $r['xp_max'] === null ? null : (int) $r['xp_max'];
                $color = (string) $r['color_hex'];
                $rangeLabel = $xmax === null
                    ? __t('ranks.range.top', ['min' => (string) $xmin])
                    : __t('ranks.range.band', ['min' => (string) $xmin, 'max' => (string) ($xmax - 1)]);
            ?>
            <div class="card shadow-sm mb-2">
                <div class="card-body">
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <span class="d-inline-block rounded-circle" style="width:18px;height:18px;background:<?= e($color) ?>;border:2px solid #fff;box-shadow:0 0 0 1px #E5E7EB;"></span>
                        <h2 class="h6 mb-0"><?= e($name) ?></h2>
                    </div>
                    <div class="small text-muted mb-2"><?= e($rangeLabel) ?></div>
                    <div class="d-flex flex-wrap gap-2">
                        <form method="POST" action="/teacher/ranks/<?= $id ?>/move-up" class="m-0">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-sm btn-outline-secondary" <?= $i === 0 ? 'disabled' : '' ?>>↑ <?= e(__t('ranks.action.move_up')) ?></button>
                        </form>
                        <form method="POST" action="/teacher/ranks/<?= $id ?>/move-down" class="m-0">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-sm btn-outline-secondary" <?= $i === $lastIdx ? 'disabled' : '' ?>>↓ <?= e(__t('ranks.action.move_down')) ?></button>
                        </form>
                        <button type="button" class="btn btn-sm btn-outline-secondary"
                                data-bs-toggle="modal" data-bs-target="#rankFormModal"
                                data-rank-mode="edit"
                                data-rank-id="<?= $id ?>"
                                data-rank-name="<?= e($name) ?>"
                                data-rank-xp-min="<?= $xmin ?>"
                                data-rank-xp-max="<?= $xmax === null ? '' : $xmax ?>"
                                data-rank-color="<?= e($color) ?>">
                            <?= e(__t('common.edit')) ?>
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-danger"
                                data-bs-toggle="modal" data-bs-target="#rankDeleteModal"
                                data-rank-id="<?= $id ?>"
                                data-rank-name="<?= e($name) ?>">
                            <?= e(__t('common.delete')) ?>
                        </button>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- Modal único: criar ou editar patente (populado via show.bs.modal) -->
<div class="modal fade" id="rankFormModal" tabindex="-1" aria-hidden="true" aria-labelledby="rankFormModalLabel">
    <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down">
        <form id="rankForm" method="POST" action="/teacher/ranks/save" class="modal-content" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="id" id="rankFormId" value="">
            <div class="modal-header">
                <h5 class="modal-title" id="rankFormModalLabel"><?= e(__t('ranks.new.title')) ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= e(__t('common.cancel')) ?>"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label for="rankName" class="form-label"><?= e(__t('ranks.form.name')) ?></label>
                    <input type="text" name="name" id="rankName" class="form-control"
                           minlength="2" maxlength="80" required autocomplete="off">
                    <div class="form-text"><?= e(__t('ranks.form.name_hint')) ?></div>
                </div>
                <div class="row g-3">
                    <div class="col-12 col-md-6">
                        <label for="rankXpMin" class="form-label"><?= e(__t('ranks.form.xp_min')) ?></label>
                        <input type="number" name="xp_min" id="rankXpMin" class="form-control"
                               min="0" max="99999999" step="1" required>
                    </div>
                    <div class="col-12 col-md-6">
                        <label for="rankXpMax" class="form-label"><?= e(__t('ranks.form.xp_max')) ?></label>
                        <input type="number" name="xp_max" id="rankXpMax" class="form-control"
                               min="1" max="99999999" step="1"
                               placeholder="<?= e(__t('ranks.form.xp_max_placeholder')) ?>">
                        <div class="form-text"><?= e(__t('ranks.form.xp_max_hint')) ?></div>
                    </div>
                </div>
                <div class="mb-0 mt-3">
                    <label for="rankColor" class="form-label"><?= e(__t('ranks.form.color')) ?></label>
                    <input type="color" name="color_hex" id="rankColor" class="form-control form-control-color" value="#6366F1">
                    <div class="form-text"><?= e(__t('ranks.form.color_hint')) ?></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?= e(__t('common.cancel')) ?></button>
                <button type="submit" class="btn btn-primary" id="rankFormSubmit"><?= e(__t('common.save')) ?></button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: confirmar exclusão (simples — sem cascade) -->
<div class="modal fade" id="rankDeleteModal" tabindex="-1" aria-hidden="true" aria-labelledby="rankDeleteModalLabel">
    <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down">
        <form id="rankDeleteForm" method="POST" action="" class="modal-content" novalidate>
            <?= csrf_field() ?>
            <div class="modal-header">
                <h5 class="modal-title" id="rankDeleteModalLabel"><?= e(__t('ranks.delete.title')) ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= e(__t('common.cancel')) ?>"></button>
            </div>
            <div class="modal-body">
                <p class="mb-0"><?= e(__t('ranks.delete.confirm', ['name' => '__RANK_NAME__'])) ?></p>
                <p id="rankDeleteName" class="fw-semibold mt-2 mb-0"></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?= e(__t('common.cancel')) ?></button>
                <button type="submit" class="btn btn-danger"><?= e(__t('common.delete')) ?></button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    // Populate form modal (new vs edit)
    var formModal = document.getElementById('rankFormModal');
    var form      = document.getElementById('rankForm');
    var label     = document.getElementById('rankFormModalLabel');
    var submitBtn = document.getElementById('rankFormSubmit');
    var idEl    = document.getElementById('rankFormId');
    var nameEl  = document.getElementById('rankName');
    var minEl   = document.getElementById('rankXpMin');
    var maxEl   = document.getElementById('rankXpMax');
    var colorEl = document.getElementById('rankColor');

    formModal.addEventListener('show.bs.modal', function (event) {
        var btn = event.relatedTarget;
        var mode = btn ? (btn.getAttribute('data-rank-mode') || 'new') : 'new';
        if (mode === 'edit') {
            label.textContent = <?= json_encode(__t('ranks.edit.title')) ?>;
            idEl.value    = btn.getAttribute('data-rank-id')     || '';
            nameEl.value  = btn.getAttribute('data-rank-name')   || '';
            minEl.value   = btn.getAttribute('data-rank-xp-min') || '';
            maxEl.value   = btn.getAttribute('data-rank-xp-max') || '';
            colorEl.value = btn.getAttribute('data-rank-color')  || '#6366F1';
        } else {
            label.textContent = <?= json_encode(__t('ranks.new.title')) ?>;
            idEl.value    = '';
            nameEl.value  = '';
            minEl.value   = '';
            maxEl.value   = '';
            colorEl.value = '#6366F1';
        }
        setTimeout(function () { nameEl.focus(); nameEl.select(); }, 250);
    });

    // Populate delete modal
    var delModal = document.getElementById('rankDeleteModal');
    var delForm  = document.getElementById('rankDeleteForm');
    var delName  = document.getElementById('rankDeleteName');
    delModal.addEventListener('show.bs.modal', function (event) {
        var btn = event.relatedTarget;
        if (!btn) return;
        var rid = btn.getAttribute('data-rank-id')   || '';
        var rnm = btn.getAttribute('data-rank-name') || '';
        delForm.action     = '/teacher/ranks/' + rid + '/delete';
        delName.textContent = rnm;
    });
})();
</script>
<?php
$page_content = ob_get_clean();
require LMS_ROOT . '/src/templates/layout.php';
