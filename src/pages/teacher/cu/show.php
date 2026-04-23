<?php
declare(strict_types=1);

/**
 * /teacher/cu/{id} — detalhe da CU com conteúdo HTML (E5-01).
 *
 * Primeira tela de detalhe individual da CU (antes, CUs eram listadas dentro
 * da página da CC em /teacher/courses/{c}/cc/{cc}). Aqui o professor vê o
 * conteúdo sanitizado atual (se publicado ou rascunho), badge de status, e
 * botão para editar. E6 (atividades) e E7 (avaliação) acoplarão blocos
 * adicionais nesta mesma tela no futuro.
 */

$tenantId = current_tenant_id();
if ($tenantId === null) {
    http_response_code(403);
    require LMS_ROOT . '/src/templates/errors/403.php';
    return;
}

$cuId = (int) ($_REQUEST['id'] ?? 0);
$cu   = CompetenceUnit::findForTenant($cuId, $tenantId);
if ($cu === null) {
    http_response_code(404);
    require LMS_ROOT . '/src/templates/errors/404.php';
    return;
}

$courseId   = (int) $cu['course_id'];
$ccId       = (int) $cu['core_competency_id'];
$isArchived = (int) $cu['course_archived'] === 1;

$content = Content::findForCu($cuId, $tenantId);
$hasContent   = $content !== null;
$isPublished  = $hasContent && (int) $content['published'] === 1;

$attachments = ContentAttachment::listByCu($cuId, $tenantId);
$activities  = Activity::listByCu($cuId, $tenantId);
$activityCount = count($activities);

$tree       = curriculum_tree($courseId, $tenantId);
$activeCcId = $ccId;
$activeCuId = $cuId;

// Breadcrumb precisa do nome da CC e do curso — mais barato buscar uma vez
// do que duas queries separadas. A árvore já tem tudo.
$ccName = '';
$courseName = (string) $tree['name'];
foreach ($tree['ccs'] as $navCc) {
    if ((int) $navCc['id'] === $ccId) {
        $ccName = (string) $navCc['name'];
        break;
    }
}

$page_title = (string) $cu['name'];

ob_start();
?>
<?= breadcrumbs([
    ['label' => __t('courses.index.title'), 'url' => '/teacher/courses'],
    ['label' => $courseName, 'url' => '/teacher/courses/' . $courseId],
    ['label' => $ccName,     'url' => '/teacher/courses/' . $courseId . '/cc/' . $ccId],
    ['label' => (string) $cu['name']],
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
                <h1 class="h4 mb-1"><?= e((string) $cu['name']) ?></h1>
                <?php if ($hasContent): ?>
                    <?php if ($isPublished): ?>
                        <span class="badge text-bg-success"><?= e(__t('content.status.published')) ?></span>
                    <?php else: ?>
                        <span class="badge text-bg-secondary"><?= e(__t('content.status.unpublished')) ?></span>
                    <?php endif; ?>
                <?php else: ?>
                    <span class="badge text-bg-light border"><?= e(__t('content.status.empty')) ?></span>
                <?php endif; ?>
            </div>
            <?php if (!$isArchived): ?>
                <a href="/teacher/cu/<?= $cuId ?>/content/edit"
                   class="btn btn-sm btn-primary">
                    <?= e(__t($hasContent ? 'content.edit_button' : 'content.create_button')) ?>
                </a>
            <?php endif; ?>
        </div>

        <?php if ($isArchived): ?>
            <div class="alert alert-warning" role="alert">
                <?= e(__t('courses.edit.archived_notice')) ?>
            </div>
        <?php endif; ?>

        <div class="card shadow-sm mb-3">
            <div class="card-body content-render">
                <?php if ($hasContent): ?>
                    <?= (string) $content['html'] ?>
                <?php else: ?>
                    <p class="text-muted mb-0 text-center py-4">
                        <?= e(__t('content.none_yet')) ?>
                    </p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Atividades (E6-02) -->
        <div class="card shadow-sm mb-3">
            <div class="card-header d-flex align-items-center justify-content-between gap-2 flex-wrap">
                <div>
                    <h2 class="h6 mb-0"><?= e(__t('activities.section.title')) ?></h2>
                    <small class="text-muted">
                        <?= e(__t('activities.section.subtitle', ['count' => (string) $activityCount])) ?>
                    </small>
                </div>
                <?php if (!$isArchived): ?>
                    <a href="/teacher/cu/<?= $cuId ?>/activity/new" class="btn btn-sm btn-primary">
                        + <?= e(__t('activities.new_button')) ?>
                    </a>
                <?php endif; ?>
            </div>
            <?php if ($activityCount === 0): ?>
                <div class="card-body text-center text-muted py-4">
                    <p class="mb-0"><?= e(__t('activities.empty')) ?></p>
                </div>
            <?php else: ?>
                <ul class="list-group list-group-flush">
                    <?php foreach ($activities as $i => $act): ?>
                        <?php
                            $aid  = (int) $act['id'];
                            $open = (int) $act['submission_open'] === 1;
                            $subs = (int) $act['submission_count'];
                        ?>
                        <li class="list-group-item d-flex align-items-center gap-2 flex-wrap">
                            <span class="text-muted small" style="min-width: 1.5rem;"><?= $i + 1 ?>.</span>
                            <div class="flex-grow-1">
                                <a href="/teacher/activity/<?= $aid ?>/edit" class="fw-semibold text-decoration-none">
                                    <?= e((string) $act['title']) ?>
                                </a>
                                <small class="text-muted ms-2">
                                    <?= e(__t('activities.type.' . $act['type'])) ?> ·
                                    <?= (int) $act['xp_value'] ?> XP ·
                                    <?= $subs ?> <?= e(__t('activities.submissions_label')) ?>
                                </small>
                            </div>

                            <?php if ($open): ?>
                                <span class="badge text-bg-success"><?= e(__t('activities.status.open')) ?></span>
                            <?php else: ?>
                                <span class="badge text-bg-secondary"><?= e(__t('activities.status.closed')) ?></span>
                            <?php endif; ?>

                            <?php if (!$isArchived): ?>
                                <div class="d-flex gap-1">
                                    <form method="POST" action="/teacher/activity/<?= $aid ?>/move-up" class="d-inline">
                                        <?= csrf_field() ?>
                                        <button type="submit" class="btn btn-sm btn-outline-secondary"
                                                <?= $i === 0 ? 'disabled' : '' ?>
                                                aria-label="<?= e(__t('activities.action.move_up')) ?>">↑</button>
                                    </form>
                                    <form method="POST" action="/teacher/activity/<?= $aid ?>/move-down" class="d-inline">
                                        <?= csrf_field() ?>
                                        <button type="submit" class="btn btn-sm btn-outline-secondary"
                                                <?= $i === $activityCount - 1 ? 'disabled' : '' ?>
                                                aria-label="<?= e(__t('activities.action.move_down')) ?>">↓</button>
                                    </form>
                                    <form method="POST" action="/teacher/activity/<?= $aid ?>/toggle" class="d-inline">
                                        <?= csrf_field() ?>
                                        <button type="submit" class="btn btn-sm btn-outline-<?= $open ? 'warning' : 'success' ?>">
                                            <?= e(__t($open ? 'activities.action.close' : 'activities.action.open')) ?>
                                        </button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <?php if ($attachments !== []): ?>
            <div class="card shadow-sm">
                <div class="card-header">
                    <h2 class="h6 mb-0"><?= e(__t('student.content.attachments_title')) ?></h2>
                </div>
                <ul class="list-group list-group-flush">
                    <?php foreach ($attachments as $att): ?>
                        <?php $aid = (int) $att['id']; ?>
                        <li class="list-group-item d-flex align-items-center gap-2 flex-wrap">
                            <div class="flex-grow-1">
                                <a href="/teacher/cu/<?= $cuId ?>/attachment/<?= $aid ?>"
                                   class="fw-semibold text-decoration-none">
                                    <?= e((string) $att['filename']) ?>
                                </a>
                                <small class="text-muted ms-2">
                                    <?= e((string) $att['mime']) ?> ·
                                    <?= e(number_format((int) $att['size_bytes'] / 1024, 1, ',', '.')) ?> KB
                                </small>
                            </div>
                            <a href="/teacher/cu/<?= $cuId ?>/attachment/<?= $aid ?>"
                               class="btn btn-sm btn-outline-primary">
                                <?= e(__t('student.content.download')) ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
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

<style>
.content-render img          { max-width: 100%; height: auto; }
.content-render table        { width: 100%; }
.content-render iframe       { width: 100%; aspect-ratio: 16 / 9; border: 0; }
.content-render pre          { background: #f6f8fa; padding: .75rem; border-radius: .25rem; overflow-x: auto; }
.content-render blockquote   { border-left: 3px solid #dee2e6; padding-left: 1rem; color: #6c757d; }
</style>
<?php
$page_content = ob_get_clean();
require LMS_ROOT . '/src/templates/layout.php';
