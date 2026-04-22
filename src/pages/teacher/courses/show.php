<?php
declare(strict_types=1);

/**
 * /teacher/courses/{id} — detalhes do curso (E3-01).
 * Base para E3-02 adicionar listagem de CCs. Inclui dados, contadores e botão
 * arquivar/restaurar.
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
$page_title = (string) $course['name'];

ob_start();
?>
<nav aria-label="breadcrumb" class="small">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="/teacher/courses"><?= e(__t('courses.index.title')) ?></a></li>
        <li class="breadcrumb-item active" aria-current="page"><?= e((string) $course['name']) ?></li>
    </ol>
</nav>

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

<div class="row g-2 mb-3">
    <div class="col-6 col-md-3">
        <div class="card card-body text-center">
            <div class="display-6"><?= (int) $course['cc_count'] ?></div>
            <div class="small text-muted"><?= e(__t('courses.col.cc_count')) ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card card-body text-center">
            <div class="display-6"><?= (int) $course['cu_count'] ?></div>
            <div class="small text-muted"><?= e(__t('courses.col.cu_count')) ?></div>
        </div>
    </div>
</div>

<div class="card card-body bg-light-subtle small text-muted">
    <?= e(__t('courses.show.cc_placeholder')) ?>
</div>
<?php
$page_content = ob_get_clean();
require LMS_ROOT . '/src/templates/layout.php';
