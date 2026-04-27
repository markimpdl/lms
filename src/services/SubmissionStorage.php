<?php
declare(strict_types=1);

/**
 * Armazenamento de arquivos de submissão de atividade (E6-03).
 *
 * Layout em disco:
 *   storage/uploads/tenant_<tid>/submissions/<activity_id>/<student_id>.<ext>
 *
 * Diferente de anexos de conteúdo (que usam UUID), o nome do arquivo da
 * submissão é determinístico pelo student_id. Isso simplifica o fluxo de
 * editar/remover (ADR-027): o arquivo novo sempre substitui o anterior;
 * o delete apaga o único arquivo daquele aluno pra aquela atividade.
 *
 * Tipos aceitos: pdf, zip, txt (doc 06) por padrão. Para atividades tipo
 * `código`, a allowlist é estendida com a extensão da linguagem (.py, .cs,
 * .js, .html) — caller passa `code_language` em `$opts`. Máximo 3 MB.
 */
final class SubmissionStorage
{
    public const MAX_BYTES = 3 * 1024 * 1024; // 3 MB

    private const ALLOWED_DEFAULT = [
        'application/pdf' => 'pdf',
        'application/zip' => 'zip',
        'text/plain'      => 'txt',
    ];

    /**
     * Mapa code_language → MIME esperado pra extensão da linguagem. Algumas
     * variantes ambíguas (text/x-csharp em alguns SOs, application/javascript
     * vs text/javascript) tratadas no `resolveAllowed`. Texto puro (.py, .cs,
     * .js, .html) cai sempre em text/plain pelo finfo na maioria dos sistemas
     * — por isso aceitamos text/plain pra qualquer linguagem (extensão do
     * nome decide qual ext final usar).
     */
    private const LANGUAGE_EXTRA = [
        'python'     => ['ext' => 'py',   'mimes' => ['text/x-python', 'text/x-script.python']],
        'csharp'     => ['ext' => 'cs',   'mimes' => ['text/x-csharp', 'text/plain']],
        'javascript' => ['ext' => 'js',   'mimes' => ['application/javascript', 'text/javascript', 'text/plain']],
        'html'       => ['ext' => 'html', 'mimes' => ['text/html', 'text/plain']],
    ];

    /**
     * Valida + salva arquivo, sobrescrevendo qualquer versão anterior do
     * mesmo aluno pra mesma atividade. Retorna status + contexto.
     *
     * @param array{name:string,tmp_name:string,size:int,error:int,type?:string} $file
     * @param array{code_language?:?string} $opts
     * @return array{status:string, filename?:string, stored_path?:string, error_key?:string}
     */
    public static function store(array $file, int $activityId, int $studentId, int $tenantId, array $opts = []): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return ['status' => 'error', 'error_key' => self::mapUploadError((int) $file['error'])];
        }
        if ((int) $file['size'] <= 0 || (int) $file['size'] > self::MAX_BYTES) {
            return ['status' => 'error', 'error_key' => 'submissions.err.size'];
        }
        $tmp = (string) $file['tmp_name'];
        if (!is_uploaded_file($tmp)) {
            return ['status' => 'error', 'error_key' => 'submissions.err.generic'];
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = (string) $finfo->file($tmp);
        $allowed = self::resolveAllowed((string) ($opts['code_language'] ?? ''));
        $ext = self::resolveExtension($mime, (string) $file['name'], $allowed);
        if ($ext === null) {
            return ['status' => 'error', 'error_key' => 'submissions.err.mime'];
        }
        $baseDir = LMS_ROOT . '/storage/uploads/tenant_' . $tenantId . '/submissions/' . $activityId;
        if (!is_dir($baseDir) && !@mkdir($baseDir, 0775, true)) {
            return ['status' => 'error', 'error_key' => 'submissions.err.generic'];
        }

        // Apaga versão anterior (se houver) antes de mover nova — evita
        // deixar arquivos de extensão antiga no disco quando o aluno
        // troca de pdf pra zip, por exemplo.
        self::cleanupExistingFor($studentId, $baseDir);

        $storedName = $studentId . '.' . $ext;
        $storedFull = $baseDir . '/' . $storedName;
        if (!@move_uploaded_file($tmp, $storedFull)) {
            return ['status' => 'error', 'error_key' => 'submissions.err.generic'];
        }

        return [
            'status'      => 'ok',
            'filename'    => self::sanitizeFilename((string) $file['name']),
            'stored_path' => 'storage/uploads/tenant_' . $tenantId . '/submissions/' . $activityId . '/' . $storedName,
        ];
    }

    /**
     * Remove arquivo físico da submissão. Não erra se já não existe.
     */
    public static function delete(int $activityId, int $studentId, int $tenantId): void
    {
        $baseDir = LMS_ROOT . '/storage/uploads/tenant_' . $tenantId . '/submissions/' . $activityId;
        if (is_dir($baseDir)) {
            self::cleanupExistingFor($studentId, $baseDir);
        }
    }

    /**
     * Apaga qualquer arquivo <studentId>.<ext> no dir da atividade.
     * Chamado antes de upload novo (pra trocar extensão) e no delete total.
     * Itera todas as extensões já usadas no projeto (default + extensões de
     * código) pra cobrir o caso do aluno trocar de .py pra .zip etc.
     */
    private static function cleanupExistingFor(int $studentId, string $baseDir): void
    {
        $realBase = realpath(LMS_ROOT . '/storage/uploads');
        $allExts  = array_unique(array_merge(
            array_values(self::ALLOWED_DEFAULT),
            array_column(self::LANGUAGE_EXTRA, 'ext')
        ));
        foreach ($allExts as $ext) {
            $path = $baseDir . '/' . $studentId . '.' . $ext;
            $real = @realpath($path);
            if ($realBase !== false && $real !== false && str_starts_with($real, $realBase)) {
                @unlink($real);
            }
        }
    }

    /**
     * Allowlist de MIMEs aceitos pro contexto. Sempre inclui o default
     * (.pdf/.zip/.txt). Quando `$codeLanguage` está setada, adiciona MIMEs
     * extras da linguagem.
     *
     * @return array<string,string> mime → ext
     */
    private static function resolveAllowed(string $codeLanguage): array
    {
        $allowed = self::ALLOWED_DEFAULT;
        if ($codeLanguage !== '' && isset(self::LANGUAGE_EXTRA[$codeLanguage])) {
            $cfg = self::LANGUAGE_EXTRA[$codeLanguage];
            foreach ($cfg['mimes'] as $mime) {
                // Não sobrescreve mapeamento default (text/plain → txt).
                $allowed[$mime] = $allowed[$mime] ?? $cfg['ext'];
            }
        }
        return $allowed;
    }

    /**
     * Resolve a extensão final do arquivo a partir do MIME real (finfo) +
     * nome original do upload. Pra casos ambíguos (text/plain pode ser .txt
     * OU .py/.cs/.js/.html), prioriza a extensão do nome original quando ela
     * está dentro da allowlist do contexto. Retorna null se nada bater.
     *
     * @param array<string,string> $allowed mime → ext default
     */
    private static function resolveExtension(string $mime, string $originalName, array $allowed): ?string
    {
        if (!isset($allowed[$mime])) {
            return null;
        }
        $defaultExt = $allowed[$mime];
        $nameExt    = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $allowedExts = array_unique(array_values($allowed));

        // Quando MIME é text/plain (ambíguo) e o nome é .py/.cs/.js/.html
        // que está na allowlist, preserva a extensão semanticamente correta.
        if ($mime === 'text/plain' && in_array($nameExt, $allowedExts, true) && $nameExt !== '') {
            return $nameExt;
        }
        return $defaultExt;
    }

    private static function sanitizeFilename(string $name): string
    {
        $name = basename($name);
        $name = preg_replace('/[^A-Za-z0-9._\- ]/u', '_', $name) ?? 'arquivo';
        $name = trim($name);
        if ($name === '' || $name === '.' || $name === '..') {
            $name = 'arquivo';
        }
        return mb_substr($name, 0, 200);
    }

    private static function mapUploadError(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'submissions.err.size',
            UPLOAD_ERR_NO_FILE                         => 'submissions.err.no_file',
            default                                    => 'submissions.err.generic',
        };
    }
}
