<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

// Placeholder — o roteamento real é implementado em E1 (Auth) e adiante.
header('Content-Type: text/html; charset=utf-8');

$lang = current_lang();
$tz   = date_default_timezone_get();
?><!doctype html>
<html lang="<?= e($lang) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(__t('app.title')) ?></title>
</head>
<body style="font-family: system-ui, sans-serif; max-width: 640px; margin: 4rem auto; padding: 0 1rem;">
    <nav style="display:flex; justify-content:flex-end; gap:.5rem; font-size:.9rem;">
        <span><?= e(__t('common.language')) ?>:</span>
        <a href="?lang=pt"<?= $lang === 'pt' ? ' style="font-weight:bold"' : '' ?>>PT</a>
        <a href="?lang=en"<?= $lang === 'en' ? ' style="font-weight:bold"' : '' ?>>EN</a>
    </nav>
    <h1><?= e(__t('app.title')) ?></h1>
    <p><?= e(__t('app.bootstrap_ok', ['tz' => $tz])) ?></p>
</body>
</html>
