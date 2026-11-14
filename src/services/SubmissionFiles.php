<?php
declare(strict_types=1);

/**
 * Arquivos de uma entrega (atividade ou tentativa de avaliação) — multi-arquivo.
 *
 * Uma entrega tem N arquivos em `activity_submission_files` /
 * `evaluation_submission_files` (filhas com CASCADE). A SOMA dos tamanhos
 * respeita `MAX_TOTAL_BYTES` — o mesmo teto que antes valia pro arquivo único.
 *
 * Legado: entregas anteriores ao multi-arquivo guardam um único arquivo em
 * `filename`/`stored_path` da própria linha da entrega. `listFor()` devolve
 * esse arquivo como o item de `id = 0`, então as telas e os downloads tratam
 * os dois formatos igual (download sem `?f=` = arquivo legado).
 */
final class SubmissionFiles
{
    public const MAX_TOTAL_BYTES = 10 * 1024 * 1024; // soma de todos os arquivos
    public const MAX_FILES       = 10;

    public const KIND_ACTIVITY   = 'activity';
    public const KIND_EVALUATION = 'evaluation';

    /**
     * Tabela filha por tipo — mapa fechado, nunca vem de input.
     */
    private static function table(string $kind): string
    {
        return match ($kind) {
            self::KIND_ACTIVITY   => 'activity_submission_files',
            self::KIND_EVALUATION => 'evaluation_submission_files',
        };
    }

    /**
     * Converte `$_FILES['files']` (formato "colunar" do input multiple) numa
     * lista de arquivos no formato de entrada única. Descarta os slots vazios
     * (UPLOAD_ERR_NO_FILE) — input sem seleção vira lista vazia.
     *
     * @return list<array{name:string,tmp_name:string,size:int,error:int}>
     */
    public static function normalize(mixed $raw): array
    {
        if (!is_array($raw) || !isset($raw['name'])) {
            return [];
        }
        // Input sem [] no name: um arquivo só, mesmo formato de antes.
        if (!is_array($raw['name'])) {
            $raw = [
                'name'     => [$raw['name']],
                'tmp_name' => [$raw['tmp_name'] ?? ''],
                'size'     => [$raw['size'] ?? 0],
                'error'    => [$raw['error'] ?? UPLOAD_ERR_NO_FILE],
            ];
        }
        $out = [];
        foreach (array_keys($raw['name']) as $i) {
            $error = (int) ($raw['error'][$i] ?? UPLOAD_ERR_NO_FILE);
            if ($error === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $out[] = [
                'name'     => (string) ($raw['name'][$i] ?? ''),
                'tmp_name' => (string) ($raw['tmp_name'][$i] ?? ''),
                'size'     => (int) ($raw['size'][$i] ?? 0),
                'error'    => $error,
            ];
        }
        return $out;
    }

    /**
     * Confere quantidade e soma (arquivos mantidos + novos) ANTES de gravar
     * qualquer coisa no disco. Devolve a chave i18n do erro ou null se OK.
     *
     * @param list<array{size_bytes:int}> $kept
     * @param list<array{size:int}>       $incoming
     */
    public static function checkLimits(array $kept, array $incoming, string $errPrefix): ?string
    {
        if (count($kept) + count($incoming) > self::MAX_FILES) {
            return $errPrefix . '.too_many';
        }
        $total = 0;
        foreach ($kept as $f) {
            $total += (int) $f['size_bytes'];
        }
        foreach ($incoming as $f) {
            $total += (int) $f['size'];
        }
        return $total > self::MAX_TOTAL_BYTES ? $errPrefix . '.total_size' : null;
    }

    /**
     * Grava todos os arquivos via `$storeOne` (closure que chama o Storage do
     * contexto). Tudo ou nada: se um falha, apaga do disco os já gravados
     * nesta chamada e devolve o erro daquele arquivo.
     *
     * @param list<array<string,mixed>> $files
     * @param callable(array<string,mixed>):array<string,mixed> $storeOne
     * @return array{status:string, stored?:list<array{filename:string,stored_path:string,size_bytes:int}>, error_key?:string, failed_name?:string}
     */
    public static function storeAll(array $files, callable $storeOne): array
    {
        $stored = [];
        foreach ($files as $file) {
            $r = $storeOne($file);
            if (($r['status'] ?? '') !== 'ok') {
                foreach ($stored as $s) {
                    safe_unlink_storage($s['stored_path']);
                }
                return [
                    'status'      => 'error',
                    'error_key'   => (string) ($r['error_key'] ?? ''),
                    'failed_name' => (string) $file['name'],
                ];
            }
            $stored[] = [
                'filename'    => (string) $r['filename'],
                'stored_path' => (string) $r['stored_path'],
                'size_bytes'  => (int) $r['size_bytes'],
            ];
        }
        return ['status' => 'ok', 'stored' => $stored];
    }

    /**
     * Arquivos de uma entrega, em ordem de envio. Inclui o arquivo legado
     * (`filename`/`stored_path` da linha-mãe) como `id = 0`.
     *
     * @param array<string,mixed> $submission precisa de id, filename, stored_path
     * @return list<array{id:int, filename:string, stored_path:string, size_bytes:int}>
     */
    public static function listFor(string $kind, array $submission): array
    {
        $out = [];
        $legacyPath = (string) ($submission['stored_path'] ?? '');
        if ($legacyPath !== '' && ($submission['filename'] ?? null) !== null) {
            $abs  = LMS_ROOT . '/' . ltrim($legacyPath, '/');
            $size = is_file($abs) ? (int) filesize($abs) : 0;
            $out[] = [
                'id'          => 0,
                'filename'    => (string) $submission['filename'],
                'stored_path' => $legacyPath,
                'size_bytes'  => $size,
            ];
        }
        $stmt = Database::pdo()->prepare(
            'SELECT id, filename, stored_path, size_bytes
               FROM ' . self::table($kind) . '
              WHERE submission_id = ?
              ORDER BY id ASC'
        );
        $stmt->execute([(int) $submission['id']]);
        foreach ($stmt->fetchAll() as $row) {
            $out[] = [
                'id'          => (int) $row['id'],
                'filename'    => (string) $row['filename'],
                'stored_path' => (string) $row['stored_path'],
                'size_bytes'  => (int) $row['size_bytes'],
            ];
        }
        return $out;
    }

    /**
     * Um arquivo da entrega pelo id (0 = legado). null se não pertence a ela.
     *
     * @param array<string,mixed> $submission
     * @return array{id:int, filename:string, stored_path:string, size_bytes:int}|null
     */
    public static function findIn(string $kind, array $submission, int $fileId): ?array
    {
        if ($fileId <= 0) {
            $legacyPath = (string) ($submission['stored_path'] ?? '');
            if ($legacyPath === '' || ($submission['filename'] ?? null) === null) {
                return null;
            }
            return [
                'id'          => 0,
                'filename'    => (string) $submission['filename'],
                'stored_path' => $legacyPath,
                'size_bytes'  => 0, // download não usa; evita o stat
            ];
        }
        $stmt = Database::pdo()->prepare(
            'SELECT id, filename, stored_path, size_bytes
               FROM ' . self::table($kind) . '
              WHERE submission_id = ? AND id = ?
              LIMIT 1'
        );
        $stmt->execute([(int) $submission['id'], $fileId]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }
        return [
            'id'          => (int) $row['id'],
            'filename'    => (string) $row['filename'],
            'stored_path' => (string) $row['stored_path'],
            'size_bytes'  => (int) $row['size_bytes'],
        ];
    }

    /**
     * Insere as linhas dos arquivos já gravados no disco.
     *
     * @param list<array{filename:string,stored_path:string,size_bytes:int}> $stored
     */
    public static function insert(PDO $pdo, string $kind, int $submissionId, array $stored): void
    {
        if ($stored === []) {
            return;
        }
        $stmt = $pdo->prepare(
            'INSERT INTO ' . self::table($kind) . '
                (submission_id, filename, stored_path, size_bytes)
             VALUES (?, ?, ?, ?)'
        );
        foreach ($stored as $s) {
            $stmt->execute([$submissionId, $s['filename'], $s['stored_path'], $s['size_bytes']]);
        }
    }

    /**
     * Remove as linhas dos ids informados (só os da própria entrega).
     *
     * @param list<int> $ids
     */
    public static function deleteRows(PDO $pdo, string $kind, int $submissionId, array $ids): void
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn (int $i): bool => $i > 0));
        if ($ids === []) {
            return;
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $pdo->prepare(
            'DELETE FROM ' . self::table($kind) . '
              WHERE submission_id = ? AND id IN (' . $in . ')'
        )->execute(array_merge([$submissionId], $ids));
    }

    /**
     * Mime do download a partir da extensão gravada (o Storage já validou o
     * conteúdo real com finfo no upload).
     */
    public static function mimeFor(string $storedPath): string
    {
        $ext = strtolower(pathinfo($storedPath, PATHINFO_EXTENSION));
        return match ($ext) {
            'pdf'   => 'application/pdf',
            'zip'   => 'application/zip',
            'txt', 'py', 'cs', 'js' => 'text/plain',
            'html'  => 'text/plain', // nunca servir HTML do aluno como text/html
            'jpg'   => 'image/jpeg',
            'png'   => 'image/png',
            default => OfficeFile::mimeForExt($ext) ?? 'application/octet-stream',
        };
    }

    /**
     * Separador decimal do idioma da interface ("2,4 MB" pt / "2.4 MB" en).
     */
    public static function decimalSep(): string
    {
        return current_lang() === 'pt' ? ',' : '.';
    }

    /**
     * "2,4 MB" / "830 KB" — rótulo curto pras listas.
     */
    public static function humanSize(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            $mb = $bytes / (1024 * 1024);
            return ($bytes % (1024 * 1024) === 0
                ? (string) (int) $mb
                : number_format($mb, 1, self::decimalSep(), '')) . ' MB';
        }
        return max(1, (int) round($bytes / 1024)) . ' KB';
    }
}
