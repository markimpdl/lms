<?php
declare(strict_types=1);

/**
 * Template `content_published` — pt.
 *
 * Disparado quando o professor publica conteúdo numa CU (E5-06). Usado
 * em E10-03 para ativar o canal email.
 *
 * Placeholders esperados:
 *   :student_name — nome do aluno
 *   :title        — nome da CU (Unidade de Competência)
 *   :body         — nome do curso
 *   :link         — URL absoluta pra /student/cu/{id}
 */

return [
    'subject' => 'Novo conteúdo em :title',

    'html' => <<<'HTML'
<p style="margin:0 0 12px;">Olá, <strong>:student_name</strong>!</p>
<p style="margin:0 0 12px;">O professor publicou novo conteúdo na unidade <strong>:title</strong> do curso <strong>:body</strong>.</p>
<p style="margin:16px 0;">
  <a href=":link" style="display:inline-block;padding:10px 20px;border-radius:999px;background:#6366F1;color:#ffffff;text-decoration:none;font-weight:600;font-size:14px;">Abrir unidade</a>
</p>
<p style="margin:0;color:#6B7280;font-size:13px;">Ou copie este endereço no navegador: :link</p>
HTML,

    'text' => <<<TXT
Olá, :student_name!

O professor publicou novo conteúdo na unidade ":title" do curso ":body".

Acesse: :link
TXT,
];
