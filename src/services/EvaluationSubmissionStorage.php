<?php
declare(strict_types=1);

/**
 * Armazenamento de arquivos de submissão de avaliação (E7-02).
 *
 * Layout em disco:
 *   storage/uploads/tenant_<tid>/evaluation_submissions/<evaluation_id>/<student_id>_<attempt>.<ext>
 *
 * Diferente de `SubmissionStorage` (1 arquivo por aluno, determinístico),
 * aqui cada tentativa gera um arquivo próprio — o aluno pode ter múltiplas
 * linhas em `evaluation_submissions` (histórico) e cada uma guarda seu PDF.
 *
 * Tipos aceitos: pdf, zip, txt. Máximo 3 MB (mesmo teto do E6).
 */
final class EvaluationSubmissionStorage
{
    public const MAX_BYTES = 3 * 1024 * 1024;

    private const ALLOWED = [
        'application/pdf' => 'pdf',
        'application/zip' => 'zip',
        'text/plain'      => 'txt',
    ];

    /**
     * Valida + salva arquivo. Diferente de `SubmissionStorage`, aqui NÃO
     * apagamos arquivos anteriores — a tabela `evaluation_submissions`
     * mantém histórico.
     *
     * @param array{name:string,tmp_name:string,size:int,error:int,type?:string} $file
     * @return array{status:string, filename?:string, stored_path?:string, error_key?:string}
     */
    public static function store(
        array $file,
        int $evaluationId,
        int $studentId,
        int $attempt,
        int $tenantId
    ): array {
        $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err !== UPLOAD_ERR_OK) {
            return ['status' => 'error', 'error_key' => self::mapUploadError($err)];
        }
        $size = (int) $file['size'];
        if ($size <= 0 || $size > self::MAX_BYTES) {
            return ['status' => 'error', 'error_key' => 'evaluations.student.err.size'];
        }
        $tmp = (string) $file['tmp_name'];
        if (!is_uploaded_file($tmp)) {
            return ['status' => 'error', 'error_key' => 'evaluations.student.err.generic'];
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = (string) $finfo->file($tmp);
        if (!isset(self::ALLOWED[$mime])) {
            return ['status' => 'error', 'error_key' => 'evaluations.student.err.mime'];
        }

        $ext = self::ALLOWED[$mime];
        $baseDir = LMS_ROOT . '/storage/uploads/tenant_' . $tenantId
                 . '/evaluation_submissions/' . $evaluationId;
        if (!is_dir($baseDir) && !@mkdir($baseDir, 0775, true)) {
            return ['status' => 'error', 'error_key' => 'evaluations.student.err.generic'];
        }

        $storedName = $studentId . '_' . $attempt . '.' . $ext;
        $storedFull = $baseDir . '/' . $storedName;
        if (!@move_uploaded_file($tmp, $storedFull)) {
            return ['status' => 'error', 'error_key' => 'evaluations.student.err.generic'];
        }

        return [
            'status'      => 'ok',
            'filename'    => self::sanitizeFilename((string) $file['name']),
            'stored_path' => 'storage/uploads/tenant_' . $tenantId
                           . '/evaluation_submissions/' . $evaluationId
                           . '/' . $storedName,
        ];
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
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'evaluations.student.err.size',
            UPLOAD_ERR_NO_FILE                         => 'evaluations.student.err.no_file',
            default                                    => 'evaluations.student.err.generic',
        };
    }
}
