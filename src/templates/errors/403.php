<?php
declare(strict_types=1);

if (http_response_code() !== 403) {
    http_response_code(403);
}

$page_title = __t('error.403');

ob_start();
?>
<div class="text-center py-5">
    <h1 class="display-1 text-muted mb-0">403</h1>
    <p class="lead"><?= e(__t('error.403_message')) ?></p>
    <a href="/" class="btn btn-outline-primary mt-2"><?= e(__t('common.back')) ?></a>
</div>
<?php
$page_content = ob_get_clean();

require LMS_ROOT . '/src/templates/layout.php';
