<?php
declare(strict_types=1);

/**
 * GET /widget/serve/{id}/{token}/{path} — serve os assets de um widget (E35/F26).
 *
 * Rota PÚBLICA (sem require_auth) porque o widget roda num <iframe sandbox> de
 * ORIGEM NULA: requisições de sub-recursos (imagens/css/js) feitas de dentro do
 * iframe NÃO levam o cookie de sessão (origem opaca → cross-site), então um gate
 * por sessão redireciona pro /login e quebra os assets. A autorização aqui é por
 * TOKEN assinado no path (widget_token), emitido só nas páginas onde o usuário
 * já podia ver o widget. O token no path faz os sub-recursos relativos herdarem
 * o gate. Rotaciona por dia.
 *
 * Passthrough: stream dos bytes de storage/widgets/... (fora do document root,
 * nunca executado pelo Apache), com content-type por allowlist + nosniff + CSP +
 * defesa contra path traversal.
 */

$id    = (int) ($_REQUEST['id'] ?? 0);
$token = (string) ($_REQUEST['token'] ?? '');
$path  = (string) ($_REQUEST['path'] ?? '');

$widget = Widget::findById($id);
if ($widget === null || (int) ($widget['active'] ?? 0) !== 1) {
    http_response_code(404);
    require LMS_ROOT . '/src/templates/errors/404.php';
    return;
}

if (!widget_token_valid($id, $token)) {
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
    http_response_code(404);
    require LMS_ROOT . '/src/templates/errors/404.php';
    return;
}

header('Content-Type: ' . $type);
header('Content-Length: ' . (string) filesize($file));
header('X-Content-Type-Options: nosniff');
// Reforço: isolamento principal é o sandbox de origem nula do iframe. Aqui só
// impedimos enframe por origens externas e cortamos <base>.
header("Content-Security-Policy: frame-ancestors 'self'; base-uri 'none'");
header('Cache-Control: private, max-age=300');

readfile($file);
