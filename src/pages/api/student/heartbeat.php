<?php
declare(strict_types=1);

/**
 * POST /api/student/heartbeat (TIME-01, issue #436).
 *
 * Recebe ping de 60s do JS do aluno e atualiza student_sessions.last_ping_at.
 * Cliente envia application/x-www-form-urlencoded com campos:
 *   - _csrf         token CSRF (verify-no-rotate, sessao pode rodar horas)
 *   - session_uuid  UUID v4 gerado em sessionStorage do cliente
 *
 * Resposta JSON:
 *   200 {ok:true, status:'created'|'updated'}
 *   200 {ok:false, status:'rejected_stale'|'rejected_closed'|'rejected_owner'}
 *        — cliente deve gerar novo UUID e re-tentar
 *   4xx {ok:false, error_key:'...'} — erros de protocolo
 */

ob_start();
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

/** @return never */
function heartbeat_json(int $httpCode, array $payload): void
{
    if (ob_get_length() !== false) {
        ob_clean();
    }
    http_response_code($httpCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    heartbeat_json(405, ['ok' => false, 'error_key' => 'heartbeat.err.method']);
}

$user = current_user();
if ($user === null || ($user['role'] ?? '') !== 'student') {
    heartbeat_json(403, ['ok' => false, 'error_key' => 'heartbeat.err.forbidden']);
}

try {
    csrf_verify_no_rotate();
} catch (RuntimeException) {
    heartbeat_json(403, ['ok' => false, 'error_key' => 'heartbeat.err.csrf']);
}

$uuid = (string) ($_POST['session_uuid'] ?? '');
if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid) !== 1) {
    heartbeat_json(400, ['ok' => false, 'error_key' => 'heartbeat.err.bad_uuid']);
}

$studentId = (int) $user['id'];
$tenantId  = (int) ($user['tenant_id'] ?? 0);
if ($tenantId <= 0) {
    // role=student sempre tem tenant_id (ADR-026 + CHK em users); se zerou,
    // sessao corrompida — forca novo login.
    heartbeat_json(403, ['ok' => false, 'error_key' => 'heartbeat.err.forbidden']);
}

$ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
$ua = isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : null;

try {
    $status = StudentSession::recordPing($uuid, $studentId, $tenantId, $ip, $ua);
} catch (\Throwable) {
    heartbeat_json(500, ['ok' => false, 'error_key' => 'heartbeat.err.internal']);
}

$ok = ($status === 'created' || $status === 'updated');
heartbeat_json(200, ['ok' => $ok, 'status' => $status]);
