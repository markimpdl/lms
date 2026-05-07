<?php
declare(strict_types=1);

/**
 * Singleton de conexão MySQL via PDO.
 *
 * Uso:
 *   $pdo = Database::pdo();
 *   Database::tx(fn ($pdo) => $pdo->exec('...'));
 *
 * Credenciais vêm de $GLOBALS['__ENV'] (carregado em config/env.php).
 * Se a conexão falhar, detalhes vão para storage/logs/db.log e o usuário
 * recebe mensagem genérica.
 */
final class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        $env = $GLOBALS['__ENV'] ?? [];
        $host = (string) ($env['DB_HOST'] ?? '127.0.0.1');
        $port = (int)    ($env['DB_PORT'] ?? 3306);
        $name = (string) ($env['DB_NAME'] ?? '');
        $user = (string) ($env['DB_USER'] ?? '');
        $pass = (string) ($env['DB_PASS'] ?? '');

        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $name);

        try {
            self::$pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                // charset=utf8mb4 no DSN já emite SET NAMES (PHP 5.3.6+).
            ]);

            // Alinha o time_zone da sessão MySQL com o timezone do PHP
            // (Asia/Dubai por padrão). Sem isso, NOW()/CURRENT_TIMESTAMP
            // gravariam em UTC na Hostinger e ficariam 4h atrás do que
            // o app exibe. Usa offset numérico (+04:00) porque o named
            // tz não está carregado em todos os MariaDB. date('P') retorna
            // o offset do timezone configurado no bootstrap.
            $offset = date('P');
            if (preg_match('/^[+-]\d{2}:\d{2}$/', $offset) === 1) {
                self::$pdo->exec("SET time_zone = '" . $offset . "'");
            }
        } catch (PDOException $e) {
            self::logError('connection', $e);
            self::abortWithGenericError();
        }

        return self::$pdo;
    }

    /**
     * Executa $fn dentro de uma transação.
     * - Commit se $fn retornar normalmente.
     * - Rollback e re-throw se $fn lançar qualquer Throwable.
     * - Chamadas aninhadas não abrem nova transação: rodam dentro da externa.
     *
     * @template T
     * @param callable(PDO): T $fn
     * @return T
     */
    public static function tx(callable $fn): mixed
    {
        $pdo = self::pdo();

        if ($pdo->inTransaction()) {
            return $fn($pdo);
        }

        $pdo->beginTransaction();
        try {
            $result = $fn($pdo);
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private static function logError(string $stage, \Throwable $e): void
    {
        $logFile = LMS_ROOT . '/storage/logs/db.log';
        $line = sprintf(
            "[%s] %s: %s (%s)\n",
            date('Y-m-d H:i:s'),
            $stage,
            $e->getMessage(),
            $e->getCode()
        );
        @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    }

    private static function abortWithGenericError(): never
    {
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, "Falha ao conectar ao banco. Verifique storage/logs/db.log.\n");
            exit(1);
        }

        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="pt"><head><meta charset="utf-8">'
            . '<title>LMS — indisponível</title></head>'
            . '<body style="font-family:sans-serif;max-width:640px;margin:4rem auto;padding:0 1rem">'
            . '<h1>Serviço temporariamente indisponível</h1>'
            . '<p>Estamos com problemas para acessar o banco de dados. Tente novamente em instantes.</p>'
            . '</body></html>';
        exit;
    }
}
