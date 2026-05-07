<?php
declare(strict_types=1);

/**
 * Fix retroativo de timezone — student_sessions (one-shot, v0.30.x).
 *
 * Antes do fix em src/lib/Database.php, o LMS conectava ao MySQL sem
 * setar `time_zone`, entao NOW()/CURRENT_TIMESTAMP gravava em UTC
 * enquanto o PHP rodava em Asia/Dubai (+04:00). Resultado: todos os
 * registros em student_sessions ficaram 4h atrasados (started_at,
 * last_ping_at, ended_at).
 *
 * Este script soma +4h nos registros existentes. Usa cutoff = NOW() - 5min
 * pra preservar registros novos que ja foram gravados em hora de Dubai
 * (pos-deploy). Por isso: ROBE LOGO APOS O DEPLOY do Database.php fixado
 * (idealmente em < 5 min, ou em horario de baixo trafego).
 *
 * USO:
 *   1. Deploy normal (Database.php fixado + este arquivo) ja feito
 *   2. Acessar https://lms.rumo.info/_fix_student_sessions_timezone.php
 *      logado como super-admin ou teacher
 *   3. Conferir o preview, clicar "Aplicar +4h"
 *   4. APAGAR este arquivo do servidor (cPanel File Manager)
 */

require dirname(__DIR__) . '/src/bootstrap.php';

require_auth();
require_role('teacher', 'super_admin');
$_user = current_user();
$_role = (string) ($_user['role'] ?? '?');
$_tid = current_tenant_id();

$pdo = Database::pdo();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    // Cutoff calculado server-side no momento do POST: registros com
    // started_at <= (NOW() - 5min) sao considerados pre-fix e levam +4h.
    // Os ultimos 5 min sao preservados (assumidos pos-deploy, ja em Dubai).
    // Filtro por tenant_id: teachers so veem suas proprias sessoes.
    $upd = $pdo->prepare(
        'UPDATE student_sessions
            SET started_at   = DATE_ADD(started_at,   INTERVAL 4 HOUR),
                last_ping_at = DATE_ADD(last_ping_at, INTERVAL 4 HOUR),
                ended_at     = CASE WHEN ended_at IS NULL THEN NULL
                                    ELSE DATE_ADD(ended_at, INTERVAL 4 HOUR) END
          WHERE tenant_id = :tid
            AND started_at <= (NOW() - INTERVAL 5 MINUTE)'
    );
    $upd->execute([':tid' => $_tid]);
    $affected = $upd->rowCount();

    header('Content-Type: text/plain; charset=utf-8');
    echo "OK: {$affected} sessao(oes) ajustada(s) em +4h.\n\n";
    echo "LEMBRE-SE: apague este arquivo do servidor agora (cPanel File Manager).\n";
    exit;
}

// GET: preview.
$nowMysql = (string) $pdo->query('SELECT NOW()')->fetchColumn();
$nowPhp   = date('Y-m-d H:i:s');

$totalStmt = $pdo->prepare('SELECT COUNT(*) FROM student_sessions WHERE tenant_id = :tid');
$totalStmt->execute([':tid' => $_tid]);
$totalRows = (int) $totalStmt->fetchColumn();

$affectedStmt = $pdo->prepare(
    'SELECT COUNT(*) FROM student_sessions
      WHERE tenant_id = :tid
        AND started_at <= (NOW() - INTERVAL 5 MINUTE)'
);
$affectedStmt->execute([':tid' => $_tid]);
$affectedRows = (int) $affectedStmt->fetchColumn();

$previewStmt = $pdo->prepare(
    'SELECT id, user_id, started_at, last_ping_at, ended_at
       FROM student_sessions
      WHERE tenant_id = :tid
        AND started_at <= (NOW() - INTERVAL 5 MINUTE)
      ORDER BY started_at DESC
      LIMIT 5'
);
$previewStmt->execute([':tid' => $_tid]);
$preview = $previewStmt->fetchAll();

?><!DOCTYPE html>
<html lang="pt"><head><meta charset="utf-8"><title>Fix timezone — student_sessions</title>
<style>
body{font-family:sans-serif;max-width:760px;margin:2rem auto;padding:0 1rem;color:#222}
table{border-collapse:collapse;width:100%;font-size:.85rem;margin:1rem 0}
th,td{border:1px solid #ccc;padding:.4rem .6rem;text-align:left}
th{background:#f3f3f3}
code{background:#f3f3f3;padding:.1rem .3rem;border-radius:3px}
.warn{background:#fff3cd;border:1px solid #ffe69c;padding:.6rem .8rem;border-radius:4px;margin:1rem 0}
</style>
</head><body>
<h1>Fix retroativo de timezone — <code>student_sessions</code></h1>

<p style="color:#666;font-size:.85rem">
    Logado como: <strong><?= e((string) ($_user['name'] ?? '?')) ?></strong>
    (role: <code><?= e($_role) ?></code>)
</p>

<h2>Diagnostico</h2>
<ul>
    <li>Hora atual MySQL (<code>NOW()</code>): <code><?= e($nowMysql) ?></code></li>
    <li>Hora atual PHP (Asia/Dubai): <code><?= e($nowPhp) ?></code></li>
</ul>
<p>Se as duas horas acima estao alinhadas, o fix de <code>Database.php</code> ja esta em vigor — pode aplicar.<br>
Se o MySQL ainda mostrar UTC (4h atras do PHP), o deploy do <code>Database.php</code> ainda nao chegou — recarregue depois.</p>

<h2>Escopo do UPDATE</h2>
<p>Sera somado <strong>+4 horas</strong> em <code>started_at</code>, <code>last_ping_at</code> e <code>ended_at</code> dos registros com <code>started_at &lt;= NOW() - 5 minutos</code>. Os ultimos 5 minutos sao preservados (assumidos pos-deploy).</p>
<ul>
    <li>Total de sessoes na tabela: <strong><?= $totalRows ?></strong></li>
    <li>Sessoes que serao ajustadas: <strong><?= $affectedRows ?></strong></li>
    <li>Sessoes preservadas (ultimos 5 min): <strong><?= $totalRows - $affectedRows ?></strong></li>
</ul>

<h2>Preview (5 mais recentes que serao ajustadas)</h2>
<?php if ($preview === []): ?>
    <p><em>Nenhum registro elegivel.</em></p>
<?php else: ?>
    <table>
        <thead><tr>
            <th>id</th><th>user</th>
            <th>started_at (atual)</th><th>started_at (depois)</th>
            <th>ended_at (atual)</th>
        </tr></thead>
        <tbody>
        <?php foreach ($preview as $r): ?>
            <tr>
                <td><?= (int) $r['id'] ?></td>
                <td><?= (int) $r['user_id'] ?></td>
                <td><code><?= e((string) $r['started_at']) ?></code></td>
                <td><code><?= e(date('Y-m-d H:i:s', strtotime((string) $r['started_at']) + 4 * 3600)) ?></code></td>
                <td><code><?= e((string) ($r['ended_at'] ?? 'NULL')) ?></code></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<div class="warn">
    <strong>Idempotencia:</strong> rodar este script duas vezes soma <strong>8h</strong>. So clique uma vez,
    e <strong>apague o arquivo do servidor</strong> apos confirmar o resultado.
</div>

<form method="POST">
    <?= csrf_field() ?>
    <button type="submit"
            <?= $affectedRows === 0 ? 'disabled' : '' ?>
            style="padding:.6rem 1.2rem;background:#c00;color:#fff;border:0;cursor:pointer;font-size:1rem">
        Aplicar +4h em <?= $affectedRows ?> sessao(oes)
    </button>
</form>

<hr>
<p><strong>Apos rodar, apague este arquivo do servidor (cPanel File Manager).</strong></p>
</body></html>
