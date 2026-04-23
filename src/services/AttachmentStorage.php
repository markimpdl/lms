<?php
declare(strict_types=1);

/**
 * Serviço de armazenamento de anexos de conteúdo da CU (E5-03).
 *
 * Responsável por validar o arquivo enviado, renomear para UUID,
 * salvar em disco e criar o registro via `ContentAttachment::create`.
 *
 * Layout de arquivos em disco:
 *   storage/uploads/tenant_<tid>/content/<cu_id>/<uuid>.<ext>
 *
 * Essa pasta fica FORA do document root (`public/`) — downloads só via
 * rota autenticada em E5-04. O nome original do arquivo é preservado na
 * coluna `filename` apenas para display; nomes duplicados coexistem
 * porque o UUID diferencia no disco.
 *
 * Tipos aceitos + mime validado via `finfo_file` (mime real, não só
 * extensão). Tamanho máximo 3 MB.
 */
final class AttachmentStorage
{
    public const MAX_BYTES = 3 * 1024 * 1024; // 3 MB
    public const MAX_ATTACHMENTS_PER_CU = 50; // Previne DDoS via criação em massa

    /** Mapa de mime canônico → extensão a gravar. */
    private const ALLOWED = [
        'application/pdf'    => 'pdf',
        'application/zip'    => 'zip',
        'text/plain'         => 'txt',
        'image/png'          => 'png',
        'image/jpeg'         => 'jpg',
        'image/gif'          => 'gif',
        'image/webp'         => 'webp',
    ];

    /**
     * Processa um $_FILES['...'] entry vindo do POST de upload.
     * Cria o content vazio se a CU ainda não tiver (via Content::ensureForCu)
     * e associa o anexo a ele.
     *
     * @param array{name:string,tmp_name:string,size:int,error:int,type?:string} $file
     * @return array{status:string, attachment_id?:int, error_key?:string}
     *         status: 'ok' | 'error'
     *         error_key: chave i18n (attachments.err.*) quando status='error'
     */
    public static function store(array $file, int $tenantId, int $cuId): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return ['status' => 'error', 'error_key' => self::mapUploadError((int) $file['error'])];
        }
        if ((int) $file['size'] <= 0 || (int) $file['size'] > self::MAX_BYTES) {
            return ['status' => 'error', 'error_key' => 'attachments.err.size'];
        }

        $tmp = (string) $file['tmp_name'];
        if (!is_uploaded_file($tmp)) {
            return ['status' => 'error', 'error_key' => 'attachments.err.generic'];
        }

        // Validação: previne DDoS via criação em massa
        $countStmt = Database::pdo()->prepare(
            'SELECT COUNT(*) as cnt FROM content_attachments a
             JOIN contents c ON c.id = a.content_id
             WHERE c.competence_unit_id = ?'
        );
        $countStmt->execute([$cuId]);
        $count = (int) $countStmt->fetchColumn();
        if ($count >= self::MAX_ATTACHMENTS_PER_CU) {
            return ['status' => 'error', 'error_key' => 'attachments.err.limit'];
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = (string) $finfo->file($tmp);
        if (!isset(self::ALLOWED[$mime])) {
            return ['status' => 'error', 'error_key' => 'attachments.err.mime'];
        }

        $contentId = Content::ensureForCu($cuId, $tenantId);
        if ($contentId === 'not_found') {
            return ['status' => 'error', 'error_key' => 'attachments.err.cu_unavailable'];
        }

        $ext       = self::ALLOWED[$mime];
        $uuid      = self::uuid4();
        $baseDir   = LMS_ROOT . '/storage/uploads/tenant_' . $tenantId . '/content/' . $cuId;
        if (!is_dir($baseDir) && !@mkdir($baseDir, 0755, true)) {
            return ['status' => 'error', 'error_key' => 'attachments.err.generic'];
        }

        $storedName = $uuid . '.' . $ext;
        $storedFull = $baseDir . '/' . $storedName;
        if (!@move_uploaded_file($tmp, $storedFull)) {
            return ['status' => 'error', 'error_key' => 'attachments.err.generic'];
        }

        // stored_path relativo à raiz do projeto, com / normalizado.
        $relative = 'storage/uploads/tenant_' . $tenantId . '/content/' . $cuId . '/' . $storedName;

        $filename = self::sanitizeFilename((string) $file['name']);

        $aid = ContentAttachment::create(
            (int) $contentId,
            $filename,
            $relative,
            $mime,
            (int) $file['size']
        );

        return ['status' => 'ok', 'attachment_id' => $aid];
    }

    /**
     * Serve o arquivo do anexo ao cliente com headers apropriados.
     * Caller é responsável por já ter validado acesso (via
     * `ContentAttachment::findForTenant` ou `findForStudent`) e passar o
     * registro resolvido. Emite headers, faz `readfile` e `exit` — nunca
     * retorna.
     *
     * @param array<string,mixed> $attachment registro de content_attachments
     * @param 'inline'|'attachment' $disposition
     */
    public static function stream(array $attachment, string $disposition): never
    {
        $fullPath = LMS_ROOT . '/' . $attachment['stored_path'];
        $realBase = realpath(LMS_ROOT . '/storage/uploads');
        $realFile = @realpath($fullPath);

        // Defesa contra path traversal: stored_path vem do DB (nosso controle),
        // mas validamos contra diretório esperado antes de servir.
        if ($realBase === false || $realFile === false || !str_starts_with($realFile, $realBase)) {
            http_response_code(404);
            require LMS_ROOT . '/src/templates/errors/404.php';
            exit;
        }

        // Sanitização do filename pro header: remove quebras de linha e aspas
        // que poderiam injetar cabeçalhos falsos (CRLF injection).
        $safeName = str_replace(['"', "\n", "\r"], '_', (string) $attachment['filename']);

        header('Content-Type: ' . $attachment['mime']);
        header('Content-Length: ' . filesize($realFile));
        header('Content-Disposition: ' . $disposition . '; filename="' . $safeName . '"');
        header('X-Content-Type-Options: nosniff');
        readfile($realFile);
        exit;
    }

    /**
     * Deleta registro + arquivo físico. Retorna true se sucesso, false se
     * anexo não pertence ao tenant. Arquivo físico ausente NÃO é erro (o
     * registro é removido mesmo assim — mantém consistência).
     */
    public static function delete(int $aid, int $tenantId): bool
    {
        $att = ContentAttachment::findForTenant($aid, $tenantId);
        if ($att === null) {
            return false;
        }

        ContentAttachment::delete($aid);

        $fullPath = LMS_ROOT . '/' . $att['stored_path'];
        // Validação defensiva contra path traversal: stored_path sempre começa
        // com 'storage/uploads/tenant_<id>/content/'.
        $realBase = realpath(LMS_ROOT . '/storage/uploads');
        $realFile = @realpath($fullPath);
        if ($realBase !== false && $realFile !== false && str_starts_with($realFile, $realBase)) {
            @unlink($realFile);
        }
        return true;
    }

    /**
     * Sanitiza o nome original pra exibição: só ASCII, traços, underscores,
     * espaços; limita 120 chars; impede path traversal.
     */
    private static function sanitizeFilename(string $name): string
    {
        $name = basename($name);
        $name = preg_replace('/[^A-Za-z0-9._\- ]/u', '_', $name) ?? 'arquivo';
        $name = trim($name);
        if ($name === '' || $name === '.' || $name === '..') {
            $name = 'arquivo';
        }
        return mb_substr($name, 0, 120);
    }

    private static function uuid4(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    private static function mapUploadError(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'attachments.err.size',
            UPLOAD_ERR_NO_FILE                         => 'attachments.err.no_file',
            default                                    => 'attachments.err.generic',
        };
    }
}
