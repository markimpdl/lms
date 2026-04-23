<?php
declare(strict_types=1);

/**
 * Armazenamento do PDF de enunciado da avaliação (E7-01).
 *
 * Layout em disco:
 *   storage/uploads/tenant_<tid>/evaluations/<evaluation_id>/brief.pdf
 *
 * Nome determinístico `brief.pdf`: o reupload substitui a versão anterior.
 * Diferente de anexos de conteúdo (UUID — semântica N:1), aqui cada
 * avaliação tem no máximo 1 PDF de enunciado.
 *
 * Tipo aceito: application/pdf. Teto configurável via
 * UPLOAD_MAX_MB_PDF_BRIEF em config/env.php (default 10 MB — ADR-028).
 */
final class EvaluationBriefStorage
{
    public const FILENAME = 'brief.pdf';
    private const DEFAULT_MAX_MB = 10;

    public static function maxBytes(): int
    {
        $mb = (int) ($GLOBALS['__ENV']['UPLOAD_MAX_MB_PDF_BRIEF'] ?? self::DEFAULT_MAX_MB);
        if ($mb <= 0) {
            $mb = self::DEFAULT_MAX_MB;
        }
        return $mb * 1024 * 1024;
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
            return ['status' => 'error', 'error_key' => 'evaluations.err.pdf_size'];
        }
        $tmp = (string) $file['tmp_name'];
        if (!is_uploaded_file($tmp)) {
            return ['status' => 'error', 'error_key' => 'evaluations.err.pdf_generic'];
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = (string) $finfo->file($tmp);
        if ($mime !== 'application/pdf') {
            return ['status' => 'error', 'error_key' => 'evaluations.err.pdf_mime'];
        }

        $baseDir = LMS_ROOT . '/storage/uploads/tenant_' . $tenantId . '/evaluations/' . $evaluationId;
        if (!is_dir($baseDir) && !@mkdir($baseDir, 0775, true)) {
            return ['status' => 'error', 'error_key' => 'evaluations.err.pdf_generic'];
        }

        self::deleteFileIfExists($baseDir);

        $storedFull = $baseDir . '/' . self::FILENAME;
        if (!@move_uploaded_file($tmp, $storedFull)) {
            return ['status' => 'error', 'error_key' => 'evaluations.err.pdf_generic'];
        }

        return [
            'status'      => 'ok',
            'filename'    => self::sanitizeFilename((string) $file['name']),
            'stored_path' => 'storage/uploads/tenant_' . $tenantId . '/evaluations/' . $evaluationId . '/' . self::FILENAME,
        ];
    }

    public static function delete(int $evaluationId, int $tenantId): void
    {
        $baseDir = LMS_ROOT . '/storage/uploads/tenant_' . $tenantId . '/evaluations/' . $evaluationId;
        if (!is_dir($baseDir)) {
            return;
        }
        self::deleteFileIfExists($baseDir);
        @rmdir($baseDir);
    }

    private static function deleteFileIfExists(string $baseDir): void
    {
        $realBase = realpath(LMS_ROOT . '/storage/uploads');
        $path     = $baseDir . '/' . self::FILENAME;
        $real     = @realpath($path);
        if ($realBase !== false && $real !== false && str_starts_with($real, $realBase)) {
            @unlink($real);
        }
    }

    private static function sanitizeFilename(string $name): string
    {
        $name = basename($name);
        $name = preg_replace('/[^A-Za-z0-9._\- ]/u', '_', $name) ?? 'arquivo.pdf';
        $name = trim($name);
        if ($name === '' || $name === '.' || $name === '..') {
            $name = 'arquivo.pdf';
        }
        return mb_substr($name, 0, 200);
    }

    private static function mapUploadError(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'evaluations.err.pdf_size',
            UPLOAD_ERR_NO_FILE                         => 'evaluations.err.pdf_no_file',
            default                                    => 'evaluations.err.pdf_generic',
        };
    }
}
