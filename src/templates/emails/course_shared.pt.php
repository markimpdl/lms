<?php
declare(strict_types=1);

/**
 * Template `course_shared` — pt (E32-03 / F23).
 *
 * Disparado quando um professor compartilha a autoria de um curso com outro
 * professor (colaborador). Vars:
 *   :student_name — nome do professor convidado (reusa o slot genérico)
 *   :title        — nome do curso compartilhado
 *   :body         — nome do professor que compartilhou (dono)
 *   :link         — URL absoluta pra /teacher/courses/{id}
 */

return [
    'subject' => 'Curso compartilhado com você: :title',

    'html' => <<<'HTML'
<p style="margin:0 0 12px;">Olá, <strong>:student_name</strong>!</p>
<p style="margin:0 0 12px;"><strong>:body</strong> compartilhou a autoria do curso <strong>:title</strong> com você.</p>
<p style="margin:0 0 16px;">A partir de agora você pode editar o conteúdo, criar competências e unidades, e matricular os seus próprios alunos. Cada professor mantém seus alunos, notas e ranking separados.</p>
<p style="margin:16px 0;">
  <a href=":link" style="display:inline-block;padding:10px 20px;border-radius:999px;background:#6366F1;color:#ffffff;text-decoration:none;font-weight:600;font-size:14px;">Abrir curso</a>
</p>
<p style="margin:0;color:#6B7280;font-size:13px;">Ou copie este endereço no navegador: :link</p>
HTML,

    'text' => <<<TXT
Olá, :student_name!

:body compartilhou a autoria do curso ":title" com você.

A partir de agora você pode editar o conteúdo, criar competências e unidades, e matricular os seus próprios alunos. Cada professor mantém seus alunos, notas e ranking separados.

Acesse: :link
TXT,
];
