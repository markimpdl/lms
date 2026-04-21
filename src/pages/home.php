<?php
declare(strict_types=1);

// Botão de teste do flash (será removido quando houver rotas reais adiante).
if (isset($_GET['demo_flash'])) {
    flash('success', __t('app.demo_flash_ok'));
    header('Location: /');
    exit;
}

$user = current_user();
$page_title = __t('app.title');

ob_start();
?>
<div class="text-center py-4">
    <h1 class="display-6"><?= e(__t('app.title')) ?></h1>
    <p class="lead text-muted"><?= e(__t('app.bootstrap_ok', ['tz' => date_default_timezone_get()])) ?></p>

    <?php if ($user === null): ?>
        <div class="d-flex justify-content-center gap-2 flex-wrap">
            <a class="btn btn-primary" href="/login"><?= e(__t('auth.login')) ?></a>
            <a class="btn btn-outline-secondary" href="?demo_flash=1"><?= e(__t('app.demo_flash_btn')) ?></a>
        </div>
    <?php else: ?>
        <p class="mt-3"><?= e(__t('common.welcome')) ?>, <strong><?= e($user['name']) ?></strong>.</p>
        <a class="btn btn-primary" href="<?= e(AuthController::dashboardFor($user['role'])) ?>">
            <?= e(__t('nav.dashboard')) ?>
        </a>
    <?php endif; ?>
</div>
<?php
$page_content = ob_get_clean();

require LMS_ROOT . '/src/templates/layout.php';
