<?php
declare(strict_types=1);

/**
 * Serviço de fanout de notificações (E10-00 + E10-03).
 *
 * Ponto único de entrega pros dois canais — sino in-app (via tabela
 * `notifications`) e email (via `EmailTemplates` + `Mailer`).
 *
 * Contrato:
 *  - Insert dos sinos SEMPRE acontece (ordem: sino primeiro, email depois).
 *  - Email é **síncrono** (decisão do PO, 2026-04-24) — falha de SMTP não
 *    impede o sino. Falhas vão pra `storage/logs/mail-failures.log` até
 *    E10-07 promover pra tabela.
 *  - Timeout no SMTP via `SMTP_TIMEOUT` em `config/env.php` (default 10s).
 *  - Idioma do email: `courses.language` quando `$courseId` informado;
 *    senão, `users.language` de cada destinatário (doc/09).
 *  - Template é `$type` (ex.: 'activity_feedback' → activity_feedback.<lang>.php).
 *    Vars disponíveis: `:student_name`, `:title`, `:body`, `:link` (abs URL).
 *
 * Quando um template não existir pro `$type`, o email é pulado silenciosamente
 * (EmailTemplates loga o miss em `storage/logs/email-missing.log`). O sino
 * ainda é criado — garantia mínima de que o evento não se perde.
 */
final class NotificationService
{
    private const FAILURE_LOG = '/storage/logs/mail-failures.log';

    /**
     * Catálogo de eventos do F9 (E21). Whitelist usada pela página de
     * config (`/teacher/settings/notifications` em E21-03) pra renderizar
     * a matriz e validar o submit. O `fanout` NÃO valida contra essa
     * lista — eventos novos podem ser disparados sem cadastro prévio (a
     * config simplesmente não terá toggle e o helper retornará default ON).
     */
    public const EVENTS = [
        'enrollment',
        'activity_new',
        'submission_closed',
        'activity_feedback',
        'new_evaluation',
        'grade_evaluation',
        'retry_enabled',
        'content_published',
    ];

    public const CHANNELS = ['bell', 'email'];

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

        // E21-02: gate por (tenant, event, channel). Resolve tenant da 1ª
        // recipient (todos os destinatários de um fanout são alunos do
        // mesmo tenant). null → sem gate (preserva comportamento atual).
        $tenantId = self::resolveTenantId($userIds);

        // 1) Inserts de sino — sempre, dentro de transação. Pula se gate
        // do canal 'bell' está OFF pro tenant.
        $bellEnabled = $tenantId === null
            || notification_enabled($tenantId, $type, 'bell');

        if ($bellEnabled) {
            Database::tx(function () use ($type, $userIds, $title, $body, $link): void {
                foreach ($userIds as $uid) {
                    Notification::create((int) $uid, $type, $title, $body, $link);
                }
            });
        }

        // 2) Email síncrono por destinatário. Falha de SMTP não re-throw.
        if (!$sendEmail) {
            return;
        }

        // Gate por canal 'email'. Skip silencioso se OFF.
        $emailEnabled = $tenantId === null
            || notification_enabled($tenantId, $type, 'email');
        if (!$emailEnabled) {
            return;
        }

        $recipients = self::fetchRecipients($userIds);

        foreach ($recipients as $r) {
            $email = (string) ($r['email'] ?? '');
            if ($email === '') {
                continue;
            }

            $lang = self::resolveLanguage((int) $r['id'], $courseId);
            $rendered = EmailTemplates::render($type, $lang, [
                'student_name' => (string) ($r['name'] ?? ''),
                'title'        => $title,
                'body'         => (string) ($body ?? ''),
                'link'         => $link !== null ? app_url($link) : '',
            ]);

            // Template sem subject = template não existe — EmailTemplates
            // já logou em email-missing.log. Pula envio pra evitar email
            // em branco com header/footer do layout.
            if ($rendered['subject'] === '') {
                continue;
            }

            $err = Mailer::send($email, $rendered['subject'], $rendered['html'], $rendered['text']);
            if ($err !== null) {
                self::logFailure((int) $r['id'], $email, $type, $err);
            }
        }
    }

    /**
     * Resolve o idioma pro template de email: `courses.language` quando
     * houver contexto de curso (doc/09); senão, `users.language`. Fallback
     * 'pt' se nenhum dos dois resolver (usuário deletado, curso inválido).
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

    /**
     * Busca email+nome+id dos destinatários numa única query. Ignora
     * usuários inativos.
     *
     * @param list<int> $userIds
     * @return list<array{id:int,name:string,email:string}>
     */
    private static function fetchRecipients(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $stmt = Database::pdo()->prepare(
            'SELECT id, name, email FROM users
              WHERE id IN (' . $placeholders . ') AND active = 1'
        );
        $stmt->execute(array_map('intval', $userIds));
        return $stmt->fetchAll();
    }

    /**
     * Resolve `tenant_id` pro gate de `notification_enabled` (E21-02).
     * Lê do 1º userId da lista — alunos são exclusivos do tenant (ADR-026)
     * e qualquer fanout dispara pra alunos do mesmo tenant que originou
     * a ação. null = recipient não é aluno (caso degenerado) ou erro;
     * caller trata como "sem gate" (default ON).
     *
     * @param list<int> $userIds
     */
    private static function resolveTenantId(array $userIds): ?int
    {
        if ($userIds === []) {
            return null;
        }
        try {
            $stmt = Database::pdo()->prepare(
                'SELECT tenant_id FROM users WHERE id = ? LIMIT 1'
            );
            $stmt->execute([(int) $userIds[0]]);
            $val = $stmt->fetchColumn();
        } catch (\Throwable) {
            return null;
        }
        if ($val === false || $val === null) {
            return null;
        }
        return (int) $val;
    }

    /**
     * Linha em `storage/logs/mail-failures.log` quando `Mailer::send`
     * retorna erro. Timestamp + user_id + type + mensagem — suficiente
     * pra diagnóstico manual até E10-07 criar tabela de falhas.
     */
    private static function logFailure(int $userId, string $email, string $type, string $error): void
    {
        $logFile = LMS_ROOT . self::FAILURE_LOG;
        $line = sprintf(
            "[%s] user_id=%d email=%s type=%s error=%s\n",
            date('Y-m-d H:i:s'),
            $userId,
            $email,
            $type,
            str_replace(["\n", "\r"], ' ', $error)
        );
        @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    }
}
