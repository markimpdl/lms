<?php
declare(strict_types=1);

/**
 * Validação de arquivos Office (Word, PowerPoint, Excel) — moderno (OOXML) e
 * legado (OLE). Usada pelo anexo de conteúdo da CU e pelas entregas do aluno
 * (atividade e avaliação).
 *
 * O `finfo` varia muito com o libmagic para esses formatos — docx sai como
 * `application/zip`, `.doc` como `application/CDFV2` ou
 * `application/x-ole-storage` —, então o mime final sai da extensão, MAS só
 * depois de o conteúdo confirmar o contêiner: OOXML é zip com a parte
 * principal do tipo (`word/document.xml` etc.); o legado começa com a
 * assinatura OLE (D0 CF 11 E0). Extensão sozinha não basta.
 *
 * Arquivo com macro (VBA) é recusado nos dois formatos: o arquivo vai pro
 * download de outra pessoa (aluno baixa anexo, professor baixa entrega).
 */
final class OfficeFile
{
    /** Mime canônico → extensão gravada em disco. */
    public const ALLOWED = [
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document'   => 'docx',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'         => 'xlsx',
        'application/msword'            => 'doc',
        'application/vnd.ms-powerpoint' => 'ppt',
        'application/vnd.ms-excel'      => 'xls',
    ];

    /** Extensão → [mime canônico, contêiner, parte interna obrigatória (OOXML)]. */
    private const BY_EXT = [
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'zip', 'word/document.xml'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'zip', 'ppt/presentation.xml'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'zip', 'xl/workbook.xml'],
        'doc'  => ['application/msword', 'ole', null],
        'ppt'  => ['application/vnd.ms-powerpoint', 'ole', null],
        'xls'  => ['application/vnd.ms-excel', 'ole', null],
    ];

    /** Mimes que o `finfo` devolve para arquivos Office, conforme a versão. */
    private const FINFO_MIMES = [
        'application/zip',
        'application/octet-stream',
        'application/vnd.ms-office',
        'application/CDFV2',
        'application/x-ole-storage',
        'application/msword',
        'application/vnd.ms-excel',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    /**
     * O upload se apresenta como Office — pela extensão do nome OU pelo mime
     * do finfo? Se sim, o caller TEM de passar por `detect()` e recusar
     * quando ela devolver null: senão um `.docx` que é zip qualquer cairia
     * como zip genérico, e um mime Office sem extensão Office pularia a
     * checagem de conteúdo.
     */
    public static function claims(string $originalName, string $finfoMime): bool
    {
        return isset(self::BY_EXT[self::ext($originalName)]) || isset(self::ALLOWED[$finfoMime]);
    }

    /**
     * Mime canônico do arquivo Office válido, ou null (não é Office, conteúdo
     * não bate com a extensão, tem macro, ou o PHP não tem `ZipArchive` pra
     * olhar dentro do OOXML).
     */
    public static function detect(string $path, string $originalName, string $finfoMime): ?string
    {
        $ext = self::ext($originalName);
        if (!isset(self::BY_EXT[$ext]) || !in_array($finfoMime, self::FINFO_MIMES, true)) {
            return null;
        }
        [$canonical, $container, $part] = self::BY_EXT[$ext];

        $bytes = @file_get_contents($path); // callers limitam o tamanho antes
        if ($bytes === false) {
            return null;
        }

        if ($container === 'ole') {
            if (!str_starts_with($bytes, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1")) {
                return null;
            }
            // Nome do stream de projeto VBA no diretório OLE (UTF-16LE).
            $vba = mb_convert_encoding('_VBA_PROJECT', 'UTF-16LE', 'ASCII');
            return str_contains($bytes, $vba) ? null : $canonical;
        }

        // Sem a extensão zip no PHP não dá pra olhar dentro: recusa em vez de
        // aceitar qualquer zip renomeado.
        if (!str_starts_with($bytes, "PK\x03\x04") || !class_exists('ZipArchive')) {
            return null;
        }
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            return null;
        }
        $ok = $zip->locateName((string) $part) !== false
            && $zip->locateName('vbaProject.bin', ZipArchive::FL_NODIR) === false;
        $zip->close();
        return $ok ? $canonical : null;
    }

    /** Content-Type pra servir um arquivo Office gravado com essa extensão. */
    public static function mimeForExt(string $ext): ?string
    {
        return self::BY_EXT[strtolower($ext)][0] ?? null;
    }

    private static function ext(string $name): string
    {
        return strtolower(pathinfo($name, PATHINFO_EXTENSION));
    }
}
