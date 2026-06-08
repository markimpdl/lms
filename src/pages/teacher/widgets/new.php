<?php
declare(strict_types=1);

/**
 * GET/POST /teacher/widgets/new — cadastra um widget (E35 / F26 — ADR-037).
 * POST valida, extrai o zip de forma segura (WidgetStorage) e cria o registro.
 */

$tenantId = current_tenant_id();
if ($tenantId === null) {
    http_response_code(403);
    require LMS_ROOT . '/src/templates/errors/403.php';
    return;
}
$user = current_user();

$old = [
    'name'        => '',
    'render_mode' => 'inline',
    'icon'        => '',
    'width_hint'  => null,
    'height_hint' => null,
];
$errors = [];
$maxMb  = (int) (WidgetStorage::MAX_BYTES / (1024 * 1024));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_verify();
    } catch (RuntimeException) {
        flash('danger', __t('auth.forbidden'));
        header('Location: /teacher/widgets/new');
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

    $file = $_FILES['zip'] ?? null;
    if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        $errors['zip'] = 'widgets.err.zip_required';
    }

    if ($errors === []) {
        $slug   = Widget::makeSlug($old['name']);
        $result = WidgetStorage::store($file, $tenantId, $slug);
        if ($result['status'] !== 'ok') {
            $errors['zip'] = $result['error_key'] ?? 'widgets.err.generic';
        } else {
            $id = Widget::create(
                $tenantId,
                (int) $user['id'],
                $old['name'],
                $slug,
                $old['render_mode'],
                (string) $result['entry'],
                (string) $result['storage_path'],
                $old['icon'] !== '' ? $old['icon'] : null,
                null,
                $old['height_hint']
            );
            flash('success', __t('widgets.created', ['name' => $old['name']]));
            header('Location: /teacher/widgets', true, 303);
            return;
        }
    }
}

$formAction  = '/teacher/widgets/new';
$zipRequired = true;
$submitLabel = __t('widgets.new_button');

$page_title = __t('widgets.new_title');
ob_start();
?>
<?= breadcrumbs([
    ['label' => __t('dashboard.teacher.title'), 'url' => '/teacher'],
    ['label' => __t('widgets.title'),            'url' => '/teacher/widgets'],
    ['label' => __t('widgets.new_title')],
]) ?>
<div class="row justify-content-center">
    <div class="col-12 col-lg-8">
        <h1 class="h4 mb-3"><?= e(__t('widgets.new_title')) ?></h1>
        <?php require LMS_ROOT . '/src/pages/teacher/widgets/_form.php'; ?>
    </div>
</div>
<?php
$page_content = ob_get_clean();
require LMS_ROOT . '/src/templates/layout.php';
