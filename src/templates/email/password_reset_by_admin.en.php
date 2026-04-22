<?php
declare(strict_types=1);

/**
 * Admin-triggered password reset (E2-07) — English.
 * Same contract as the PT template.
 */

/** @var array{email:string,password:string,login_url:string,tenant_name:string} $data */

$email    = htmlspecialchars($data['email'],     ENT_QUOTES, 'UTF-8');
$password = htmlspecialchars($data['password'],  ENT_QUOTES, 'UTF-8');
$loginUrl = htmlspecialchars($data['login_url'], ENT_QUOTES, 'UTF-8');

$subject = 'LMS — your password has been reset by the administrator';

$html = <<<HTML
<!doctype html>
<html><body style="font-family:system-ui,sans-serif;max-width:560px;margin:0 auto;padding:1rem">
    <p>Hello!</p>
    <p>The administrator has reset your password on the LMS platform. Use the credentials below to log in:</p>
    <table style="border-collapse:collapse;margin:1rem 0">
        <tr><td style="padding:.3rem .6rem;color:#6c757d">Email:</td><td style="padding:.3rem .6rem"><strong>{$email}</strong></td></tr>
        <tr><td style="padding:.3rem .6rem;color:#6c757d">New password:</td><td style="padding:.3rem .6rem"><code>{$password}</code></td></tr>
    </table>
    <p><a href="{$loginUrl}" style="display:inline-block;padding:.6rem 1.2rem;background:#0d6efd;color:#fff;border-radius:.4rem;text-decoration:none">Log in to the platform</a></p>
    <p style="color:#6c757d;font-size:.9rem">For security, please change this password on your first login at <em>My profile &rarr; Password</em>. Active sessions on other devices have been terminated automatically.</p>
    <p style="color:#6c757d;font-size:.9rem">If you did not request this reset, reply to this email immediately.</p>
    <hr>
    <p style="color:#6c757d;font-size:.85rem">LMS Team</p>
</body></html>
HTML;

$text = <<<TEXT
Hello!

The administrator has reset your password on the LMS platform. Use the
credentials below to log in:

    Email:        {$data['email']}
    New password: {$data['password']}

Log in: {$data['login_url']}

For security, please change this password on your first login
(My profile > Password). Active sessions on other devices have been
terminated automatically.

If you did not request this reset, reply to this email immediately.

LMS Team
TEXT;

return [
    'subject' => $subject,
    'html'    => $html,
    'text'    => $text,
];
