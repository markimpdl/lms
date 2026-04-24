<?php
declare(strict_types=1);

/**
 * Layout mestre. Espera as variáveis (convenção):
 *   $page_title   string (opcional)  — vai no <title>
 *   $page_content string            — HTML já montado, inserido no <main>
 *   $body_class   string (opcional) — classes extras no <body>
 *
 * Uso recomendado:
 *   $page_title = 'Login';
 *   ob_start(); ?>
 *   <h1>Login</h1>
 *   <?php $page_content = ob_get_clean();
 *   require LMS_ROOT . '/src/templates/layout.php';
 */

$title     = $page_title   ?? __t('app.title');
$content   = $page_content ?? '';
$bodyClass = $body_class   ?? '';
$lang      = current_lang();
?><!doctype html>
<html lang="<?= e($lang) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#0d6efd">
    <title><?= e($title) ?></title>
    <link rel="icon" href="/assets/favicon.svg" type="image/svg+xml">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet"
          integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body<?= $bodyClass !== '' ? ' class="' . e($bodyClass) . '"' : '' ?>>
    <?php require __DIR__ . '/header.php'; ?>
    <main class="container py-4">
        <?php render_flash(); ?>
        <?= $content ?>
    </main>
    <?php require __DIR__ . '/footer.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"
            integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI" crossorigin="anonymous"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.1/dist/cdn.min.js"></script>
</body>
</html>
