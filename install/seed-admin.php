<?php
declare(strict_types=1);

/**
 * Gera uma senha aleatória para o super-admin (admin@lms.local),
 * grava o hash bcrypt no banco e imprime a senha em texto para o operador
 * anotar. Pode ser rodado múltiplas vezes — cada execução ROTACIONA a senha.
 *
 * Uso (no servidor, depois de rodar install/schema.sql):
 *   php install/seed-admin.php
 */

require dirname(__DIR__) . '/src/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Este script só roda via CLI.\n");
}

$adminEmail = 'admin@lms.local';
$adminName  = 'Super Admin';

$password = generate_strong_password(16);
$hash     = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

try {
    $action = Database::tx(static function (PDO $pdo) use ($adminEmail, $adminName, $hash): string {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$adminEmail]);
        $existingId = $stmt->fetchColumn();

        if ($existingId !== false) {
            $upd = $pdo->prepare(
                'UPDATE users SET password_hash = ?, password_changed_at = CURRENT_TIMESTAMP, active = 1 WHERE id = ?'
            );
            $upd->execute([$hash, $existingId]);
            return 'senha rotacionada';
        }

        $ins = $pdo->prepare(
            'INSERT INTO users (email, password_hash, password_changed_at, name, role, language, active, tenant_id) '
            . "VALUES (?, ?, CURRENT_TIMESTAMP, ?, 'super_admin', 'pt', 1, NULL)"
        );
        $ins->execute([$adminEmail, $hash, $adminName]);
        return 'conta criada';
    });
} catch (\Throwable $e) {
    fwrite(STDERR, "Falha ao semear super-admin: " . $e->getMessage() . "\n");
    exit(1);
}

$line = str_repeat('=', 64);
echo "{$line}\n";
echo "Super-admin: {$action}.\n";
echo "Email:  {$adminEmail}\n";
echo "Senha:  {$password}\n";
echo "{$line}\n";
echo "ANOTE A SENHA AGORA — ela não será mostrada novamente.\n";
echo "Rode novamente este script se precisar rotacionar a senha.\n";
exit(0);

/**
 * Gera uma senha forte sem caracteres ambíguos (0/O, 1/l/I) para
 * reduzir erros de transcrição quando o operador anotar.
 */
function generate_strong_password(int $length = 16): string
{
    $chars = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789!@#$%^&*-_=+';
    $max   = strlen($chars) - 1;
    $out   = '';
    for ($i = 0; $i < $length; $i++) {
        $out .= $chars[random_int(0, $max)];
    }
    return $out;
}
