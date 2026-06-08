<?php
declare(strict_types=1);

/**
 * GET /widget/serve/{id}/{path} — serve os assets de um widget (E35 / F26).
 *
 * Passthrough PHP: faz stream dos bytes a partir de storage/widgets/... (FORA
 * do document root, nunca executado pelo Apache). O conteúdo é carregado num
 * <iframe sandbox> de origem nula (ver expand_widgets / widget/open), então o
 * JS do widget não acessa a sessão do LMS. Aqui garantimos:
 *  - gate de acesso (Widget::userCanAccess) — professor com acesso / aluno
 *    matriculado em curso que referencia o widget;
 *  - content-type por allowlist de extensão + X-Content-Type-Options: nosniff;
 *  - defesa contra path traversal (WidgetStorage::resolveAsset);
 *  - CSP de reforço (sem framing externo).
 */

$id   = (int) ($_REQUEST['id'] ?? 0);
$path = (string) ($_REQUEST['path'] ?? '');

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

$requested = $path !== '' ? $path : (string) $widget['entry_file'];
$file = WidgetStorage::resolveAsset((string) $widget['storage_path'], $requested);
if ($file === null) {
    http_response_code(404);
    require LMS_ROOT . '/src/templates/errors/404.php';
    return;
}

$type = WidgetStorage::contentTypeFor($file);
if ($type === null) {
    // Extensão fora da allowlist — não deveria existir (filtrado na extração).
    http_response_code(404);
    require LMS_ROOT . '/src/templates/errors/404.php';
    return;
}

header('Content-Type: ' . $type);
header('Content-Length: ' . (string) filesize($file));
header('X-Content-Type-Options: nosniff');
// Reforço: o isolamento principal é o sandbox de origem nula do iframe. Aqui
// só impedimos que estas respostas sejam enframadas por origens externas e
// cortamos <base>. Assets do próprio widget (mesma origem) seguem carregando.
header("Content-Security-Policy: frame-ancestors 'self'; base-uri 'none'");
header('Cache-Control: private, max-age=300');

readfile($file);
