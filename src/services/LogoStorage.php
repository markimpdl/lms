<?php
declare(strict_types=1);

/**
 * Armazenamento da logo customizada do tenant (E24-03).
 *
 * Logos são assets PÚBLICOS: ficam em `public/uploads/logos/<basename>` e
 * são servidos como estáticos pelo web server. Diferente de attachments
 * de avaliação (que vivem em `storage/uploads/` fora do document root e
 * passam por gate PHP), a logo aparece na navbar pra alunos não logados
 * de outros tenants em URLs cross-origin? Não — é gated pela navbar do
 * próprio tenant; mas se alguém adivinhar a URL pode acessar. Aceitável
 * pra branding (não há informação sensível no asset).
 *
 * Convenção de nome: `<tenantId>-<unixTimestamp>.<ext>` — único por
 * tenant+momento, sem colisão e auditável. A coluna `tenants.logo_path`
 * armazena apenas o basename; URL final é montada por `tenant_branding()`.
 *
 * Reupload substitui: o caller passa `previousBasename` e o service apaga
 * o arquivo antigo após gravar o novo (best-effort — falha em apagar não
 * derruba o sucesso do upload).
 *
 * **SVG trust boundary**: SVGs podem conter `<script>` e executar JS no
 * contexto do site. Como apenas o próprio professor faz upload e a logo
 * é exibida só na própria navbar dele e dos alunos dele, o impacto fica
 * contido. Aceitamos a extensão sem sanitizar conteúdo no MVP. Se
 * precisarmos no futuro: stripar `<script>`, `<foreignObject>`, `on*`
 * attributes via DOMDocument.
 */
final class LogoStorage
{
    public const MAX_BYTES = 512 * 1024; // 500 KB conforme spec do épico

    private const RELATIVE_DIR = '/public/uploads/logos';

    /**
     * Extensões aceitas → MIMEs canônicos. SVG aceita variantes porque
     * `finfo` em algumas versões PHP/OS retorna `text/plain` ou
     * `text/xml` em vez de `image/svg+xml`.
     */
    private const ALLOWED = [
        'png'  => ['image/png'],
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'svg'  => ['image/svg+xml', 'image/svg', 'text/plain', 'text/xml', 'application/xml'],
    ];

    /**
     * @param array{name:string,tmp_name:string,size:int,error:int,type?:string} $file
     * @return array{status:string, basename?:string, error_key?:string}
     */
    public static function store(array $file, int $tenantId, ?string $previousBasename = null): array
    {
        $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err !== UPLOAD_ERR_OK) {
            return ['status' => 'error', 'error_key' => self::mapUploadError($err)];
        }

        $size = (int) $file['size'];
        if ($size <= 0 || $size > self::MAX_BYTES) {
            return ['status' => 'error', 'error_key' => 'teacher.settings.logo_too_large'];
        }

        $tmp = (string) $file['tmp_name'];
        if (!is_uploaded_file($tmp)) {
            return ['status' => 'error', 'error_key' => 'teacher.settings.logo_generic'];
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = (string) $finfo->file($tmp);

        $ext = self::resolveExtension($mime, (string) $file['name']);
        if ($ext === null) {
            return ['status' => 'error', 'error_key' => 'teacher.settings.logo_invalid_mime'];
        }

        $baseDir = LMS_ROOT . self::RELATIVE_DIR;
        if (!is_dir($baseDir) && !@mkdir($baseDir, 0775, true)) {
            return ['status' => 'error', 'error_key' => 'teacher.settings.logo_generic'];
        }

        $basename = $tenantId . '-' . time() . '.' . $ext;
        $fullPath = $baseDir . '/' . $basename;

        if (!@move_uploaded_file($tmp, $fullPath)) {
            return ['status' => 'error', 'error_key' => 'teacher.settings.logo_generic'];
        }

        // Best-effort: apaga o arquivo antigo. Path traversal defendido por
        // realpath comparado ao baseDir (basename pode vir do banco e em
        // tese estar corrompido).
        if ($previousBasename !== null && $previousBasename !== '' && $previousBasename !== $basename) {
            self::deleteByBasename($previousBasename);
        }

        return ['status' => 'ok', 'basename' => $basename];
    }

    /**
     * Remove arquivo de logo pelo basename (se existir e estiver dentro do
     * diretório esperado). Best-effort.
     */
    public static function deleteByBasename(string $basename): void
    {
        if ($basename === '' || str_contains($basename, '/') || str_contains($basename, '\\')) {
            return;
        }
        $baseDir = LMS_ROOT . self::RELATIVE_DIR;
        $realBase = @realpath($baseDir);
        if ($realBase === false) {
            return;
        }
        $target = $baseDir . '/' . $basename;
        $real   = @realpath($target);
        if ($real !== false && str_starts_with($real, $realBase)) {
            @unlink($real);
        }
    }

    private static function resolveExtension(string $mime, string $originalName): ?string
    {
        $nameExt = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        // Para MIME ambíguo (text/plain, text/xml, application/xml),
        // exige que a extensão do nome bata pra evitar aceitar TXT como SVG.
        $ambiguousMimes = ['text/plain', 'text/xml', 'application/xml'];

        foreach (self::ALLOWED as $ext => $mimes) {
            if (in_array($mime, $mimes, true)) {
                if (in_array($mime, $ambiguousMimes, true) && $nameExt !== $ext) {
                    continue;
                }
                return $ext;
            }
        }
        return null;
    }

    private static function mapUploadError(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'teacher.settings.logo_too_large',
            UPLOAD_ERR_NO_FILE                         => 'teacher.settings.logo_no_file',
            default                                    => 'teacher.settings.logo_generic',
        };
    }
}
