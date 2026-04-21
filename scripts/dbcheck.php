<?php
declare(strict_types=1);

/**
 * Sanity check da conexão MySQL.
 * Uso: php scripts/dbcheck.php
 */

require dirname(__DIR__) . '/src/bootstrap.php';

$pdo = Database::pdo();
$sum = $pdo->query('SELECT 1 + 1 AS sum')->fetchColumn();

if ((int) $sum !== 2) {
    fwrite(STDERR, "Consulta inesperada: SELECT 1 + 1 retornou {$sum}\n");
    exit(1);
}

$version = $pdo->query('SELECT VERSION()')->fetchColumn();
echo "OK — conectado ao MySQL {$version}. SELECT 1 + 1 = {$sum}.\n";
exit(0);
