<?php
declare(strict_types=1);

/**
 * Dados + script do botão "Áudio" do TinyMCE (`/assets/js/tinymce-audio.js`).
 * Incluir ANTES do `tinymce.init()` da página, que chama
 * `lmsRegisterAudioButton(editor)` no `setup` e põe `audioinsert` no toolbar.
 *
 * Espera:
 *   $audioPickerOptions list<array{title:string,value:string}>
 *                       — de `lesson_audio_picker_options()`
 */

$audioPickerOptions = $audioPickerOptions ?? [];
?>
<script>
window.lmsAudioPicker = {
    items: <?= json_encode($audioPickerOptions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?>,
    labels: <?= json_encode([
        'button' => __t('content.audio.button'),
        'title'  => __t('content.audio.title'),
        'field'  => __t('content.audio.field'),
        'none'   => __t('content.audio.none'),
        'insert' => __t('common.insert'),
        'cancel' => __t('common.cancel'),
    ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>
};
</script>
<script src="/assets/js/tinymce-audio.js"></script>
