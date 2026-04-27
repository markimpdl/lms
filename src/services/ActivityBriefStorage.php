<?php
declare(strict_types=1);

/**
 * Armazenamento do brief (PDF/ZIP) da atividade tipo `projeto` (v0.30.0).
 *
 * Clone do `EvaluationBriefStorage` aplicado a atividades — mesma decisão
 * de design (1 arquivo por atividade, deterministic name, PDF+ZIP, teto via
 * `UPLOAD_MAX_MB_PDF_BRIEF`). Reupload alterna PDF↔ZIP apagando o anterior.
 *
 * Layout em disco:
 *   storage/uploads/tenant_<tid>/activities/<activity_id>/brief.<ext>
 *
 * Disponível só pra `activities.type = 'projeto'` — caller (form/handler)
 * gateia. Aqui é só armazenamento.
 */
final class ActivityBriefStorage
{
    private const DEFAULT_MAX_MB = 12;

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

    public static function mimeFromStoredPath(string $storedPath): string
    {
        $ext = strtolower(pathinfo($storedPath, PATHINFO_EXTENSION));
        if ($ext === 'zip') {
            return 'application/zip';
        }
        return 'application/pdf';
    }

    /**
     * @param array{name:string,tmp_name:string,size:int,error:int,type?:string} $file
     * @return array{status:string, filename?:string, stored_path?:string, error_key?:string}
     */
    public static function store(array $file, int $activityId, int $tenantId): array
    {
        $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err !== UPLOAD_ERR_OK) {
            return ['status' => 'error', 'error_key' => self::mapUploadError($err)];
        }
        $size = (int) $file['size'];
        if ($size <= 0 || $size > self::maxBytes()) {
            return ['status' => 'error', 'error_key' => 'activities.err.brief_size'];
        }
        $tmp = (string) $file['tmp_name'];
        if (!is_uploaded_file($tmp)) {
            return ['status' => 'error', 'error_key' => 'activities.err.brief_generic'];
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = (string) $finfo->file($tmp);
        $ext   = self::resolveExtension($mime, (string) $file['name']);
        if ($ext === null) {
            return ['status' => 'error', 'error_key' => 'activities.err.brief_mime'];
        }

        $baseDir = LMS_ROOT . '/storage/uploads/tenant_' . $tenantId . '/activities/' . $activityId;
        if (!is_dir($baseDir) && !@mkdir($baseDir, 0775, true)) {
            return ['status' => 'error', 'error_key' => 'activities.err.brief_generic'];
        }

        self::deleteAnyBrief($baseDir);

        $storedFilename = 'brief.' . $ext;
        $storedFull     = $baseDir . '/' . $storedFilename;
        if (!@move_uploaded_file($tmp, $storedFull)) {
            return ['status' => 'error', 'error_key' => 'activities.err.brief_generic'];
        }

        return [
            'status'      => 'ok',
            'filename'    => self::sanitizeFilename((string) $file['name'], $ext),
            'stored_path' => 'storage/uploads/tenant_' . $tenantId . '/activities/' . $activityId . '/' . $storedFilename,
        ];
    }

    public static function delete(int $activityId, int $tenantId): void
    {
        $baseDir = LMS_ROOT . '/storage/uploads/tenant_' . $tenantId . '/activities/' . $activityId;
        if (!is_dir($baseDir)) {
            return;
        }
        self::deleteAnyBrief($baseDir);
        @rmdir($baseDir);
    }

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

    private static function resolveExtension(string $mime, string $originalName): ?string
    {
        $nameExt = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        foreach (self::ALLOWED as $ext => $mimes) {
            if (in_array($mime, $mimes, true)) {
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
        if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== $ext) {
            $name .= '.' . $ext;
        }
        return mb_substr($name, 0, 200);
    }

    private static function mapUploadError(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'activities.err.brief_size',
            UPLOAD_ERR_NO_FILE                         => 'activities.err.brief_no_file',
            default                                    => 'activities.err.brief_generic',
        };
    }
}
