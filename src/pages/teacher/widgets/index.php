<?php
declare(strict_types=1);

/**
 * /teacher/widgets — biblioteca de widgets do professor (E35 / F26 — ADR-037).
 * Lista os widgets do tenant; cadastrar / editar / remover. Inserção no
 * conteúdo da CU acontece pelo editor (TinyMCE), não aqui.
 */

$tenantId = current_tenant_id();
if ($tenantId === null) {
    http_response_code(403);
    require LMS_ROOT . '/src/templates/errors/403.php';
    return;
}

$widgets = Widget::listForTenant($tenantId);

$page_title = __t('widgets.title');

ob_start();
?>
<?= breadcrumbs([
    ['label' => __t('dashboard.teacher.title'), 'url' => '/teacher'],
    ['label' => __t('widgets.title')],
]) ?>

<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
    <div>
        <h1 class="h4 mb-1"><?= e(__t('widgets.title')) ?></h1>
        <small class="text-muted"><?= e(__t('widgets.subtitle')) ?></small>
    </div>
    <a href="/teacher/widgets/new" class="btn btn-primary">+ <?= e(__t('widgets.new_button')) ?></a>
</div>

<?php if ($widgets === []): ?>
    <div class="card card-body text-center text-muted py-5">
        <p class="lead mb-2"><?= e(__t('widgets.empty.title')) ?></p>
        <p class="small mb-3"><?= e(__t('widgets.empty.hint')) ?></p>
        <div><a href="/teacher/widgets/new" class="btn btn-primary">+ <?= e(__t('widgets.new_button')) ?></a></div>
    </div>
<?php else: ?>
    <div class="row g-3">
        <?php foreach ($widgets as $w): $wid = (int) $w['id']; ?>
            <div class="col-12 col-md-6 col-lg-4">
                <div class="card shadow-sm h-100">
                    <div class="card-body d-flex flex-column">
                        <div class="d-flex align-items-start justify-content-between gap-2 mb-2">
                            <h2 class="h6 mb-0 text-break"><?= e((string) $w['name']) ?></h2>
                            <span class="badge text-bg-secondary"><?= e(__t('widgets.mode.' . (string) $w['render_mode'])) ?></span>
                        </div>
                        <div class="small text-muted mb-3">
                            <?= e(__t('widgets.entry')) ?>: <code><?= e((string) $w['entry_file']) ?></code>
                            <?php if ((int) $w['active'] === 0): ?>
                                · <span class="badge text-bg-warning"><?= e(__t('widgets.inactive')) ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="mt-auto d-flex gap-2">
                            <a href="/widget/open/<?= $wid ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary">
                                <?= e(__t('widgets.action.preview')) ?>
                            </a>
                            <a href="/teacher/widgets/<?= $wid ?>/edit" class="btn btn-sm btn-outline-primary">
                                <?= e(__t('common.edit')) ?>
                            </a>
                            <form method="POST" action="/teacher/widgets/<?= $wid ?>/delete" class="m-0"
                                  onsubmit="return confirm(<?= e(json_encode(__t('widgets.delete_confirm', ['name' => (string) $w['name']]), JSON_UNESCAPED_UNICODE)) ?>);">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-sm btn-outline-danger"><?= e(__t('common.delete')) ?></button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php
$page_content = ob_get_clean();
require LMS_ROOT . '/src/templates/layout.php';
