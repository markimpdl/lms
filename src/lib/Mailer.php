<?php
declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

/**
 * Mailer do LMS.
 *
 * Dois modos, decididos em runtime pelo que está em config/env.php:
 *  - SMTP real via PHPMailer quando SMTP_HOST/USER/PASS estão preenchidos.
 *  - Fallback em storage/logs/mail-debug.log quando não estão (dev sem SMTP).
 *
 * A interface é estável entre os dois modos — callers não precisam saber.
 * Falhas de envio SMTP são logadas em storage/logs/mail.log e silenciadas
 * (a UX do fluxo chamador não depende do retorno).
 */
final class Mailer
{
    private const DEBUG_LOG = '/storage/logs/mail-debug.log';
    private const SEND_LOG  = '/storage/logs/mail.log';

    public static function isConfigured(): bool
    {
        $env = $GLOBALS['__ENV'] ?? [];
        return !empty($env['SMTP_HOST'])
            && !empty($env['SMTP_USER'])
            && !empty($env['SMTP_PASS'])
            && !empty($env['SMTP_FROM']);
    }

    /**
     * Retorna `null` em sucesso (ou no fallback de debug — considerado
     * "entregue" pro fluxo de dev). Retorna a **mensagem de erro** quando o
     * SMTP falhou — o erro já fica em `storage/logs/mail.log`, mas o
     * chamador pode logar contexto adicional (ex.: E10-03 grava em
     * `mail-failures.log` com `user_id` + `type` + a mesma mensagem).
     */
    public static function send(string $to, string $subject, string $htmlBody, string $textBody): ?string
    {
        if (!self::isConfigured()) {
            self::logDebug($to, $subject, $htmlBody, $textBody);
            return null;
        }

        $env = $GLOBALS['__ENV'];
        $mail = new PHPMailer(true);
        try {
            $mail->CharSet = 'UTF-8';
            $mail->Encoding = 'base64';
            $mail->isSMTP();
            $mail->Host = (string)$env['SMTP_HOST'];
            $mail->Port = (int)($env['SMTP_PORT'] ?? 465);
            $mail->SMTPAuth = true;
            $mail->Username = (string)$env['SMTP_USER'];
            $mail->Password = (string)$env['SMTP_PASS'];
            $mail->Timeout  = (int)($env['SMTP_TIMEOUT'] ?? 10);

            $secure = strtolower((string)($env['SMTP_SECURE'] ?? 'ssl'));
            $mail->SMTPSecure = $secure === 'tls'
                ? PHPMailer::ENCRYPTION_STARTTLS
                : PHPMailer::ENCRYPTION_SMTPS;

            $mail->setFrom((string)$env['SMTP_FROM'], (string)($env['SMTP_FROM_NAME'] ?? 'LMS'));
            $mail->addAddress($to);

            $mail->Subject = $subject;
            $mail->isHTML(true);
            $mail->Body = $htmlBody;
            $mail->AltBody = $textBody;

            $mail->send();
            self::logSend('ok', $to, $subject, null);
            return null;
        } catch (PHPMailerException $e) {
            $err = $mail->ErrorInfo ?: $e->getMessage();
            self::logSend('fail', $to, $subject, $err);
            return $err;
        } catch (\Throwable $e) {
            $err = $e->getMessage();
            self::logSend('fail', $to, $subject, $err);
            return $err;
        }
    }

    private static function logDebug(string $to, string $subject, string $html, string $text): void
    {
        $logFile = LMS_ROOT . self::DEBUG_LOG;
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

    private static function logSend(string $status, string $to, string $subject, ?string $error): void
    {
        $logFile = LMS_ROOT . self::SEND_LOG;
        $entry = sprintf(
            "[%s] %s to=%s subject=%s%s\n",
            date('Y-m-d H:i:s'),
            strtoupper($status),
            $to,
            $subject,
            $error !== null ? ' error=' . $error : ''
        );
        @file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
    }
}
