<?php
declare(strict_types=1);

/**
 * Teacher welcome email template (E2-02) — English.
 * Same contract as teacher_welcome.pt.php — see that file for parameters.
 */

/** @var array{email:string,password:string,login_url:string,tenant_name:string} $data */

$email    = htmlspecialchars($data['email'],       ENT_QUOTES, 'UTF-8');
$password = htmlspecialchars($data['password'],    ENT_QUOTES, 'UTF-8');
$loginUrl = htmlspecialchars($data['login_url'],   ENT_QUOTES, 'UTF-8');
$tenant   = htmlspecialchars($data['tenant_name'], ENT_QUOTES, 'UTF-8');

$subject = 'LMS — your access is ready';

$html = <<<HTML
<!doctype html>
<html><body style="font-family:system-ui,sans-serif;max-width:560px;margin:0 auto;padding:1rem">
    <p>Hello!</p>
    <p>Your workspace <strong>{$tenant}</strong> has been created on the LMS platform. Use the credentials below to log in:</p>
    <table style="border-collapse:collapse;margin:1rem 0">
        <tr><td style="padding:.3rem .6rem;color:#6c757d">Email:</td><td style="padding:.3rem .6rem"><strong>{$email}</strong></td></tr>
        <tr><td style="padding:.3rem .6rem;color:#6c757d">Password:</td><td style="padding:.3rem .6rem"><code>{$password}</code></td></tr>
    </table>
    <p><a href="{$loginUrl}" style="display:inline-block;padding:.6rem 1.2rem;background:#0d6efd;color:#fff;border-radius:.4rem;text-decoration:none">Log in to the platform</a></p>
    <p style="color:#6c757d;font-size:.9rem">For security, change your password on the first login: user menu &rarr; My profile &rarr; Password.</p>
    <hr>
    <p style="color:#6c757d;font-size:.85rem">LMS Team</p>
</body></html>
HTML;

$text = <<<TEXT
Hello!

Your workspace "{$data['tenant_name']}" has been created on the LMS
platform. Use the credentials below to log in:

    Email:    {$data['email']}
    Password: {$data['password']}

Log in: {$data['login_url']}

For security, please change your password on your first login
(My profile > Password).

LMS Team
TEXT;

return [
    'subject' => $subject,
    'html'    => $html,
    'text'    => $text,
];
