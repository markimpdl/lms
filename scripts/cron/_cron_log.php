<?php
declare(strict_types=1);

/**
 * Log de cron que nao depende do shell (issue do cron de sessoes, 2026-09).
 *
 * **O problema que isto resolve.** A linha registrada no cPanel era
 *
 *     * * * * * php .../close-stale-sessions.php >> .../storage/cron-sessions.log 2>&1
 *
 * e `storage/` nao existia no servidor. O `>>` eh feito pelo SHELL, antes de o
 * PHP ser carregado, e o shell NAO cria diretorio faltante: ele aborta com
 * "No such file or directory" e o comando inteiro nunca roda. O script nunca
 * executou uma vez sequer — nao teve como criar a pasta, nem como avisar que
 * estava morto. Sessoes ficaram abertas pra sempre e o tempo online de cada
 * aluno cresceu sozinho, 24h por dia.
 *
 * Por isso o log passa a ser responsabilidade do PHP, que cria o diretorio se
 * precisar. A linha do cPanel nao deve mais depender de caminho nenhum:
 *
 *     * * * * * php .../close-stale-sessions.php >/dev/null
 *
 * `>/dev/null` SEM o `2>&1`: STDOUT eh ruido de rodada bem-sucedida, mas
 * STDERR eh o unico canal que o cPanel converte em email. Somar `2>&1`
 * silenciaria justamente o aviso de job quebrado — e falha ANTES deste
 * arquivo carregar (binario de PHP errado, fatal no bootstrap) nao gera nem
 * linha de log nem email, que eh a morte indiagnosticavel descrita acima.
 *
 * Falha de escrita do log NUNCA derruba o job: perder a linha de log eh
 * irrelevante perto de perder a rodada.
 */

/**
 * Anexa uma linha ao log do cron, com timestamp. Silencioso em qualquer erro.
 */
function cron_log(string $job, string $message): void
{
    try {
        // Validar $job contra caracteres que poderiam causar path traversal.
        if (!preg_match('/^[a-z0-9_-]+$/i', $job)) {
            return;
        }

        $dir = LMS_ROOT . '/storage/logs';
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
                return;
            }
            // O repo versiona storage/logs/.htaccess, mas o deploy lista
            // `storage/logs` em PRESERVE_ON_SERVER e nunca o sobe — entao o
            // diretorio que acabamos de criar nasceria SEM a regra de deny,
            // deixando o log de cron legivel pela web. Recria a regra aqui.
            @file_put_contents($dir . '/.htaccess', "Require all denied\n");
        }

        $file = $dir . '/cron-' . $job . '.log';

        // Rotacao simples: o job de sessoes roda de minuto em minuto, e sem
        // corte o arquivo cresceria pra sempre — o mesmo defeito que o
        // purge-old-logins existe pra resolver. Um arquivo .1 de historico
        // basta pra diagnostico.
        if (@filesize($file) > 1048576) {
            @rename($file, $file . '.1');
        }

        @file_put_contents(
            $file,
            sprintf("[%s] %s\n", date('Y-m-d H:i:s'), $message),
            FILE_APPEND | LOCK_EX
        );
    } catch (\Throwable) {
        // Log eh diagnostico, nao funcionalidade.
    }
}

/**
 * Roda o corpo do job, loga o resultado e devolve o exit code.
 *
 * O `$job()` devolve `[mensagem, vale_logar]`. `vale_logar = false` imprime no
 * STDOUT mas nao grava: rodada que nao fez nada, a cada minuto, enterraria o
 * que importa em meio milhao de linhas por ano. A decisao vem de DENTRO da
 * closure de proposito — um terceiro parametro de `cron_run()` seria avaliado
 * ANTES de o job rodar, quando ainda nao ha resultado nenhum pra consultar.
 *
 * Excecao vira linha `FAIL` no log E no STDERR (o unico canal que o cPanel
 * converte em email), alem de exit 1 — um job quebrado tem de ser ruidoso,
 * ao contrario do que aconteceu aqui.
 *
 * @param callable():array{0:string,1:bool} $job
 */
function cron_run(string $name, callable $job): int
{
    $start = microtime(true);
    try {
        [$message, $worthLogging] = $job();
    } catch (\Throwable $e) {
        $line = sprintf('FAIL %s: %s', $name, $e->getMessage());
        cron_log($name, $line);
        // STDERR eh o que vira email no cPanel — ver a nota do cabecalho.
        fwrite(STDERR, $line . "\n");
        return 1;
    }

    $elapsed = (int) round((microtime(true) - $start) * 1000);
    $line    = sprintf('%s (%dms)', (string) $message, $elapsed);
    if ($worthLogging) {
        cron_log($name, $line);
    }
    echo $line . "\n";
    return 0;
}
