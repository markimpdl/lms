<?php
declare(strict_types=1);

/**
 * GET/POST /teacher/widgets/{id}/edit — edita metadados do widget e, opcional,
 * re-faz upload do zip (E35 / F26). Exclusivo do dono (por tenant).
 */

$tenantId = current_tenant_id();
if ($tenantId === null) {
    http_response_code(403);
    require LMS_ROOT . '/src/templates/errors/403.php';
    return;
}

$widgetId = (int) ($_REQUEST['id'] ?? 0);
$widget   = Widget::findForTenant($widgetId, $tenantId);
if ($widget === null) {
    http_response_code(404);
    require LMS_ROOT . '/src/templates/errors/404.php';
    return;
}

$old = [
    'name'        => (string) $widget['name'],
    'render_mode' => (string) $widget['render_mode'],
    'icon'        => (string) ($widget['icon'] ?? ''),
    'width_hint'  => $widget['width_hint'],
    'height_hint' => $widget['height_hint'],
];
$errors = [];
$maxMb  = (int) (WidgetStorage::MAX_BYTES / (1024 * 1024));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_verify();
    } catch (RuntimeException) {
        flash('danger', __t('auth.forbidden'));
        header('Location: /teacher/widgets/' . $widgetId . '/edit');
        return;
    }

    $old['name']        = trim((string) ($_POST['name'] ?? ''));
    $old['render_mode'] = (string) ($_POST['render_mode'] ?? 'inline');
    $old['icon']        = trim((string) ($_POST['icon'] ?? ''));
    $old['height_hint'] = ($_POST['height_hint'] ?? '') !== '' ? (int) $_POST['height_hint'] : null;

    if (mb_strlen($old['name']) < 3 || mb_strlen($old['name']) > 120) {
        $errors['name'] = 'widgets.err.name';
    }
    if (!in_array($old['render_mode'], Widget::RENDER_MODES, true)) {
        $old['render_mode'] = 'inline';
    }

    // Re-upload é opcional. Se veio arquivo, extrai pra um slug novo e troca,
    // apagando a pasta antiga só após sucesso.
    $file      = $_FILES['zip'] ?? null;
    $hasUpload = is_array($file) && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    $newStorage = null;

    if ($errors === [] && $hasUpload) {
        $newSlug = Widget::makeSlug($old['name']);
        $result  = WidgetStorage::store($file, $tenantId, $newSlug);
        if ($result['status'] !== 'ok') {
            $errors['zip'] = $result['error_key'] ?? 'widgets.err.generic';
        } else {
            $newStorage = ['slug' => $newSlug, 'path' => (string) $result['storage_path'], 'entry' => (string) $result['entry']];
        }
    }

    if ($errors === []) {
        Widget::updateMeta(
            $widgetId,
            $tenantId,
            $old['name'],
            $old['render_mode'],
            $old['icon'] !== '' ? $old['icon'] : null,
            null,
            $old['height_hint']
        );
        if ($newStorage !== null) {
            $oldPath = (string) $widget['storage_path'];
            Widget::updateStorage($widgetId, $tenantId, $newStorage['slug'], $newStorage['path'], $newStorage['entry']);
            if ($oldPath !== $newStorage['path']) {
                WidgetStorage::deleteDir($oldPath);
            }
        }
        flash('success', __t('widgets.updated', ['name' => $old['name']]));
        header('Location: /teacher/widgets', true, 303);
        return;
    }
}

$formAction  = '/teacher/widgets/' . $widgetId . '/edit';
$zipRequired = false;
$submitLabel = __t('common.save');

$page_title = __t('widgets.edit_title', ['name' => (string) $widget['name']]);
ob_start();
?>
<?= breadcrumbs([
    ['label' => __t('dashboard.teacher.title'), 'url' => '/teacher'],
    ['label' => __t('widgets.title'),            'url' => '/teacher/widgets'],
    ['label' => __t('widgets.edit_breadcrumb')],
]) ?>
<div class="row justify-content-center">
    <div class="col-12 col-lg-8">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
            <h1 class="h4 mb-0"><?= e(__t('widgets.edit_title', ['name' => (string) $widget['name']])) ?></h1>
            <a href="/widget/open/<?= $widgetId ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary">
                <?= e(__t('widgets.action.preview')) ?>
            </a>
        </div>
        <?php require LMS_ROOT . '/src/pages/teacher/widgets/_form.php'; ?>
    </div>
</div>
<?php
$page_content = ob_get_clean();
require LMS_ROOT . '/src/templates/layout.php';
