<?php
declare(strict_types=1);

/**
 * POST /api/code/run (E8-01) — proxy backend pro Judge0 CE.
 *
 * Validações (na ordem):
 *   1. Método HTTP POST + Content-Type form (o cliente JS envia
 *      `application/x-www-form-urlencoded` pra CSRF padrão funcionar)
 *   2. Role=student
 *   3. CSRF token (campo `_csrf`)
 *   4. `activity_id` int > 0
 *   5. Matrícula ativa via `ActivitySubmission::findForStudentActivity`
 *   6. `type = 'codigo'` + `allow_online_code_run = 1`
 *   7. `code_language` ∈ {python, javascript, csharp} (html usa sandbox)
 *   8. Tamanho do `code` ≤ 64KB
 *
 * Resposta (sempre JSON):
 *   200 {ok:true, stdout, stderr, time, status_id}
 *   200 {ok:false, error_key}  — erros de negócio/configuração/quota
 *   4xx/5xx {ok:false, error_key}  — erros de protocolo
 */

// Evita qualquer output prévio antes do JSON final (layout etc.)
ob_start();
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

/** @return never */
function judge0_json_response(int $httpCode, array $payload): void
{
    if (ob_get_length() !== false) {
        ob_clean();
    }
    http_response_code($httpCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    judge0_json_response(405, ['ok' => false, 'error_key' => 'code_run.err.method']);
}

// require_auth já rodou no front controller; confirmar role student.
$user = current_user();
if ($user === null || ($user['role'] ?? '') !== 'student') {
    judge0_json_response(403, ['ok' => false, 'error_key' => 'code_run.err.forbidden']);
}

// CSRF — usa verify-no-rotate porque o aluno pode executar várias vezes
// na mesma página sem reload. Token vale até expirar (TTL 30 min).
try {
    csrf_verify_no_rotate();
} catch (RuntimeException) {
    judge0_json_response(403, ['ok' => false, 'error_key' => 'code_run.err.csrf']);
}

$activityId = (int) ($_POST['activity_id'] ?? 0);
$code       = (string) ($_POST['code'] ?? '');

if ($activityId <= 0) {
    judge0_json_response(400, ['ok' => false, 'error_key' => 'code_run.err.bad_request']);
}

$studentId = (int) $user['id'];
$ctx = ActivitySubmission::findForStudentActivity($activityId, $studentId);
if ($ctx === null) {
    judge0_json_response(404, ['ok' => false, 'error_key' => 'code_run.err.not_found']);
}

$activity = $ctx['activity'];
if (($activity['type'] ?? '') !== 'codigo') {
    judge0_json_response(400, ['ok' => false, 'error_key' => 'code_run.err.not_code']);
}
if ((int) ($activity['allow_online_code_run'] ?? 0) !== 1) {
    judge0_json_response(403, ['ok' => false, 'error_key' => 'code_run.err.disabled']);
}

$language = (string) ($activity['code_language'] ?? '');
if ($language === '' || $language === 'html') {
    // html roda local (E8-03); sem language setada = professor não configurou
    judge0_json_response(400, ['ok' => false, 'error_key' => 'code_run.err.bad_language']);
}

if (strlen($code) > 65536) {
    judge0_json_response(413, ['ok' => false, 'error_key' => 'code_run.err.too_large']);
}

$result = Judge0Client::run($language, $code);

$status = (string) ($result['status'] ?? 'remote_error');
if ($status === 'ok') {
    judge0_json_response(200, [
        'ok'        => true,
        'stdout'    => (string) ($result['stdout'] ?? ''),
        'stderr'    => (string) ($result['stderr'] ?? ''),
        'time'      => $result['time'] ?? null,
        'status_id' => (int) ($result['status_id'] ?? 0),
    ]);
}

// Mapa status interno → error_key i18n (client-side lookup)
$errorKey = match ($status) {
    'unavailable'     => 'code_run.err.unavailable',
    'quota_exceeded'  => 'code_run.err.quota',
    'timeout'         => 'code_run.err.timeout',
    'too_large'       => 'code_run.err.too_large',
    'unsupported'     => 'code_run.err.bad_language',
    default           => 'code_run.err.remote',
};
$http = match ($status) {
    'unavailable'    => 503,
    'quota_exceeded' => 503,
    'timeout'        => 504,
    'too_large'      => 413,
    'unsupported'    => 400,
    default          => 502,
};
judge0_json_response($http, ['ok' => false, 'error_key' => $errorKey]);
