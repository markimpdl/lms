<?php
declare(strict_types=1);

/**
 * /teacher/courses/{id} — detalhes do curso + listagem e CRUD de CCs (E3-01 + E3-02).
 * E3-04 adiciona breadcrumbs via helper + sidebar de currículo (coluna ≥md,
 * offcanvas <md).
 */

$tenantId = current_tenant_id();
if ($tenantId === null) {
    http_response_code(403);
    require LMS_ROOT . '/src/templates/errors/403.php';
    return;
}

$courseId = (int) ($_REQUEST['id'] ?? 0);
$course = Course::findForTenant($courseId, $tenantId);
if ($course === null) {
    http_response_code(404);
    require LMS_ROOT . '/src/templates/errors/404.php';
    return;
}

$isArchived = (int) $course['archived'] === 1;
$ccs = CoreCompetency::listByCourse($courseId, $tenantId);
$ccCount = count($ccs);
$tree = curriculum_tree($courseId, $tenantId);
$activeCcId = 0;
$activeCuId = 0;

$page_title = (string) $course['name'];

ob_start();
?>
<?= breadcrumbs([
    ['label' => __t('courses.index.title'), 'url' => '/teacher/courses'],
    ['label' => (string) $course['name']],
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
                <h1 class="h4 mb-1"><?= e((string) $course['name']) ?></h1>
                <div class="small text-muted">
                    <?= (int) $course['year'] ?> · <?= e(strtoupper((string) $course['language'])) ?> ·
                    <?php if ($isArchived): ?>
                        <span class="badge text-bg-warning"><?= e(__t('courses.status.archived')) ?></span>
                        <?php if ($course['archived_at'] !== null): ?>
                            <span class="ms-1"><?= e(__t('courses.archived_at', ['date' => date('Y-m-d H:i', strtotime((string) $course['archived_at']))])) ?></span>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="badge text-bg-success"><?= e(__t('courses.status.active')) ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="d-flex gap-2 flex-wrap">
                <?php if (!$isArchived): ?>
                    <a href="/teacher/courses/<?= (int) $course['id'] ?>/edit" class="btn btn-outline-primary">
                        <?= e(__t('courses.action.edit')) ?>
                    </a>
                <?php endif; ?>
                <form method="POST" action="/teacher/courses/<?= (int) $course['id'] ?>/toggle-archive" class="d-inline">
                    <?= csrf_field() ?>
                    <?php if ($isArchived): ?>
                        <button type="submit" class="btn btn-outline-success">
                            <?= e(__t('courses.action.restore')) ?>
                        </button>
                    <?php else: ?>
                        <button type="submit" class="btn btn-outline-warning"
                                onclick="return confirm(<?= e(json_encode(__t('courses.archive_confirm', ['name' => $course['name']]), JSON_UNESCAPED_UNICODE)) ?>);">
                            <?= e(__t('courses.action.archive')) ?>
                        </button>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <?php if (!empty($course['description'])): ?>
            <div class="card card-body shadow-sm mb-3">
                <p class="mb-0" style="white-space: pre-line;"><?= e((string) $course['description']) ?></p>
            </div>
        <?php endif; ?>

        <!-- Core Competencies -->
        <div class="card shadow-sm mb-3">
            <div class="card-header d-flex align-items-center justify-content-between gap-2 flex-wrap">
                <div>
                    <h2 class="h6 mb-0"><?= e(__t('cc.section.title')) ?></h2>
                    <small class="text-muted"><?= e(__t('cc.section.subtitle', ['count' => (string) $ccCount])) ?></small>
                </div>
                <?php if (!$isArchived): ?>
                    <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#ccNewModal">
                        + <?= e(__t('cc.new_button')) ?>
                    </button>
                <?php endif; ?>
            </div>

            <?php if ($ccCount === 0): ?>
                <div class="card-body text-center text-muted py-4">
                    <p class="mb-0"><?= e(__t('cc.empty')) ?></p>
                </div>
            <?php else: ?>
                <ul class="list-group list-group-flush">
                    <?php foreach ($ccs as $i => $cc): ?>
                        <?php $ccId = (int) $cc['id']; $ccName = (string) $cc['name']; ?>
                        <li class="list-group-item d-flex align-items-center gap-2">
                            <span class="text-muted small" style="min-width: 1.5rem;"><?= $i + 1 ?>.</span>
                            <div class="flex-grow-1">
                                <a href="/teacher/courses/<?= (int) $course['id'] ?>/cc/<?= $ccId ?>" class="fw-semibold text-decoration-none"><?= e($ccName) ?></a>
                                <small class="text-muted ms-2"><?= (int) $cc['cu_count'] ?> <?= e(__t('courses.col.cu_count')) ?></small>
                            </div>
                            <?php if (!$isArchived): ?>
                                <div class="d-flex gap-1">
                                    <form method="POST" action="/teacher/cc/<?= $ccId ?>/move-up" class="d-inline">
                                        <?= csrf_field() ?>
                                        <button type="submit" class="btn btn-sm btn-outline-secondary" <?= $i === 0 ? 'disabled' : '' ?>
                                                aria-label="<?= e(__t('cc.action.move_up')) ?>">↑</button>
                                    </form>
                                    <form method="POST" action="/teacher/cc/<?= $ccId ?>/move-down" class="d-inline">
                                        <?= csrf_field() ?>
                                        <button type="submit" class="btn btn-sm btn-outline-secondary" <?= $i === $ccCount - 1 ? 'disabled' : '' ?>
                                                aria-label="<?= e(__t('cc.action.move_down')) ?>">↓</button>
                                    </form>
                                    <button type="button" class="btn btn-sm btn-outline-primary"
                                            data-bs-toggle="modal" data-bs-target="#ccEditModal"
                                            data-cc-id="<?= $ccId ?>" data-cc-name="<?= e($ccName) ?>"
                                            aria-label="<?= e(__t('cc.action.rename')) ?>">✎</button>
                                </div>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Offcanvas (mobile) com a mesma árvore -->
<div class="offcanvas offcanvas-start" tabindex="-1" id="curriculumOffcanvas" aria-labelledby="curriculumOffcanvasLabel">
    <div class="offcanvas-header">
        <h5 class="offcanvas-title" id="curriculumOffcanvasLabel"><?= e(__t('nav.curriculum.title')) ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body">
        <?php require LMS_ROOT . '/src/templates/partials/curriculum_nav.php'; ?>
    </div>
</div>

<?php if (!$isArchived): ?>
<!-- Modal: Nova CC -->
<div class="modal fade" id="ccNewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down">
        <form method="POST" action="/teacher/cc/new" class="modal-content" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="course_id" value="<?= (int) $course['id'] ?>">
            <div class="modal-header">
                <h5 class="modal-title"><?= e(__t('cc.new.title')) ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <label for="ccNewName" class="form-label"><?= e(__t('cc.form.name')) ?></label>
                <input type="text" id="ccNewName" name="name" class="form-control form-control-lg"
                       required minlength="2" maxlength="150" autofocus>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?= e(__t('common.cancel')) ?></button>
                <button type="submit" class="btn btn-primary"><?= e(__t('common.save')) ?></button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Editar CC -->
<div class="modal fade" id="ccEditModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down">
        <form method="POST" action="" id="ccEditForm" class="modal-content" novalidate>
            <?= csrf_field() ?>
            <div class="modal-header">
                <h5 class="modal-title"><?= e(__t('cc.edit.title')) ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <label for="ccEditName" class="form-label"><?= e(__t('cc.form.name')) ?></label>
                <input type="text" id="ccEditName" name="name" class="form-control form-control-lg"
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
    var modal = document.getElementById('ccEditModal');
    if (!modal) return;
    modal.addEventListener('show.bs.modal', function (event) {
        var btn = event.relatedTarget;
        if (!btn) return;
        var id = btn.getAttribute('data-cc-id');
        var name = btn.getAttribute('data-cc-name');
        var form = document.getElementById('ccEditForm');
        form.action = '/teacher/cc/' + id + '/rename';
        document.getElementById('ccEditName').value = name;
    });
})();
</script>
<?php endif; ?>
<?php
$page_content = ob_get_clean();
require LMS_ROOT . '/src/templates/layout.php';
