<?php
declare(strict_types=1);

require_role('super_admin');
$user = current_user();

$page_title = __t('dashboard.admin.title');

ob_start();
?>
<div class="py-4">
    <h1 class="h3 mb-3"><?= e(__t('dashboard.admin.title')) ?></h1>
    <p class="lead text-muted"><?= e(__t('dashboard.admin.welcome', ['name' => $user['name']])) ?></p>
    <p class="text-muted small"><?= e(__t('dashboard.stub_notice')) ?></p>
</div>
<?php
$page_content = ob_get_clean();

require LMS_ROOT . '/src/templates/layout.php';
