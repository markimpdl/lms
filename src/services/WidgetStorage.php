<?php
declare(strict_types=1);

/**
 * Armazenamento de widgets (E35 / F26 — ADR-037).
 *
 * Recebe o .zip do widget, valida e extrai de forma segura para FORA do
 * document root: storage/widgets/tenant_<tid>/<slug>/. Os assets são servidos
 * depois por um endpoint PHP de passthrough (nunca executados pelo Apache).
 *
 * Defesas: limite de tamanho, finfo de zip, zip-slip guard (rejeita `..`,
 * caminho absoluto, backslash), allowlist de extensões internas (rejeita
 * .php/.phtml/executáveis), exige o arquivo de entrada (index.html).
 */
final class WidgetStorage
{
    public const MAX_BYTES = 12 * 1024 * 1024; // 12 MB
    public const ENTRY     = 'index.html';

    /** Extensões internas aceitas → content-type pro serving. */
    public const ALLOWED_EXT = [
        'html' => 'text/html; charset=utf-8',
        'htm'  => 'text/html; charset=utf-8',
        'css'  => 'text/css; charset=utf-8',
        'js'   => 'text/javascript; charset=utf-8',
        'mjs'  => 'text/javascript; charset=utf-8',
        'json' => 'application/json; charset=utf-8',
        'map'  => 'application/json; charset=utf-8',
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
        'svg'  => 'image/svg+xml',
        'ico'  => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf'  => 'font/ttf',
        'csv'  => 'text/csv; charset=utf-8',
        'txt'  => 'text/plain; charset=utf-8',
    ];

    /**
     * Valida e extrai o zip. Retorna:
     *   ['status'=>'ok','storage_path'=>'storage/widgets/tenant_X/slug','entry'=>'index.html']
     *   ['status'=>'error','error_key'=>'widgets.err.*']
     *
     * @param array{name:string,tmp_name:string,size:int,error:int} $file
     */
    public static function store(array $file, int $tenantId, string $slug): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return ['status' => 'error', 'error_key' => 'widgets.err.upload'];
        }
        if ((int) $file['size'] <= 0 || (int) $file['size'] > self::MAX_BYTES) {
            return ['status' => 'error', 'error_key' => 'widgets.err.size'];
        }
        $tmp = (string) $file['tmp_name'];
        if (!is_uploaded_file($tmp)) {
            return ['status' => 'error', 'error_key' => 'widgets.err.upload'];
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = (string) $finfo->file($tmp);
        if (!in_array($mime, ['application/zip', 'application/x-zip-compressed', 'application/octet-stream'], true)) {
            return ['status' => 'error', 'error_key' => 'widgets.err.not_zip'];
        }

        $zip = new ZipArchive();
        if ($zip->open($tmp) !== true) {
            return ['status' => 'error', 'error_key' => 'widgets.err.not_zip'];
        }

        // 1ª passada: valida toda entrada antes de gravar qualquer coisa.
        $files     = [];   // [normalizedRelPath => zipIndex]
        $hasEntry  = false;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if ($name === '' || str_ends_with($name, '/')) {
                continue; // diretório
            }
            // Ignora lixo de macOS sem rejeitar o pacote.
            if (str_starts_with($name, '__MACOSX/') || str_starts_with(basename($name), '._')) {
                continue;
            }
            // Zip-slip guard.
            $norm = str_replace('\\', '/', $name);
            if (str_contains($norm, "\0")
                || str_starts_with($norm, '/')
                || preg_match('#^[A-Za-z]:#', $norm) === 1
                || in_array('..', explode('/', $norm), true)
            ) {
                $zip->close();
                return ['status' => 'error', 'error_key' => 'widgets.err.unsafe_path'];
            }
            $ext = strtolower(pathinfo($norm, PATHINFO_EXTENSION));
            if (!isset(self::ALLOWED_EXT[$ext])) {
                $zip->close();
                return ['status' => 'error', 'error_key' => 'widgets.err.ext'];
            }
            if ($norm === self::ENTRY) {
                $hasEntry = true;
            }
            $files[$norm] = $i;
        }

        if (!$hasEntry) {
            $zip->close();
            return ['status' => 'error', 'error_key' => 'widgets.err.no_entry'];
        }
        if ($files === []) {
            $zip->close();
            return ['status' => 'error', 'error_key' => 'widgets.err.empty'];
        }

        // Destino fora do webroot. Re-upload: limpa antes.
        $relDir  = 'storage/widgets/tenant_' . $tenantId . '/' . $slug;
        $destDir = LMS_ROOT . '/' . $relDir;
        self::rrmdir($destDir);
        if (!@mkdir($destDir, 0755, true) && !is_dir($destDir)) {
            $zip->close();
            return ['status' => 'error', 'error_key' => 'widgets.err.generic'];
        }

        // 2ª passada: extrai só as entradas validadas, via stream.
        foreach ($files as $rel => $idx) {
            $target = $destDir . '/' . $rel;
            $subdir = dirname($target);
            if (!is_dir($subdir) && !@mkdir($subdir, 0755, true) && !is_dir($subdir)) {
                self::rrmdir($destDir);
                $zip->close();
                return ['status' => 'error', 'error_key' => 'widgets.err.generic'];
            }
            $stream = $zip->getStream((string) $zip->getNameIndex($idx));
            if ($stream === false) {
                self::rrmdir($destDir);
                $zip->close();
                return ['status' => 'error', 'error_key' => 'widgets.err.generic'];
            }
            $out = @fopen($target, 'wb');
            if ($out === false) {
                fclose($stream);
                self::rrmdir($destDir);
                $zip->close();
                return ['status' => 'error', 'error_key' => 'widgets.err.generic'];
            }
            stream_copy_to_stream($stream, $out);
            fclose($stream);
            fclose($out);
        }
        $zip->close();

        return ['status' => 'ok', 'storage_path' => $relDir, 'entry' => self::ENTRY];
    }

    /** Remove a pasta do widget (best-effort), confinada a storage/widgets. */
    public static function deleteDir(string $storagePathRelative): void
    {
        $full = LMS_ROOT . '/' . ltrim($storagePathRelative, '/');
        $base = realpath(LMS_ROOT . '/storage/widgets');
        $real = realpath($full);
        if ($base !== false && $real !== false && str_starts_with($real, $base)) {
            self::rrmdir($real);
        }
    }

    /**
     * Resolve o caminho absoluto de um asset do widget a partir do path pedido,
     * confinado à pasta do widget (defesa contra path traversal). Retorna o
     * caminho real do arquivo ou null se inválido/inexistente.
     */
    public static function resolveAsset(string $storagePathRelative, string $requested): ?string
    {
        $requested = $requested === '' ? self::ENTRY : $requested;
        $requested = str_replace('\\', '/', $requested);
        if (str_contains($requested, "\0") || in_array('..', explode('/', $requested), true)) {
            return null;
        }
        $baseDir = realpath(LMS_ROOT . '/' . ltrim($storagePathRelative, '/'));
        if ($baseDir === false) {
            return null;
        }
        $full = realpath($baseDir . '/' . ltrim($requested, '/'));
        if ($full === false || !is_file($full)) {
            return null;
        }
        // Confina ao diretório do widget.
        if (!str_starts_with($full, $baseDir . DIRECTORY_SEPARATOR) && $full !== $baseDir) {
            return null;
        }
        return $full;
    }

    /** Content-type por extensão (null se não permitido). */
    public static function contentTypeFor(string $path): ?string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return self::ALLOWED_EXT[$ext] ?? null;
    }

    /** rm -rf seguro (já confinado pelo caller). */
    private static function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path) && !is_link($path)) {
                self::rrmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
