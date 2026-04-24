<?php
declare(strict_types=1);

/**
 * Serviço de fanout de notificações (E10-00).
 *
 * Ponto único de entrega pros dois canais — sino in-app (via tabela
 * `notifications`) e email (via `Mailer`, cabeado em E10-03).
 *
 * Contrato:
 *  - Insert dos sinos SEMPRE acontece (ordem: sino primeiro, email depois).
 *  - Email é **síncrono** (decisão do PO, 2026-04-24) — falha de SMTP não
 *    impede o sino. Falhas vão pra log de arquivo até E10-07 promover
 *    pra tabela.
 *  - Idioma do email: `courses.language` quando `$courseId` informado;
 *    senão, `users.language` de cada destinatário (doc/09).
 *
 * Esta story (E10-00) entrega só o scaffolding — insere sinos e resolve
 * idioma. O envio real de email entra em E10-03 junto com os templates.
 */
final class NotificationService
{
    /**
     * Dispara notificação pra lista de destinatários. Lista vazia é no-op.
     *
     * @param list<int> $userIds
     */
    public static function fanout(
        string $type,
        array $userIds,
        string $title,
        ?string $body = null,
        ?string $link = null,
        ?int $courseId = null,
        bool $sendEmail = true
    ): void {
        if ($userIds === []) {
            return;
        }

        Database::tx(function () use ($type, $userIds, $title, $body, $link): void {
            foreach ($userIds as $uid) {
                Notification::create((int) $uid, $type, $title, $body, $link);
            }
        });

        if ($sendEmail) {
            // TODO(E10-03): renderizar template e chamar Mailer::send por
            // destinatário (try/catch individual + log em storage/logs/
            // mail-failures.log). `resolveLanguage($uid, $courseId)` já dá
            // o idioma certo.
            unset($courseId); // silencia PHPStan/IDE até E10-03 consumir
        }
    }

    /**
     * Resolve o idioma pro template de email: `courses.language` quando
     * houver contexto de curso (doc/09); senão, `users.language`. Fallback
     * 'pt' se nenhum dos dois resolver (usuário deletado, curso inválido).
     *
     * Público porque E10-03 vai chamar diretamente; inerte em E10-00.
     */
    public static function resolveLanguage(int $userId, ?int $courseId): string
    {
        if ($courseId !== null) {
            $stmt = Database::pdo()->prepare(
                'SELECT language FROM courses WHERE id = ? LIMIT 1'
            );
            $stmt->execute([$courseId]);
            $lang = $stmt->fetchColumn();
            if ($lang !== false) {
                return (string) $lang;
            }
        }

        $stmt = Database::pdo()->prepare(
            'SELECT language FROM users WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$userId]);
        $lang = $stmt->fetchColumn();
        return $lang !== false ? (string) $lang : 'pt';
    }
}
