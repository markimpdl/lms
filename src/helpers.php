<?php
declare(strict_types=1);

/**
 * Helpers globais do LMS.
 * Carregado por src/bootstrap.php após sessão e autoload.
 */

// ---------------------------------------------------------------------
// Escape HTML
// ---------------------------------------------------------------------

function e(mixed $v): string
{
    if ($v === null) {
        return '';
    }
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ---------------------------------------------------------------------
// i18n
// ---------------------------------------------------------------------

/**
 * Resolve o idioma corrente uma vez por request.
 * Ordem: ?lang= (persistido em sessão) → sessão → preferência do user → 'pt'.
 */
function current_lang(): string
{
    static $resolved = null;
    if ($resolved !== null) {
        return $resolved;
    }

    $supported = ['pt', 'en'];

    if (isset($_GET['lang']) && in_array($_GET['lang'], $supported, true)) {
        $_SESSION['lang'] = $_GET['lang'];
    }

    $candidate = $_SESSION['lang']
        ?? (current_user()['lang'] ?? null)
        ?? 'pt';

    $resolved = in_array($candidate, $supported, true) ? $candidate : 'pt';
    return $resolved;
}

/**
 * Traduz uma chave. Substitui placeholders `:var` com valores de $params.
 * Se a chave não existir, retorna a própria chave e loga em storage/logs/i18n-missing.log.
 *
 * $lang opcional permite renderizar num idioma específico (ex.: email seguindo
 * preferência do destinatário — ADR-014). Quando omitido usa current_lang().
 */
function __t(string $key, array $params = [], ?string $lang = null): string
{
    static $cache = [];
    $lang = $lang ?? current_lang();

    if (!isset($cache[$lang])) {
        $file = LMS_ROOT . '/lang/' . $lang . '.php';
        $data = is_file($file) ? require $file : [];
        $cache[$lang] = is_array($data) ? $data : [];
    }

    $value = $cache[$lang][$key] ?? null;

    if ($value === null) {
        $logFile = LMS_ROOT . '/storage/logs/i18n-missing.log';
        $line = sprintf("[%s] %s: %s\n", date('Y-m-d H:i:s'), $lang, $key);
        @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
        return $key;
    }

    if ($params === []) {
        return $value;
    }

    $replace = [];
    foreach ($params as $k => $v) {
        $replace[':' . $k] = (string) $v;
    }
    return strtr($value, $replace);
}

// ---------------------------------------------------------------------
// CSRF
// ---------------------------------------------------------------------

const CSRF_TTL_SECONDS = 1800; // 30 min

function csrf_token(): string
{
    $expires = $_SESSION['_csrf_expires'] ?? 0;
    if (empty($_SESSION['_csrf']) || $expires < time()) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        $_SESSION['_csrf_expires'] = time() + CSRF_TTL_SECONDS;
    }
    return $_SESSION['_csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

/**
 * Valida o token do POST atual. Token é one-time use: após validar, é rotacionado.
 * Lança RuntimeException com status 403 se inválido.
 */
function csrf_verify(): void
{
    $posted  = (string) ($_POST['_csrf'] ?? '');
    $stored  = (string) ($_SESSION['_csrf'] ?? '');
    $expires = (int)    ($_SESSION['_csrf_expires'] ?? 0);

    if ($stored === '' || $posted === '' || $expires < time() || !hash_equals($stored, $posted)) {
        http_response_code(403);
        throw new RuntimeException('CSRF token inválido ou expirado');
    }

    // Rotaciona: próxima request recebe token novo.
    unset($_SESSION['_csrf'], $_SESSION['_csrf_expires']);
}

// ---------------------------------------------------------------------
// Auth
// ---------------------------------------------------------------------

function current_user(): ?array
{
    $u = $_SESSION['user'] ?? null;
    return is_array($u) ? $u : null;
}

function current_tenant_id(): ?int
{
    $u = current_user();
    if ($u === null || ($u['role'] ?? null) !== 'teacher') {
        return null;
    }
    $tid = $u['tenant_id'] ?? null;
    return $tid !== null ? (int) $tid : null;
}

function require_auth(): void
{
    if (current_user() !== null) {
        return;
    }
    $next = $_SERVER['REQUEST_URI'] ?? '/';
    header('Location: /login?next=' . urlencode($next));
    exit;
}

/**
 * Exige que o usuário logado tenha um dos papéis informados.
 * Uso: require_role('teacher'); ou require_role('teacher', 'super_admin');
 */
function require_role(string ...$roles): void
{
    require_auth();
    $userRole = current_user()['role'] ?? null;
    if (!in_array($userRole, $roles, true)) {
        http_response_code(403);
        exit;
    }
}

// ---------------------------------------------------------------------
// Flash messages
// ---------------------------------------------------------------------

/**
 * Empilha uma mensagem flash para aparecer no próximo render do layout.
 * $type: success | danger | warning | info
 */
function flash(string $type, string $message): void
{
    if (!isset($_SESSION['flash']) || !is_array($_SESSION['flash'])) {
        $_SESSION['flash'] = [];
    }
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

/**
 * Renderiza todas as mensagens flash pendentes. Invocado pelo layout;
 * pode ser chamado manualmente em páginas que não usam o layout mestre.
 */
function render_flash(): void
{
    require LMS_ROOT . '/src/templates/flash.php';
}
