<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

// Placeholder — o roteamento real é implementado em E1 (Auth) e adiante.
header('Content-Type: text/html; charset=utf-8');
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>LMS</title>
</head>
<body style="font-family: system-ui, sans-serif; max-width: 640px; margin: 4rem auto; padding: 0 1rem;">
    <h1>LMS</h1>
    <p>Bootstrap OK. Timezone: <code><?= htmlspecialchars(date_default_timezone_get(), ENT_QUOTES, 'UTF-8') ?></code>.</p>
</body>
</html>
