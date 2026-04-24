<?php
declare(strict_types=1);

/**
 * Cliente HTTP do Judge0 CE (E8-01).
 *
 * Chama a API do Judge0 via RapidAPI com `base64_encoded=true` e
 * `wait=true` (síncrono). A chave (`JUDGE0_KEY`) nunca sai do servidor
 * — o endpoint `/api/code/run` é o único caminho pro aluno executar
 * código remoto, e ele valida matrícula + role antes de chamar aqui.
 *
 * Decisões consolidadas (ADR-029): sem rate limit próprio no LMS. Se o
 * Judge0 devolver 429, repassamos como `status='quota_exceeded'` — o
 * chamador traduz em mensagem amigável.
 *
 * Log em `storage/logs/judge0.log` por chamada pra diagnóstico.
 */
final class Judge0Client
{
    private const MAX_CODE_BYTES = 65536;

    /**
     * Mapa `code_language` → Judge0 language_id (valores estáveis no CE
     * via RapidAPI). Mantendo estes fixos pra isolar de mudanças no
     * upstream; se um id mudar, é 1 arquivo pra ajustar.
     */
    private const LANGUAGE_IDS = [
        'python'     => 71,  // Python (3.8.1)
        'javascript' => 63,  // JavaScript (Node.js 12.14.0)
        'csharp'     => 51,  // C# (Mono 6.6.0.161)
        // HTML não roda no Judge0 — usa sandbox local (E8-03).
    ];

    public static function isConfigured(): bool
    {
        $env = $GLOBALS['__ENV'] ?? [];
        return !empty($env['JUDGE0_HOST']) && !empty($env['JUDGE0_KEY']);
    }

    /**
     * Executa código sincronamente. Retorna array com shape consistente
     * independente do cenário — o chamador sempre checa `status`:
     *
     *   'ok'              — execução completou; stdout/stderr disponíveis
     *   'unavailable'     — Judge0 não configurado (key/host vazios)
     *   'unsupported'     — linguagem fora de LANGUAGE_IDS
     *   'too_large'       — code excede 64KB
     *   'quota_exceeded'  — HTTP 429 do Judge0
     *   'timeout'         — curl timeout (geralmente rede ou Judge0 travado)
     *   'remote_error'    — HTTP 5xx
     *   'bad_request'     — HTTP 4xx (exceto 429)
     *   'parse_error'     — resposta não é JSON válido
     *   'network_error'   — curl falhou sem HTTP status
     *
     * @return array{
     *   status:string,
     *   stdout?:string,
     *   stderr?:string,
     *   time?:?string,
     *   status_id?:int,
     *   error?:string
     * }
     */
    public static function run(string $language, string $code): array
    {
        if (!self::isConfigured()) {
            return ['status' => 'unavailable', 'error' => 'JUDGE0_HOST/KEY not set'];
        }
        $langId = self::LANGUAGE_IDS[$language] ?? null;
        if ($langId === null) {
            return ['status' => 'unsupported', 'error' => 'language=' . $language];
        }
        if (strlen($code) > self::MAX_CODE_BYTES) {
            return ['status' => 'too_large', 'error' => 'code size=' . strlen($code)];
        }

        $env = $GLOBALS['__ENV'];
        $host = (string) $env['JUDGE0_HOST'];
        $key  = (string) $env['JUDGE0_KEY'];
        $url  = 'https://' . $host
              . '/submissions?base64_encoded=true&wait=true'
              . '&fields=stdout,stderr,compile_output,time,status';

        $body = json_encode([
            'language_id'    => $langId,
            'source_code'    => base64_encode($code),
            'cpu_time_limit' => 5,
        ], JSON_THROW_ON_ERROR);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'X-RapidAPI-Host: ' . $host,
                'X-RapidAPI-Key: '  . $key,
            ],
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errNo    = curl_errno($ch);
        $errStr   = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            $status = $errNo === CURLE_OPERATION_TIMEDOUT ? 'timeout' : 'network_error';
            self::log($language, $status, null, $errStr);
            return ['status' => $status, 'error' => $errStr];
        }
        if ($httpCode === 429) {
            self::log($language, 'quota_exceeded', $httpCode, null);
            return ['status' => 'quota_exceeded', 'error' => 'HTTP 429'];
        }
        if ($httpCode >= 500) {
            self::log($language, 'remote_error', $httpCode, null);
            return ['status' => 'remote_error', 'error' => 'HTTP ' . $httpCode];
        }
        if ($httpCode >= 400) {
            self::log($language, 'bad_request', $httpCode, (string) $response);
            return ['status' => 'bad_request', 'error' => 'HTTP ' . $httpCode];
        }

        try {
            $data = json_decode((string) $response, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            self::log($language, 'parse_error', $httpCode, $e->getMessage());
            return ['status' => 'parse_error', 'error' => $e->getMessage()];
        }
        if (!is_array($data)) {
            self::log($language, 'parse_error', $httpCode, 'not an array');
            return ['status' => 'parse_error', 'error' => 'unexpected response shape'];
        }

        $stdout = self::b64decode($data['stdout']         ?? null);
        $stderr = self::b64decode($data['stderr']         ?? null);
        $cmpOut = self::b64decode($data['compile_output'] ?? null);
        $time   = $data['time']        ?? null;
        $statId = (int) ($data['status']['id'] ?? 0);

        // compile_output entra no stderr quando stderr vazio — aluno vê o
        // erro de compilação na mesma aba.
        if ($stderr === '' && $cmpOut !== '') {
            $stderr = $cmpOut;
        }

        self::log($language, 'ok', $httpCode, 'status_id=' . $statId . ' time=' . (string) $time);

        return [
            'status'    => 'ok',
            'stdout'    => $stdout,
            'stderr'    => $stderr,
            'time'      => $time !== null ? (string) $time : null,
            'status_id' => $statId,
        ];
    }

    private static function b64decode(?string $raw): string
    {
        if ($raw === null || $raw === '') {
            return '';
        }
        $decoded = base64_decode($raw, true);
        return $decoded === false ? '' : $decoded;
    }

    private static function log(string $language, string $status, ?int $httpCode, ?string $detail): void
    {
        $logFile = LMS_ROOT . '/storage/logs/judge0.log';
        $line = sprintf(
            "[%s] language=%s status=%s http=%s%s\n",
            date('Y-m-d H:i:s'),
            $language,
            $status,
            $httpCode !== null ? (string) $httpCode : '-',
            $detail !== null ? ' detail=' . str_replace(["\n", "\r"], ' ', $detail) : ''
        );
        @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    }
}
