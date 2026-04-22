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

    public static function send(string $to, string $subject, string $htmlBody, string $textBody): void
    {
        if (!self::isConfigured()) {
            self::logDebug($to, $subject, $htmlBody, $textBody);
            return;
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
        } catch (PHPMailerException $e) {
            self::logSend('fail', $to, $subject, $mail->ErrorInfo ?: $e->getMessage());
        } catch (\Throwable $e) {
            self::logSend('fail', $to, $subject, $e->getMessage());
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
