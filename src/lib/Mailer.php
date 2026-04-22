<?php
declare(strict_types=1);

/**
 * Mailer do LMS.
 *
 * Fase atual (E1-03): SEM envio SMTP real. Todas as mensagens são escritas
 * em storage/logs/mail-debug.log no formato "plain+html" para inspeção
 * manual. E10 substitui o corpo de send() pela integração PHPMailer.
 *
 * Interface pensada para ser estável entre as fases — páginas e controllers
 * chamam Mailer::send() igual em debug e em produção.
 */
final class Mailer
{
    private const LOG_PATH = '/storage/logs/mail-debug.log';

    /**
     * True quando há SMTP real por trás (Hostgator + PHPMailer, vindo em E10-03).
     * Usado por fluxos que precisam decidir entre enviar credenciais por email ou
     * exibi-las na tela para o admin copiar (E2-02).
     *
     * Enquanto `send()` só escreve em log, isso devolve false. Ao ligar o SMTP
     * de verdade, passa a consultar a config de env.
     */
    public static function isConfigured(): bool
    {
        return false;
    }

    /**
     * Envia (ou, na fase atual, registra) um email.
     * $htmlBody e $textBody são fornecidos por quem chama — o Mailer não gera
     * um a partir do outro (mantém controle total de formatação).
     */
    public static function send(string $to, string $subject, string $htmlBody, string $textBody): void
    {
        self::logToFile($to, $subject, $htmlBody, $textBody);
    }

    private static function logToFile(string $to, string $subject, string $html, string $text): void
    {
        $logFile = LMS_ROOT . self::LOG_PATH;
        $sep = str_repeat('=', 72);
        $entry = sprintf(
            "[%s] MAIL DEBUG\n%s\nTo:      %s\nSubject: %s\n%s\n-- TEXT --\n%s\n-- HTML --\n%s\n%s\n\n",
            date('Y-m-d H:i:s'),
            $sep,
            $to,
            $subject,
            $sep,
            $text,
            $html,
            $sep
        );
        @file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
    }
}
