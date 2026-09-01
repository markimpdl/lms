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
 *   - Cron daily 03:00 UTC: `php /home/USER/.../scripts/cron/purge-old-logins.php`
 *   - Stdout/Stderr direcionado pra log via redirect ou null se não for útil.
 *
 * Saída: número de rows apagadas + status. Exit 0 em sucesso.
 */

require dirname(__DIR__, 2) . '/src/bootstrap.php';

$start   = microtime(true);
try {
    $deleted  = UserLogin::purgeOlderThan();
    $attempts = AuthController::purgeOldAttempts();
} catch (\Throwable $e) {
    fwrite(STDERR, "purge-old-logins: " . $e->getMessage() . "\n");
    exit(1);
}
$elapsed = (int) round((microtime(true) - $start) * 1000);

echo sprintf(
    "purge-old-logins: %d login(s) + %d attempt(s) removed in %dms "
    . "(retention=%d/%d days)\n",
    $deleted,
    $attempts,
    $elapsed,
    UserLogin::RETENTION_DAYS,
    AuthController::ATTEMPTS_RETENTION_DAYS
);
exit(0);
