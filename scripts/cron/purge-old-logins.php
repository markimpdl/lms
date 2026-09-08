<?php
declare(strict_types=1);

/**
 * Cron de retenção do histórico de conexões (E16-04).
 *
 * Apaga rows de `user_logins` com `logged_in_at` mais velho que
 * `UserLogin::RETENTION_DAYS` (180 dias). Idempotente — rodar várias
 * vezes ao dia é seguro (apenas o primeiro DELETE do dia tem volume).
 *
 * Purga tambem `login_attempts` (tentativas de login pro rate limit) mais
 * velhas que `AuthController::ATTEMPTS_RETENTION_DAYS` — a tabela so era
 * escrita, nunca limpa, e crescia indefinidamente.
 *
 * **Setup no cPanel da Hostinger** (instrução manual):
 *
 *     0 3 * * * /usr/local/bin/php /home/USER/.../scripts/cron/purge-old-logins.php >/dev/null
 *
 * Sem redirect pra arquivo — ver a nota em `close-stale-sessions.php`: um
 * diretorio de log inexistente faz o shell abortar o comando antes do PHP
 * rodar, e o job morre em silencio. O log sai em `storage/logs/`.
 *
 * Saída: número de rows apagadas + status. Exit 0 em sucesso.
 */

require dirname(__DIR__, 2) . '/src/bootstrap.php';
require __DIR__ . '/_cron_log.php';

exit(cron_run('purge', static function (): array {
    $deleted  = UserLogin::purgeOlderThan();
    $attempts = AuthController::purgeOldAttempts();
    return [
        sprintf(
            'purge-old-logins: %d login(s) + %d attempt(s) removed (retention=%d/%d days)',
            $deleted,
            $attempts,
            UserLogin::RETENTION_DAYS,
            AuthController::ATTEMPTS_RETENTION_DAYS
        ),
        // Diario: uma linha por dia eh barata e serve de prova de vida.
        true,
    ];
}));
