<?php
declare(strict_types=1);

/**
 * Template `retry_enabled` — pt.
 *
 * Disparado apenas quando o professor marca "permitir reenvio" e a nota
 * efetiva permite (< 6). Evento separado de `grade_evaluation` por
 * decisão do PO (2026-04-24): a ação "reenviar" merece CTA dedicado.
 */

return [
    'subject' => 'Reenvio liberado: :title',

    'html' => <<<'HTML'
<p style="margin:0 0 12px;">Olá, <strong>:student_name</strong>!</p>
<p style="margin:0 0 12px;">O professor liberou reenvio pra sua avaliação <strong>:title</strong>.</p>
<p style="margin:0 0 16px;">Revise o feedback e envie uma nova tentativa quando estiver pronto.</p>
<p style="margin:16px 0;">
  <a href=":link" style="display:inline-block;padding:10px 20px;border-radius:999px;background:#EC4899;color:#ffffff;text-decoration:none;font-weight:600;font-size:14px;">Reenviar avaliação</a>
</p>
<p style="margin:0;color:#6B7280;font-size:13px;">Ou copie este endereço no navegador: :link</p>
HTML,

    'text' => <<<TXT
Olá, :student_name!

O professor liberou reenvio pra sua avaliação ":title".

Revise o feedback e envie uma nova tentativa quando estiver pronto.

Acesse: :link
TXT,
];
