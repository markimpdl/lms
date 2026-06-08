<?php
declare(strict_types=1);

/**
 * GET /widget/open/{id} — abre um widget em tela cheia (modo 'window', E35/F26).
 *
 * Página mínima e autossuficiente (sem o chrome do app): só um <iframe sandbox>
 * de origem nula ocupando a viewport, apontando pro /widget/serve do mesmo
 * widget. Mesmo gate de acesso do serving.
 */

$id     = (int) ($_REQUEST['id'] ?? 0);
$widget = Widget::findById($id);
if ($widget === null) {
    http_response_code(404);
    require LMS_ROOT . '/src/templates/errors/404.php';
    return;
}

$user = current_user();
if ($user === null || !Widget::userCanAccess($widget, $user)) {
    http_response_code(403);
    require LMS_ROOT . '/src/templates/errors/403.php';
    return;
}

$name = (string) $widget['name'];
header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="<?= e(current_lang()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title><?= e($name) ?></title>
    <style>
        html, body { margin: 0; height: 100%; background: #fff; }
        iframe { display: block; width: 100%; height: 100%; border: 0; }
    </style>
</head>
<body>
    <iframe sandbox="allow-scripts" title="<?= e($name) ?>"
            src="/widget/serve/<?= $id ?>/"></iframe>
</body>
</html>
<?php
return;
