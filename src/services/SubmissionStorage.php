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
 * Tipos aceitos: pdf, zip, txt (doc 06). Máximo 3 MB.
 */
final class SubmissionStorage
{
    public const MAX_BYTES = 3 * 1024 * 1024; // 3 MB

    private const ALLOWED = [
        'application/pdf' => 'pdf',
        'application/zip' => 'zip',
        'text/plain'      => 'txt',
    ];

    /**
     * Valida + salva arquivo, sobrescrevendo qualquer versão anterior do
     * mesmo aluno pra mesma atividade. Retorna status + contexto.
     *
     * @param array{name:string,tmp_name:string,size:int,error:int,type?:string} $file
     * @return array{status:string, filename?:string, stored_path?:string, error_key?:string}
     */
    public static function store(array $file, int $activityId, int $studentId, int $tenantId): array
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
        if (!isset(self::ALLOWED[$mime])) {
            return ['status' => 'error', 'error_key' => 'submissions.err.mime'];
        }

        $ext = self::ALLOWED[$mime];
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
     */
    private static function cleanupExistingFor(int $studentId, string $baseDir): void
    {
        $realBase = realpath(LMS_ROOT . '/storage/uploads');
        foreach (array_keys(self::ALLOWED) as $mime) {
            $ext     = self::ALLOWED[$mime];
            $path    = $baseDir . '/' . $studentId . '.' . $ext;
            $real    = @realpath($path);
            if ($realBase !== false && $real !== false && str_starts_with($real, $realBase)) {
                @unlink($real);
            }
        }
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
