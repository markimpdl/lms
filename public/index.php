<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

// Botão de teste do flash (será removido quando houver rotas reais em E1+).
if (isset($_GET['demo_flash'])) {
    flash('success', __t('app.demo_flash_ok'));
    header('Location: /');
    exit;
}

$page_title = __t('app.title');

ob_start();
?>
<div class="text-center py-4">
    <h1 class="display-6"><?= e(__t('app.title')) ?></h1>
    <p class="lead text-muted"><?= e(__t('app.bootstrap_ok', ['tz' => date_default_timezone_get()])) ?></p>
    <a class="btn btn-outline-primary" href="?demo_flash=1"><?= e(__t('app.demo_flash_btn')) ?></a>
</div>
<?php
$page_content = ob_get_clean();

require LMS_ROOT . '/src/templates/layout.php';
