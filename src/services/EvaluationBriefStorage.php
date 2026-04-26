<?php
declare(strict_types=1);

/**
 * Armazenamento do enunciado da avaliação tipo `projeto` (E7-01 + E23-01).
 *
 * Layout em disco:
 *   storage/uploads/tenant_<tid>/evaluations/<evaluation_id>/brief.<ext>
 *
 * Nome determinístico `brief.{pdf|zip}`: o reupload substitui a versão
 * anterior. Diferente de anexos de conteúdo (UUID — semântica N:1), aqui
 * cada avaliação tem no máximo 1 arquivo de enunciado.
 *
 * Tipos aceitos (E23-01): PDF e ZIP. Teto configurável via
 * UPLOAD_MAX_MB_PDF_BRIEF em config/env.php (default 10 MB — ADR-028).
 *
 * Re-upload alternando formato (PDF → ZIP ou vice-versa): apaga o
 * arquivo antigo de qualquer extensão antes de gravar o novo.
 */
final class EvaluationBriefStorage
{
    private const DEFAULT_MAX_MB = 10;

    /** Extensões aceitas e seus MIME types canônicos. ZIP tem 2 MIMEs comuns
     *  conforme browser/cliente (`application/zip` é o RFC; alguns Windows
     *  enviam `application/x-zip-compressed` ou `application/octet-stream`). */
    private const ALLOWED = [
        'pdf' => ['application/pdf'],
        'zip' => ['application/zip', 'application/x-zip-compressed', 'application/octet-stream'],
    ];

    public static function maxBytes(): int
    {
        $mb = (int) ($GLOBALS['__ENV']['UPLOAD_MAX_MB_PDF_BRIEF'] ?? self::DEFAULT_MAX_MB);
        if ($mb <= 0) {
            $mb = self::DEFAULT_MAX_MB;
        }
        return $mb * 1024 * 1024;
    }

    /**
     * Detecta extensão do path armazenado pra Content-Type no download.
     */
    public static function mimeFromStoredPath(string $storedPath): string
    {
        $ext = strtolower(pathinfo($storedPath, PATHINFO_EXTENSION));
        if ($ext === 'zip') {
            return 'application/zip';
        }
        return 'application/pdf'; // default + legados
    }

    /**
     * @param array{name:string,tmp_name:string,size:int,error:int,type?:string} $file
     * @return array{status:string, filename?:string, stored_path?:string, error_key?:string}
     */
    public static function store(array $file, int $evaluationId, int $tenantId): array
    {
        $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err !== UPLOAD_ERR_OK) {
            return ['status' => 'error', 'error_key' => self::mapUploadError($err)];
        }
        $size = (int) $file['size'];
        if ($size <= 0 || $size > self::maxBytes()) {
            return ['status' => 'error', 'error_key' => 'evaluations.err.brief_size'];
        }
        $tmp = (string) $file['tmp_name'];
        if (!is_uploaded_file($tmp)) {
            return ['status' => 'error', 'error_key' => 'evaluations.err.brief_generic'];
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = (string) $finfo->file($tmp);

        // Determina extensão baseada em MIME real + extensão do nome (fallback).
        $ext = self::resolveExtension($mime, (string) $file['name']);
        if ($ext === null) {
            return ['status' => 'error', 'error_key' => 'evaluations.err.brief_mime'];
        }

        $baseDir = LMS_ROOT . '/storage/uploads/tenant_' . $tenantId . '/evaluations/' . $evaluationId;
        if (!is_dir($baseDir) && !@mkdir($baseDir, 0775, true)) {
            return ['status' => 'error', 'error_key' => 'evaluations.err.brief_generic'];
        }

        // Apaga briefs anteriores (qualquer extensão) — re-upload substitui.
        self::deleteAnyBrief($baseDir);

        $storedFilename = 'brief.' . $ext;
        $storedFull     = $baseDir . '/' . $storedFilename;
        if (!@move_uploaded_file($tmp, $storedFull)) {
            return ['status' => 'error', 'error_key' => 'evaluations.err.brief_generic'];
        }

        return [
            'status'      => 'ok',
            'filename'    => self::sanitizeFilename((string) $file['name'], $ext),
            'stored_path' => 'storage/uploads/tenant_' . $tenantId . '/evaluations/' . $evaluationId . '/' . $storedFilename,
        ];
    }

    public static function delete(int $evaluationId, int $tenantId): void
    {
        $baseDir = LMS_ROOT . '/storage/uploads/tenant_' . $tenantId . '/evaluations/' . $evaluationId;
        if (!is_dir($baseDir)) {
            return;
        }
        self::deleteAnyBrief($baseDir);
        @rmdir($baseDir);
    }

    /**
     * Apaga `brief.pdf` e `brief.zip` se existirem. Defesa contra path
     * traversal via realpath comparado ao storage root.
     */
    private static function deleteAnyBrief(string $baseDir): void
    {
        $realBase = realpath(LMS_ROOT . '/storage/uploads');
        if ($realBase === false) {
            return;
        }
        foreach (array_keys(self::ALLOWED) as $ext) {
            $path = $baseDir . '/brief.' . $ext;
            $real = @realpath($path);
            if ($real !== false && str_starts_with($real, $realBase)) {
                @unlink($real);
            }
        }
    }

    /**
     * Resolve extensão final usando MIME real (`finfo`) + extensão do nome
     * como reforço. Retorna null se nenhum match — dispara erro de MIME.
     *
     * Cenário ZIP comum: `finfo` retorna `application/zip` em quase todos
     * casos. Mas alguns Windows mandam `application/octet-stream` quando
     * o arquivo é "puro" (sem assinatura). Nesse caso, fallback pra
     * extensão do nome (.zip) confirma intent do usuário.
     */
    private static function resolveExtension(string $mime, string $originalName): ?string
    {
        $nameExt = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        foreach (self::ALLOWED as $ext => $mimes) {
            if (in_array($mime, $mimes, true)) {
                // Quando MIME é octet-stream (genérico), exige bater com a extensão do nome.
                if ($mime === 'application/octet-stream' && $nameExt !== $ext) {
                    continue;
                }
                return $ext;
            }
        }
        return null;
    }

    private static function sanitizeFilename(string $name, string $ext): string
    {
        $name = basename($name);
        $name = preg_replace('/[^A-Za-z0-9._\- ]/u', '_', $name) ?? ('arquivo.' . $ext);
        $name = trim($name);
        if ($name === '' || $name === '.' || $name === '..') {
            $name = 'arquivo.' . $ext;
        }
        // Garante que o nome retornado tem a extensão correta (defensivo).
        if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== $ext) {
            $name .= '.' . $ext;
        }
        return mb_substr($name, 0, 200);
    }

    private static function mapUploadError(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'evaluations.err.brief_size',
            UPLOAD_ERR_NO_FILE                         => 'evaluations.err.brief_no_file',
            default                                    => 'evaluations.err.brief_generic',
        };
    }
}
