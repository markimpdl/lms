<?php
declare(strict_types=1);

/**
 * Bootstrap do LMS — carregado por public/index.php e por scripts CLI (cron).
 * Define timezone, charset, autoload e sessão; expõe env em $GLOBALS['__ENV'].
 */

define('LMS_ROOT', dirname(__DIR__));

// 1. Env -----------------------------------------------------------------
$envFile = LMS_ROOT . '/config/env.php';
if (!is_file($envFile)) {
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="pt"><head><meta charset="utf-8">'
        . '<title>LMS — configuração pendente</title></head><body style="font-family:sans-serif;max-width:640px;margin:4rem auto;padding:0 1rem">'
        . '<h1>Configuração pendente</h1>'
        . '<p>Copie <code>config/env.example.php</code> para <code>config/env.php</code> e preencha as credenciais antes de acessar a aplicação.</p>'
        . '</body></html>';
    exit;
}

$env = require $envFile;
if (!is_array($env)) {
    throw new RuntimeException('config/env.php deve retornar um array associativo.');
}
$GLOBALS['__ENV'] = $env;

// 2. Timezone e charset ---------------------------------------------------
date_default_timezone_set($env['APP_TIMEZONE'] ?? 'Asia/Dubai');
mb_internal_encoding('UTF-8');
ini_set('default_charset', 'UTF-8');

// 3. Sessão (apenas web) --------------------------------------------------
if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    // Default do PHP é 1440s (24min). Professores escrevendo conteudo longo
    // no TinyMCE perdiam trabalho ao salvar apos 25min de digitacao (sem
    // navegacao = sem refresh de sessao). Bumpa pra 4h. Cookie continua em
    // browser-session (lifetime: 0) por seguranca — fechar o browser desloga.
    ini_set('session.gc_maxlifetime', '14400');
    session_name('lms_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => !empty($env['APP_HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// 4. Autoload -----------------------------------------------------------
// Composer primeiro (dependências com namespace: PHPMailer, etc.). Depois o
// autoload por convenção do projeto, que cobre classes sem namespace em
// src/{lib,models,services,controllers}/.
$composerAutoload = LMS_ROOT . '/vendor/autoload.php';
if (is_file($composerAutoload)) {
    require $composerAutoload;
}
spl_autoload_register(static function (string $class): void {
    static $roots = ['lib', 'models', 'services', 'controllers'];
    $relative = str_replace('\\', '/', $class) . '.php';
    foreach ($roots as $root) {
        $path = LMS_ROOT . '/src/' . $root . '/' . $relative;
        if (is_file($path)) {
            require $path;
            return;
        }
    }
});

// 5. Helpers globais ------------------------------------------------------
require LMS_ROOT . '/src/helpers.php';
