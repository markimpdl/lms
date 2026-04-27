<?php
declare(strict_types=1);

/**
 * Cleanup retroativo de tentativas antigas de avaliação (v0.29.0).
 *
 * A partir de 2026-04-27 o LMS não guarda mais histórico de tentativas
 * reprovadas — o `submit()` deleta a anterior antes de inserir a nova.
 * Este script faz a limpeza retroativa de tudo que ficou pendurado:
 *   - DELETE de `evaluation_submissions` WHERE is_current = 0 (cascade
 *     limpa `evaluation_submission_lo_grades` automaticamente)
 *   - unlink dos arquivos físicos correspondentes (stored_path +
 *     report_pdf_path) em storage/uploads/
 *
 * **USO (1 vez em prod):**
 *   1. Subir esse arquivo via FileZilla pra raiz do projeto (em install/)
 *   2. Acessar https://lms.rumo.info/install/cleanup_evaluation_attempts.php
 *      logado como super-admin
 *   3. Confirmar com o botão (mostra contagem antes)
 *   4. **APAGAR esse arquivo do servidor após rodar** (via FileZilla)
 */

require dirname(__DIR__) . '/src/bootstrap.php';

// Aceita qualquer usuário autenticado (script temporário, será apagado
// após uso). Operação determinística: só apaga is_current=0 que já são
// tentativas órfãs após o feat de "sem histórico" (PR #404).
require_auth();
$_currentUser = current_user();
$_currentRole = (string) ($_currentUser['role'] ?? '?');

$pdo = Database::pdo();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    // Coleta paths antes do DELETE.
    $stmt = $pdo->query(
        'SELECT stored_path FROM evaluation_submissions
          WHERE is_current = 0 AND stored_path IS NOT NULL
          UNION ALL
         SELECT report_pdf_path FROM evaluation_submissions
          WHERE is_current = 0 AND report_pdf_path IS NOT NULL'
    );
    /** @var list<string> $paths */
    $paths = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $deleted = $pdo->exec('DELETE FROM evaluation_submissions WHERE is_current = 0');

    $unlinked = 0;
    foreach ($paths as $p) {
        $p = trim((string) $p);
        if ($p === '') continue;
        $base = @realpath(LMS_ROOT . '/storage/uploads');
        $abs  = LMS_ROOT . '/' . ltrim($p, '/');
        $real = @realpath($abs);
        if ($base !== false && $real !== false && str_starts_with($real, $base)) {
            if (@unlink($real)) {
                $unlinked++;
            }
        }
    }

    $totalPaths = count($paths);
    $msg = "OK: deleted {$deleted} row(s), unlinked {$unlinked}/{$totalPaths} file(s).";
    header('Content-Type: text/plain; charset=utf-8');
    echo $msg . "\n\nLEMBRE-SE: apague esse arquivo do servidor agora.\n";
    exit;
}

// GET: mostra contagem + botão de confirmação.
$rowCount = (int) $pdo->query(
    'SELECT COUNT(*) FROM evaluation_submissions WHERE is_current = 0'
)->fetchColumn();

$fileCount = (int) $pdo->query(
    'SELECT COUNT(*) FROM evaluation_submissions
      WHERE is_current = 0 AND (stored_path IS NOT NULL OR report_pdf_path IS NOT NULL)'
)->fetchColumn();

?><!DOCTYPE html>
<html lang="pt"><head><meta charset="utf-8"><title>Cleanup tentativas antigas</title>
<style>body{font-family:sans-serif;max-width:640px;margin:2rem auto;padding:0 1rem}</style>
</head><body>
<h1>Cleanup retroativo — tentativas antigas de avaliação</h1>
<p style="color:#666;font-size:.85rem">Logado como: <strong><?= e((string) ($_currentUser['name'] ?? '?')) ?></strong> (role: <code><?= e($_currentRole) ?></code>)</p>
<p>Este script vai apagar permanentemente:</p>
<ul>
    <li><strong><?= $rowCount ?></strong> linha(s) em <code>evaluation_submissions</code> com <code>is_current = 0</code></li>
    <li><strong>~<?= $fileCount ?></strong> arquivo(s) físico(s) correspondente(s) em <code>storage/uploads/</code></li>
</ul>
<p>FK CASCADE limpa <code>evaluation_submission_lo_grades</code> automaticamente.</p>
<form method="POST">
    <?= csrf_field() ?>
    <button type="submit" style="padding: .5rem 1rem; background: #c00; color: #fff; border: 0; cursor: pointer;">
        Apagar agora
    </button>
</form>
<hr>
<p><strong>Após rodar, apague este arquivo do servidor (FileZilla).</strong></p>
</body></html>
