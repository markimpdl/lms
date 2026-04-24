<?php
declare(strict_types=1);

/**
 * Template `activity_feedback` — pt.
 *
 * Disparado quando o professor salva feedback numa entrega de atividade
 * (E6-04). Usado em E10-03 para ativar o canal email.
 *
 * Placeholders esperados:
 *   :student_name   — nome do aluno destinatário
 *   :title — título da atividade
 *   :link           — URL absoluta pra /student/activity/{id}
 */

return [
    'subject' => 'Feedback em :title',

    'html' => <<<'HTML'
<p style="margin:0 0 12px;">Olá, <strong>:student_name</strong>!</p>
<p style="margin:0 0 12px;">O professor deixou um feedback na sua entrega da atividade <strong>:title</strong>.</p>
<p style="margin:16px 0;">
  <a href=":link" style="display:inline-block;padding:10px 20px;border-radius:999px;background:#6366F1;color:#ffffff;text-decoration:none;font-weight:600;font-size:14px;">Ver feedback</a>
</p>
<p style="margin:0;color:#6B7280;font-size:13px;">Ou copie este endereço no navegador: :link</p>
HTML,

    'text' => <<<TXT
Olá, :student_name!

O professor deixou um feedback na sua entrega da atividade ":title".

Acesse: :link
TXT,
];
