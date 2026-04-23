<?php
declare(strict_types=1);

/**
 * /teacher/courses/{c}/cc/{cc} — detalhes da CC com lista de CUs (E3-03).
 * Validação estrita: se a CC não pertence ao curso da URL (mesmo que ambos
 * pertençam ao tenant) → 404. Mobile-first; modais Bootstrap para criar e
 * renomear CU, com editar populado via show.bs.modal + data-*.
 */

$tenantId = current_tenant_id();
if ($tenantId === null) {
    http_response_code(403);
    require LMS_ROOT . '/src/templates/errors/403.php';
    return;
}

$courseId = (int) ($_REQUEST['course_id'] ?? 0);
$ccId     = (int) ($_REQUEST['cc_id']     ?? 0);

$cc = CompetenceUnit::findCcInCourse($ccId, $courseId, $tenantId);
if ($cc === null) {
    http_response_code(404);
    require LMS_ROOT . '/src/templates/errors/404.php';
    return;
}

$isArchived = (int) $cc['course_archived'] === 1;
$cus = CompetenceUnit::listByCc($ccId, $tenantId);
$cuCount = count($cus);
$tree = curriculum_tree($courseId, $tenantId);
$activeCcId = $ccId;
$activeCuId = 0;
$ccCountsFormatted = format_delete_counts(CoreCompetency::countDescendants($ccId, $tenantId));

$page_title = (string) $cc['name'];

ob_start();
?>
<?= breadcrumbs([
    ['label' => __t('courses.index.title'), 'url' => '/teacher/courses'],
    ['label' => (string) $cc['course_name'], 'url' => '/teacher/courses/' . $courseId],
    ['label' => (string) $cc['name']],
]) ?>

<button type="button" class="btn btn-outline-secondary d-md-none mb-2"
        data-bs-toggle="offcanvas" data-bs-target="#curriculumOffcanvas">
    ☰ <?= e(__t('nav.curriculum.open')) ?>
</button>

<div class="row g-3">
<aside class="col-md-3 d-none d-md-block">
    <div class="card shadow-sm sticky-top" style="top: 1rem;">
        <div class="card-body p-2">
            <?php require LMS_ROOT . '/src/templates/partials/curriculum_nav.php'; ?>
        </div>
    </div>
</aside>
<div class="col-12 col-md-9">

<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
    <div>
        <h1 class="h4 mb-1"><?= e((string) $cc['name']) ?></h1>
        <small class="text-muted"><?= e(__t('cu.section.subtitle', ['count' => (string) $cuCount])) ?></small>
    </div>
    <?php if (!$isArchived): ?>
        <div class="d-flex gap-2 flex-wrap">
            <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#cuNewModal">
                + <?= e(__t('cu.new_button')) ?>
            </button>
            <button type="button" class="btn btn-sm btn-outline-danger"
                    data-bs-toggle="modal" data-bs-target="#deleteConfirmModal"
                    data-item-name="<?= e((string) $cc['name']) ?>"
                    data-action-url="/teacher/cc/<?= $ccId ?>/delete"
                    data-counts="<?= e(json_encode($ccCountsFormatted, JSON_UNESCAPED_UNICODE)) ?>">
                <?= e(__t('delete.action')) ?>
            </button>
        </div>
    <?php endif; ?>
</div>

<?php if ($isArchived): ?>
    <div class="alert alert-warning" role="alert">
        <?= e(__t('courses.edit.archived_notice')) ?>
    </div>
<?php endif; ?>

<div class="card shadow-sm">
    <?php if ($cuCount === 0): ?>
        <div class="card-body text-center text-muted py-4">
            <p class="mb-0"><?= e(__t('cu.empty')) ?></p>
        </div>
    <?php else: ?>
        <ul class="list-group list-group-flush">
            <?php foreach ($cus as $i => $cu): ?>
                <?php $cuId = (int) $cu['id']; $cuName = (string) $cu['name']; ?>
                <li class="list-group-item d-flex align-items-center gap-2">
                    <span class="text-muted small" style="min-width: 1.5rem;"><?= $i + 1 ?>.</span>
                    <div class="flex-grow-1">
                        <a href="/teacher/cu/<?= $cuId ?>" class="fw-semibold text-decoration-none">
                            <?= e($cuName) ?>
                        </a>
                    </div>
                    <?php if (!$isArchived): ?>
                        <?php $cuCountsFormatted = format_delete_counts([
                            'activities'  => (int) $cu['activities_count'],
                            'evaluations' => (int) $cu['evaluations_count'],
                        ]); ?>
                        <div class="d-flex gap-1">
                            <form method="POST" action="/teacher/cu/<?= $cuId ?>/move-up" class="d-inline">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-sm btn-outline-secondary" <?= $i === 0 ? 'disabled' : '' ?>
                                        aria-label="<?= e(__t('cu.action.move_up')) ?>">↑</button>
                            </form>
                            <form method="POST" action="/teacher/cu/<?= $cuId ?>/move-down" class="d-inline">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-sm btn-outline-secondary" <?= $i === $cuCount - 1 ? 'disabled' : '' ?>
                                        aria-label="<?= e(__t('cu.action.move_down')) ?>">↓</button>
                            </form>
                            <button type="button" class="btn btn-sm btn-outline-primary"
                                    data-bs-toggle="modal" data-bs-target="#cuEditModal"
                                    data-cu-id="<?= $cuId ?>" data-cu-name="<?= e($cuName) ?>"
                                    aria-label="<?= e(__t('cu.action.rename')) ?>">✎</button>
                            <button type="button" class="btn btn-sm btn-outline-danger"
                                    data-bs-toggle="modal" data-bs-target="#deleteConfirmModal"
                                    data-item-name="<?= e($cuName) ?>"
                                    data-action-url="/teacher/cu/<?= $cuId ?>/delete"
                                    data-counts="<?= e(json_encode($cuCountsFormatted, JSON_UNESCAPED_UNICODE)) ?>"
                                    aria-label="<?= e(__t('delete.action')) ?>">🗑</button>
                        </div>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>

<?php if (!$isArchived): ?>
<!-- Modal: Nova CU -->
<div class="modal fade" id="cuNewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down">
        <form method="POST" action="/teacher/cu/new" class="modal-content" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="course_id" value="<?= $courseId ?>">
            <input type="hidden" name="cc_id"     value="<?= $ccId ?>">
            <div class="modal-header">
                <h5 class="modal-title"><?= e(__t('cu.new.title')) ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <label for="cuNewName" class="form-label"><?= e(__t('cu.form.name')) ?></label>
                <input type="text" id="cuNewName" name="name" class="form-control form-control-lg"
                       required minlength="2" maxlength="150" autofocus>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?= e(__t('common.cancel')) ?></button>
                <button type="submit" class="btn btn-primary"><?= e(__t('common.save')) ?></button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Editar CU -->
<div class="modal fade" id="cuEditModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down">
        <form method="POST" action="" id="cuEditForm" class="modal-content" novalidate>
            <?= csrf_field() ?>
            <div class="modal-header">
                <h5 class="modal-title"><?= e(__t('cu.edit.title')) ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <label for="cuEditName" class="form-label"><?= e(__t('cu.form.name')) ?></label>
                <input type="text" id="cuEditName" name="name" class="form-control form-control-lg"
                       required minlength="2" maxlength="150">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?= e(__t('common.cancel')) ?></button>
                <button type="submit" class="btn btn-primary"><?= e(__t('common.save')) ?></button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    var modal = document.getElementById('cuEditModal');
    if (!modal) return;
    modal.addEventListener('show.bs.modal', function (event) {
        var btn = event.relatedTarget;
        if (!btn) return;
        var id = btn.getAttribute('data-cu-id');
        var name = btn.getAttribute('data-cu-name');
        var form = document.getElementById('cuEditForm');
        form.action = '/teacher/cu/' + id + '/rename';
        document.getElementById('cuEditName').value = name;
    });
})();
</script>
<?php endif; ?>

</div>
</div>

<!-- Offcanvas (mobile) -->
<div class="offcanvas offcanvas-start" tabindex="-1" id="curriculumOffcanvas" aria-labelledby="curriculumOffcanvasLabel">
    <div class="offcanvas-header">
        <h5 class="offcanvas-title" id="curriculumOffcanvasLabel"><?= e(__t('nav.curriculum.title')) ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body">
        <?php require LMS_ROOT . '/src/templates/partials/curriculum_nav.php'; ?>
    </div>
</div>

<?php require LMS_ROOT . '/src/templates/partials/delete_confirm_modal.php'; ?>
<?php
$page_content = ob_get_clean();
require LMS_ROOT . '/src/templates/layout.php';
