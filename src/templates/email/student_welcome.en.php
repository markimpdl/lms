<?php
declare(strict_types=1);

/**
 * Student welcome email (E4-01) — English.
 *
 * Invoked via closure in TeacherStudentsController::sendWelcomeEmail:
 *   $data['name']      string
 *   $data['email']     string
 *   $data['password']  string
 *   $data['login_url'] string
 *
 * Returns {subject, html, text}. Strings are escaped here.
 */

/** @var array{name:string,email:string,password:string,login_url:string} $data */

$name     = htmlspecialchars($data['name'],      ENT_QUOTES, 'UTF-8');
$email    = htmlspecialchars($data['email'],     ENT_QUOTES, 'UTF-8');
$password = htmlspecialchars($data['password'],  ENT_QUOTES, 'UTF-8');
$loginUrl = htmlspecialchars($data['login_url'], ENT_QUOTES, 'UTF-8');

$subject = 'LMS — your account is ready';

$html = <<<HTML
<!doctype html>
<html><body style="font-family:system-ui,sans-serif;max-width:560px;margin:0 auto;padding:1rem">
    <p>Hi {$name}!</p>
    <p>Your LMS account was just created by your teacher. Use the credentials below to sign in:</p>
    <table style="border-collapse:collapse;margin:1rem 0">
        <tr><td style="padding:.3rem .6rem;color:#6c757d">Email:</td><td style="padding:.3rem .6rem"><strong>{$email}</strong></td></tr>
        <tr><td style="padding:.3rem .6rem;color:#6c757d">Password:</td><td style="padding:.3rem .6rem"><code>{$password}</code></td></tr>
    </table>
    <p><a href="{$loginUrl}" style="display:inline-block;padding:.6rem 1.2rem;background:#0d6efd;color:#fff;border-radius:.4rem;text-decoration:none">Sign in to the platform</a></p>
    <p style="color:#6c757d;font-size:.9rem">For security, change your password on first login: user menu &rarr; My profile &rarr; Password.</p>
    <hr>
    <p style="color:#6c757d;font-size:.85rem">LMS Team</p>
</body></html>
HTML;

$text = <<<TEXT
Hi {$data['name']}!

Your LMS account was just created by your teacher. Use the credentials
below to sign in:

    Email: {$data['email']}
    Password: {$data['password']}

Sign in: {$data['login_url']}

For security, change your password on first login
(My profile > Password).

LMS Team
TEXT;

return [
    'subject' => $subject,
    'html'    => $html,
    'text'    => $text,
];
