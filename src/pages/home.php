<?php
declare(strict_types=1);

// Botão de teste do flash (será removido quando houver rotas reais adiante).
if (isset($_GET['demo_flash'])) {
    flash('success', __t('app.demo_flash_ok'));
    header('Location: /');
    exit;
}

$user = current_user();

// Usuário logado vai direto pro dashboard — sem tela intermediária
// (decisão do PO em 2026-04-25, F1 do roadmap pós-MVP).
if ($user !== null) {
    header('Location: ' . AuthController::dashboardFor((string) $user['role']));
    exit;
}

$page_title = __t('app.title');

ob_start();
?>
<div class="text-center py-4">
    <h1 class="display-6"><?= e(__t('app.title')) ?></h1>
    <p class="lead text-muted"><?= e(__t('app.bootstrap_ok', ['tz' => date_default_timezone_get()])) ?></p>

    <div class="d-flex justify-content-center gap-2 flex-wrap">
        <a class="btn btn-primary" href="/login"><?= e(__t('auth.login')) ?></a>
        <a class="btn btn-outline-secondary" href="?demo_flash=1"><?= e(__t('app.demo_flash_btn')) ?></a>
    </div>
</div>
<?php
$page_content = ob_get_clean();

require LMS_ROOT . '/src/templates/layout.php';
