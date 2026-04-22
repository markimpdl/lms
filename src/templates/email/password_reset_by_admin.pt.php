<?php
declare(strict_types=1);

/**
 * Reset de senha feito pelo super-admin (E2-07) — Português.
 *
 * Contrato idêntico a teacher_welcome.pt.php: recebe $data com email,
 * password, login_url, tenant_name; retorna {subject, html, text}.
 */

/** @var array{email:string,password:string,login_url:string,tenant_name:string} $data */

$email    = htmlspecialchars($data['email'],     ENT_QUOTES, 'UTF-8');
$password = htmlspecialchars($data['password'],  ENT_QUOTES, 'UTF-8');
$loginUrl = htmlspecialchars($data['login_url'], ENT_QUOTES, 'UTF-8');

$subject = 'LMS — sua senha foi redefinida pelo administrador';

$html = <<<HTML
<!doctype html>
<html><body style="font-family:system-ui,sans-serif;max-width:560px;margin:0 auto;padding:1rem">
    <p>Olá!</p>
    <p>O administrador redefiniu sua senha na plataforma LMS. Use as credenciais abaixo para entrar:</p>
    <table style="border-collapse:collapse;margin:1rem 0">
        <tr><td style="padding:.3rem .6rem;color:#6c757d">Email:</td><td style="padding:.3rem .6rem"><strong>{$email}</strong></td></tr>
        <tr><td style="padding:.3rem .6rem;color:#6c757d">Nova senha:</td><td style="padding:.3rem .6rem"><code>{$password}</code></td></tr>
    </table>
    <p><a href="{$loginUrl}" style="display:inline-block;padding:.6rem 1.2rem;background:#0d6efd;color:#fff;border-radius:.4rem;text-decoration:none">Entrar na plataforma</a></p>
    <p style="color:#6c757d;font-size:.9rem">Por segurança, troque essa senha no primeiro acesso em <em>Meu perfil &rarr; Senha</em>. Sessões ativas em outros dispositivos foram encerradas automaticamente.</p>
    <p style="color:#6c757d;font-size:.9rem">Se você não pediu este reset, responda este email imediatamente.</p>
    <hr>
    <p style="color:#6c757d;font-size:.85rem">Equipe LMS</p>
</body></html>
HTML;

$text = <<<TEXT
Olá!

O administrador redefiniu sua senha na plataforma LMS. Use as credenciais
abaixo para entrar:

    Email:       {$data['email']}
    Nova senha:  {$data['password']}

Acesso: {$data['login_url']}

Por segurança, troque essa senha no primeiro acesso (Meu perfil > Senha).
Sessões ativas em outros dispositivos foram encerradas automaticamente.

Se você não pediu este reset, responda este email imediatamente.

Equipe LMS
TEXT;

return [
    'subject' => $subject,
    'html'    => $html,
    'text'    => $text,
];
