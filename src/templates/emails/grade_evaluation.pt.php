<?php
declare(strict_types=1);

/**
 * Template `grade_evaluation` — pt.
 *
 * Disparado quando o professor grava nota + feedback numa submissão de
 * avaliação (E7-03). Template é agnóstico à nota — apenas avisa que há
 * resultado. Se reenvio foi liberado, um email separado `retry_enabled`
 * é disparado em sequência (decisão do PO).
 */

return [
    'subject' => 'Correção disponível: :title',

    'html' => <<<'HTML'
<p style="margin:0 0 12px;">Olá, <strong>:student_name</strong>!</p>
<p style="margin:0 0 12px;">O professor corrigiu sua avaliação <strong>:title</strong>. Abra pra ver nota e feedback.</p>
<p style="margin:16px 0;">
  <a href=":link" style="display:inline-block;padding:10px 20px;border-radius:999px;background:#6366F1;color:#ffffff;text-decoration:none;font-weight:600;font-size:14px;">Ver correção</a>
</p>
<p style="margin:0;color:#6B7280;font-size:13px;">Ou copie este endereço no navegador: :link</p>
HTML,

    'text' => <<<TXT
Olá, :student_name!

O professor corrigiu sua avaliação ":title". Abra pra ver nota e feedback.

Acesse: :link
TXT,
];
