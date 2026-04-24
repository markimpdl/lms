<?php
declare(strict_types=1);

/**
 * Template `new_evaluation` — pt.
 *
 * Disparado quando o professor cria uma avaliação (E7-01). Vars:
 *   :student_name — nome do aluno
 *   :title        — título da avaliação
 *   :link         — URL absoluta pra /student/evaluation/{id}
 */

return [
    'subject' => 'Nova avaliação: :title',

    'html' => <<<'HTML'
<p style="margin:0 0 12px;">Olá, <strong>:student_name</strong>!</p>
<p style="margin:0 0 12px;">O professor publicou uma nova avaliação: <strong>:title</strong>.</p>
<p style="margin:0 0 16px;">Confira o enunciado (PDF) e envie sua resposta antes do prazo.</p>
<p style="margin:16px 0;">
  <a href=":link" style="display:inline-block;padding:10px 20px;border-radius:999px;background:#6366F1;color:#ffffff;text-decoration:none;font-weight:600;font-size:14px;">Abrir avaliação</a>
</p>
<p style="margin:0;color:#6B7280;font-size:13px;">Ou copie este endereço no navegador: :link</p>
HTML,

    'text' => <<<TXT
Olá, :student_name!

O professor publicou uma nova avaliação: ":title".

Confira o enunciado (PDF) e envie sua resposta antes do prazo.

Acesse: :link
TXT,
];
