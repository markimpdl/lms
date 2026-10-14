<?php
declare(strict_types=1);

/**
 * Partial: seção de anexos (materiais para download) da unidade, na tela do
 * aluno. Única para V1 e V2 — a seção vivia só no layout V1 e o aluno de
 * curso com trilha nunca via os anexos.
 *
 * Espera no escopo:
 *   $attachments      list<array> — linhas no formato de attachment_row.php
 *                                  (imagem e áudio já filtrados)
 *   $attachmentsCount int
 */

if ($attachmentsCount <= 0) {
    return;
}
?>
<section class="lms-unit-section" id="attachments">
    <?php
        $title = __t('student.unit.tab.attachments');
        $count = $attachmentsCount;
        $accent = null;
        require LMS_ROOT . '/src/templates/partials/section_header.php';
    ?>
    <div class="lms-attachment-list">
        <?php foreach ($attachments as $attachment): ?>
            <?php require LMS_ROOT . '/src/templates/partials/attachment_row.php'; ?>
        <?php endforeach; ?>
    </div>
</section>
