<?php
declare(strict_types=1);

/**
 * Partial: botão maximizar/restaurar do modo foco.
 *
 * Encolhe a sidebar do aluno pro rail de ícones e solta o `max-width` da
 * grid, alargando o conteúdo sem tirar a trilha da direita. O estado vive em
 * localStorage e é aplicado por /assets/js/student-focus.js — o desenho está
 * em student-area.css (`body.lms-focus`).
 *
 * Só as telas de conteúdo do curso V2 oferecem o "maximizar"; o "restaurar"
 * mora no rail e acompanha o aluno em qualquer tela. Quem decide se este
 * botão aparece é o caller — o partial em si renderiza sempre.
 */
?>
<button type="button" class="lms-focus-toggle" data-lms-focus-toggle
        aria-pressed="false"
        data-label-off="<?= e(__t('student.focus.maximize')) ?>"
        data-label-on="<?= e(__t('student.focus.restore')) ?>"
        title="<?= e(__t('student.focus.maximize')) ?>"
        aria-label="<?= e(__t('student.focus.maximize')) ?>">
    <i class="bi bi-arrows-angle-expand" aria-hidden="true"></i>
</button>
