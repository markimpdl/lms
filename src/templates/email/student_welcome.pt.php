<?php
declare(strict_types=1);

/**
 * Template de boas-vindas ao aluno (E4-01) — Português.
 *
 * Recebe via closure em TeacherStudentsController::sendWelcomeEmail:
 *   $data['name']      string
 *   $data['email']     string
 *   $data['password']  string
 *   $data['login_url'] string
 *
 * Retorna {subject, html, text}. Strings já escapadas aqui.
 */

/** @var array{name:string,email:string,password:string,login_url:string} $data */

$name     = htmlspecialchars($data['name'],      ENT_QUOTES, 'UTF-8');
$email    = htmlspecialchars($data['email'],     ENT_QUOTES, 'UTF-8');
$password = htmlspecialchars($data['password'],  ENT_QUOTES, 'UTF-8');
$loginUrl = htmlspecialchars($data['login_url'], ENT_QUOTES, 'UTF-8');

$subject = 'LMS — seu acesso foi criado';

$html = <<<HTML
<!doctype html>
<html><body style="font-family:system-ui,sans-serif;max-width:560px;margin:0 auto;padding:1rem">
    <p>Olá, {$name}!</p>
    <p>Seu acesso à plataforma LMS foi criado pelo(a) seu(sua) professor(a). Use as credenciais abaixo para entrar:</p>
    <table style="border-collapse:collapse;margin:1rem 0">
        <tr><td style="padding:.3rem .6rem;color:#6c757d">Email:</td><td style="padding:.3rem .6rem"><strong>{$email}</strong></td></tr>
        <tr><td style="padding:.3rem .6rem;color:#6c757d">Senha:</td><td style="padding:.3rem .6rem"><code>{$password}</code></td></tr>
    </table>
    <p><a href="{$loginUrl}" style="display:inline-block;padding:.6rem 1.2rem;background:#0d6efd;color:#fff;border-radius:.4rem;text-decoration:none">Entrar na plataforma</a></p>
    <p style="color:#6c757d;font-size:.9rem">Por segurança, troque sua senha no primeiro acesso: menu do usuário &rarr; Meu perfil &rarr; Senha.</p>
    <hr>
    <p style="color:#6c757d;font-size:.85rem">Equipe LMS</p>
</body></html>
HTML;

$text = <<<TEXT
Olá, {$data['name']}!

Seu acesso à plataforma LMS foi criado pelo(a) seu(sua) professor(a). Use as
credenciais abaixo para entrar:

    Email: {$data['email']}
    Senha: {$data['password']}

Acesso: {$data['login_url']}

Por segurança, troque sua senha no primeiro acesso
(Meu perfil > Senha).

Equipe LMS
TEXT;

return [
    'subject' => $subject,
    'html'    => $html,
    'text'    => $text,
];
