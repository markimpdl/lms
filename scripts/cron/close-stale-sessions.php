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
 *
 *     * * * * * /usr/local/bin/php /home/USER/.../scripts/cron/close-stale-sessions.php >/dev/null
 *
 * **Nao redirecione a saida pra um arquivo.** Era assim que estava, apontando
 * pra `storage/cron-sessions.log`, e `storage/` nao existia no servidor: o
 * `>>` eh do SHELL, roda antes do PHP, e shell nao cria diretorio faltante —
 * o comando abortava com "No such file or directory" e este script nunca
 * executou. Sessoes ficaram abertas indefinidamente. O log agora eh escrito
 * pelo proprio PHP em `storage/logs/`, que ele cria se precisar (_cron_log).
 *
 * E `>/dev/null` sem `2>&1`: STDERR eh o unico canal que o cPanel manda por
 * email, e job quebrado tem de ser ruidoso.
 *
 * Rodar de minuto em minuto eh o ideal, mas nao eh mais requisito de
 * corretude: `StudentSession::sqlEffectiveSeconds()` ja trata sessao orfa como
 * encerrada no ultimo ping, entao um atraso do cron nao infla mais o tempo
 * online de ninguem. O cron so materializa `ended_at`/`duration_seconds`.
 *
 * Saida: numero de sessoes fechadas + tempo de execucao. Exit 0 em sucesso.
 */

require dirname(__DIR__, 2) . '/src/bootstrap.php';
require __DIR__ . '/_cron_log.php';

exit(cron_run('sessions', static function (): array {
    $closed = StudentSession::closeStaleSessions();
    return [
        sprintf(
            'close-stale-sessions: %d session(s) closed (threshold=%d min)',
            $closed,
            StudentSession::CRON_CLOSE_AFTER_MINUTES
        ),
        // Rodando de minuto em minuto, a esmagadora maioria fecha zero — so
        // vira linha de log a rodada que de fato fechou alguma sessao.
        $closed > 0,
    ];
}));
