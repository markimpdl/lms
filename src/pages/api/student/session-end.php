<?php
declare(strict_types=1);

/**
 * POST /api/student/session-end (TIME-01, issue #436).
 *
 * Fecha explicitamente a sessao do aluno. Disparado pelo JS via
 * navigator.sendBeacon no beforeunload da aba lider — sendBeacon e
 * fire-and-forget, entao a resposta e best-effort (cliente nao processa).
 *
 * sendBeacon envia FormData com:
 *   - _csrf
 *   - session_uuid
 *
 * Resposta JSON simples (cliente ignora):
 *   200 {ok:true|false}
 *   4xx {ok:false, error_key:'...'}
 */

ob_start();
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

/** @return never */
function session_end_json(int $httpCode, array $payload): void
{
    if (ob_get_length() !== false) {
        ob_clean();
    }
    http_response_code($httpCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    session_end_json(405, ['ok' => false, 'error_key' => 'session_end.err.method']);
}

$user = current_user();
if ($user === null || ($user['role'] ?? '') !== 'student') {
    session_end_json(403, ['ok' => false, 'error_key' => 'session_end.err.forbidden']);
}

try {
    csrf_verify_no_rotate();
} catch (RuntimeException) {
    session_end_json(403, ['ok' => false, 'error_key' => 'session_end.err.csrf']);
}

$uuid = (string) ($_POST['session_uuid'] ?? '');
if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid) !== 1) {
    session_end_json(400, ['ok' => false, 'error_key' => 'session_end.err.bad_uuid']);
}

$studentId = (int) $user['id'];

try {
    $changed = StudentSession::endSession($uuid, $studentId);
} catch (\Throwable) {
    session_end_json(500, ['ok' => false, 'error_key' => 'session_end.err.internal']);
}

session_end_json(200, ['ok' => $changed]);
