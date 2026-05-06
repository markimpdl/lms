<?php
declare(strict_types=1);

/**
 * Cron de fechamento de sessoes orfas (TIME-03, issue #438).
 *
 * Fecha sessoes em student_sessions cujo last_ping_at e mais antigo que
 * StudentSession::CRON_CLOSE_AFTER_MINUTES (3 min). Trata o ultimo ping
 * como o momento real em que o aluno saiu (presume inatividade do JS por
 * tab fechada / sleep / sem rede). Idempotente — rodar a cada minuto e
 * seguro mesmo sem fila pendente.
 *
 * **Setup no cPanel da Hostinger** (instrucao manual):
 *   - Cron: `* * * * * php /home/USER/.../scripts/cron/close-stale-sessions.php`
 *   - Stdout/Stderr direcionado pra log ou null.
 *
 * Saida: numero de sessoes fechadas + tempo de execucao. Exit 0 em sucesso.
 */

require dirname(__DIR__, 2) . '/src/bootstrap.php';

$start = microtime(true);
try {
    $closed = StudentSession::closeStaleSessions();
} catch (\Throwable $e) {
    fwrite(STDERR, "close-stale-sessions: " . $e->getMessage() . "\n");
    exit(1);
}
$elapsed = (int) round((microtime(true) - $start) * 1000);

echo sprintf(
    "close-stale-sessions: %d session(s) closed in %dms (threshold=%d min)\n",
    $closed,
    $elapsed,
    StudentSession::CRON_CLOSE_AFTER_MINUTES
);
exit(0);
