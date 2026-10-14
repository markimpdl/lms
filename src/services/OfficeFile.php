<?php
declare(strict_types=1);

/**
 * Validação de arquivos Office (Word, PowerPoint, Excel) — moderno (OOXML) e
 * legado (OLE). Usada pelo anexo de conteúdo da CU e pelas entregas do aluno
 * (atividade e avaliação), sempre via `resolveMime()`.
 *
 * O `finfo` varia muito com o libmagic para esses formatos — docx sai como
 * `application/zip`, `.doc` como `application/CDFV2` ou
 * `application/x-ole-storage` —, então o mime final sai da extensão, MAS só
 * depois de o conteúdo confirmar o tipo:
 *   - OOXML: zip cujo `_rels/.rels` aponta o documento principal dentro da
 *     pasta do tipo (`word/`, `ppt/`, `xl/`);
 *   - legado: contêiner OLE (D0 CF 11 E0) com o stream principal do tipo
 *     (`WordDocument`, `PowerPoint Document`, `Workbook`/`Book`).
 *
 * Macro: recusa OOXML com `vbaProject.bin` e OLE com o stream
 * `_VBA_PROJECT`. É MELHOR ESFORÇO no legado — o VBA do `.ppt` fica
 * comprimido dentro do stream do documento e macro XLM (Excel 4.0) do `.xls`
 * não tem stream próprio; nenhum dos dois é visível sem parsear o formato.
 * Arquivo protegido por senha (OOXML criptografado vira OLE com
 * `EncryptedPackage`) é recusado: não dá pra conferir o conteúdo.
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

    /**
     * Extensão → [mime canônico, contêiner, prova do tipo]. No OOXML a prova
     * é a pasta do documento principal; no OLE, os nomes de stream aceitos.
     */
    private const BY_EXT = [
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'zip', 'word/'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'zip', 'ppt/'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'zip', 'xl/'],
        'doc'  => ['application/msword', 'ole', ['WordDocument']],
        'ppt'  => ['application/vnd.ms-powerpoint', 'ole', ['PowerPoint Document']],
        'xls'  => ['application/vnd.ms-excel', 'ole', ['Workbook', 'Book']],
    ];

    /**
     * Mimes que o `finfo` devolve para contêineres Office, conforme a versão.
     * Arquivo que se diz Office e cai aqui mas falha na checagem é recusado;
     * fora daqui (ex.: `notas.xls` que é CSV → text/plain) segue o mime do
     * finfo e a allowlist do caller decide.
     */
    private const CONTAINER_MIMES = [
        'application/zip',
        'application/octet-stream',
        'application/vnd.ms-office',
        'application/CDFV2',
        'application/CDFV2-encrypted',
        'application/encrypted',
        'application/x-ole-storage',
        'application/msword',
        'application/vnd.ms-excel',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    /**
     * Mime final do upload, pra o caller conferir na sua allowlist. Ponto
     * ÚNICO de entrada dos três storages:
     *   - não se diz Office (nem pela extensão, nem pelo finfo) → finfo intacto;
     *   - Office válido → mime canônico da extensão;
     *   - se diz Office, é contêiner Office mas falha na checagem (zip
     *     renomeado, macro, senha, extensão trocada) → '' (recusado);
     *   - se diz Office pela extensão mas o conteúdo é outra coisa
     *     (CSV/PDF com nome .xls/.doc) → finfo intacto.
     */
    public static function resolveMime(string $path, string $originalName, string $finfoMime): string
    {
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!isset(self::BY_EXT[$ext]) && !isset(self::ALLOWED[$finfoMime])) {
            return $finfoMime;
        }
        if (!in_array($finfoMime, self::CONTAINER_MIMES, true)) {
            return $finfoMime;
        }
        return self::detect($path, $ext) ?? '';
    }

    /** Content-Type pra servir um arquivo Office gravado com essa extensão. */
    public static function mimeForExt(string $ext): ?string
    {
        return self::BY_EXT[strtolower($ext)][0] ?? null;
    }

    private static function detect(string $path, string $ext): ?string
    {
        if (!isset(self::BY_EXT[$ext])) {
            return null;
        }
        [$canonical, $container, $proof] = self::BY_EXT[$ext];
        return ($container === 'ole' ? self::oleMatches($path, $proof) : self::ooxmlMatches($path, $proof))
            ? $canonical
            : null;
    }

    /**
     * OLE com o stream principal do tipo e sem projeto VBA. Os nomes de
     * stream ficam no diretório do contêiner em UTF-16LE; busca no arquivo
     * inteiro (o caller já limitou o tamanho do upload).
     *
     * @param list<string> $streams
     */
    private static function oleMatches(string $path, array $streams): bool
    {
        $bytes = @file_get_contents($path);
        if ($bytes === false || !str_starts_with($bytes, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1")) {
            return false;
        }
        $u16 = static fn (string $s): string => mb_convert_encoding($s, 'UTF-16LE', 'ASCII');
        if (str_contains($bytes, $u16('_VBA_PROJECT')) || str_contains($bytes, $u16('EncryptedPackage'))) {
            return false;
        }
        foreach ($streams as $name) {
            // Entrada do diretório: nome + terminador nulo, pra `Book` não
            // casar dentro de `Workbook` de outro contexto.
            if (str_contains($bytes, $u16($name) . "\0\0")) {
                return true;
            }
        }
        return false;
    }

    /**
     * Zip cujo documento principal (alvo da relação `officeDocument` em
     * `_rels/.rels`) existe dentro da pasta do tipo, sem `vbaProject.bin`.
     * O nome do principal varia (Word Online grava `word/document2.xml`),
     * por isso vem do .rels e não de uma lista fixa.
     */
    private static function ooxmlMatches(string $path, string $dir): bool
    {
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            return false;
        }
        $head = (string) fread($fh, 4);
        fclose($fh);
        // Sem a extensão zip no PHP não dá pra olhar dentro: recusa em vez de
        // aceitar qualquer zip renomeado.
        if ($head !== "PK\x03\x04" || !class_exists('ZipArchive')) {
            return false;
        }

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            return false;
        }
        $ok = false;
        $rels = $zip->getFromName('_rels/.rels');
        if ($rels !== false
            && $zip->locateName('vbaProject.bin', ZipArchive::FL_NODIR | ZipArchive::FL_NOCASE) === false
            && preg_match('~<Relationship\b[^>]*\bType="[^"]*/officeDocument"[^>]*>~i', $rels, $m)
            && preg_match('~\bTarget="/?([^"]+)"~i', $m[0], $t)
        ) {
            $target = $t[1];
            $ok = str_starts_with(strtolower($target), $dir)
                && $zip->locateName($target, ZipArchive::FL_NOCASE) !== false;
        }
        $zip->close();
        return $ok;
    }
}
