<?php
declare(strict_types=1);

/**
 * Template `enrollment` — pt.
 *
 * Disparado quando o professor matricula o aluno num curso novo
 * (E4-02 → hook E10-05). Vars:
 *   :student_name — nome do aluno
 *   :title        — nome do curso
 *   :link         — URL absoluta pra /student/course/{id}
 */

return [
    'subject' => 'Matrícula confirmada: :title',

    'html' => <<<'HTML'
<p style="margin:0 0 12px;">Olá, <strong>:student_name</strong>!</p>
<p style="margin:0 0 12px;">Você foi matriculado no curso <strong>:title</strong>.</p>
<p style="margin:0 0 16px;">Acesse a plataforma pra conhecer as unidades de competência, baixar conteúdos e começar as atividades.</p>
<p style="margin:16px 0;">
  <a href=":link" style="display:inline-block;padding:10px 20px;border-radius:999px;background:#6366F1;color:#ffffff;text-decoration:none;font-weight:600;font-size:14px;">Abrir curso</a>
</p>
<p style="margin:0;color:#6B7280;font-size:13px;">Ou copie este endereço no navegador: :link</p>
HTML,

    'text' => <<<TXT
Olá, :student_name!

Você foi matriculado no curso ":title".

Acesse a plataforma pra conhecer as unidades de competência, baixar conteúdos e começar as atividades.

Acesse: :link
TXT,
];
