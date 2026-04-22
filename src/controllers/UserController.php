<?php
declare(strict_types=1);

/**
 * Ações de perfil do próprio usuário (E1-04): atualizar dados e trocar senha.
 *
 * Email é imutável pelo usuário (ADR-021) — a coluna nem chega a ser tocada aqui.
 */
final class UserController
{
    public const SUPPORTED_LANGUAGES = ['pt', 'en'];
    public const PASSWORD_MIN_LENGTH = 8;

    /**
     * Atualiza nome e idioma do usuário logado.
     * Retorna null em sucesso; string com a chave de erro (i18n) em falha.
     */
    public static function updateProfile(int $userId, string $name, string $language): ?string
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 150) {
            return 'profile.error.name';
        }
        if (!in_array($language, self::SUPPORTED_LANGUAGES, true)) {
            return 'profile.error.language';
        }

        Database::pdo()
            ->prepare('UPDATE users SET name = ?, language = ? WHERE id = ?')
            ->execute([$name, $language, $userId]);

        return null;
    }

    /**
     * Troca a senha validando a atual com password_verify. Nova senha usa bcrypt
     * cost 12 e atualiza password_changed_at com um timestamp gerado em PHP —
     * ele é também devolvido em `$changedAt` (by-ref) para o caller gravar na
     * sessão com valor idêntico ao que ficou no banco. Isso é crítico: o
     * middleware de E1-05 desloga a sessão se os dois valores divergirem.
     *
     * Retorna null em sucesso ou a chave i18n do erro.
     */
    public static function changePassword(
        int $userId,
        string $currentPassword,
        string $newPassword,
        string $confirmPassword,
        ?string &$changedAt = null
    ): ?string {
        if (strlen($newPassword) < self::PASSWORD_MIN_LENGTH) {
            return 'profile.error.password_min';
        }
        if ($newPassword !== $confirmPassword) {
            return 'profile.error.password_mismatch';
        }

        $pdo  = Database::pdo();
        $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $hash = (string) ($stmt->fetchColumn() ?: '');

        if ($hash === '' || !password_verify($currentPassword, $hash)) {
            return 'profile.error.current_wrong';
        }

        $now     = date('Y-m-d H:i:s');
        $newHash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
        $pdo->prepare(
            'UPDATE users SET password_hash = ?, password_changed_at = ?
              WHERE id = ?'
        )->execute([$newHash, $now, $userId]);

        $changedAt = $now;
        return null;
    }
}
