<?php
/**
 * Partial: modal "Importar markdown" dos editores TinyMCE.
 *
 * Espera no escopo do caller:
 *   $mdEditorId string  id do <textarea> que o TinyMCE controla (ex.: 'lessonHtml')
 *
 * Renderize FORA do <form> (o modal tem seus próprios controles) e DEPOIS do
 * `tinymce.init()` da página — o init aqui só roda no DOMContentLoaded, mas o
 * editor precisa estar registrado quando o professor clicar em Inserir.
 *
 * O markdown é convertido no browser (`/assets/js/markdown-import.js` + `marked`
 * do CDN) e inserido como HTML no editor. Nada muda no POST: o ContentSanitizer
 * continua sendo quem decide o que entra no banco.
 */
$mdModalId = 'markdownImportModal';
?>
<div class="modal fade" id="<?= e($mdModalId) ?>" tabindex="-1"
     aria-labelledby="markdownImportLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title h5" id="markdownImportLabel">
                    <?= e(__t('markdown.import.title')) ?>
                </h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal"
                        aria-label="<?= e(__t('common.cancel')) ?>"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small"><?= e(__t('markdown.import.help')) ?></p>

                <label for="mdImportSource" class="form-label visually-hidden">
                    <?= e(__t('markdown.import.title')) ?>
                </label>
                <textarea id="mdImportSource" class="form-control js-md-source font-monospace"
                          rows="12" spellcheck="false"
                          placeholder="<?= e(__t('markdown.import.placeholder')) ?>"></textarea>

                <div class="form-check mt-3">
                    <input class="form-check-input js-md-replace" type="checkbox" id="mdImportReplace">
                    <label class="form-check-label" for="mdImportReplace">
                        <?= e(__t('markdown.import.replace')) ?>
                    </label>
                </div>

                <div class="alert alert-danger mt-3 mb-0 d-none js-md-error" role="alert"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                    <?= e(__t('common.cancel')) ?>
                </button>
                <button type="button" class="btn btn-primary js-md-insert">
                    <?= e(__t('common.insert')) ?>
                </button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/marked@12.0.2/marked.min.js"
        integrity="sha384-/TQbtLCAerC3jgaim+N78RZSDYV7ryeoBCVqTuzRrFec2akfBkHS7ACQ3PQhvMVi"
        crossorigin="anonymous" referrerpolicy="origin"></script>
<script defer src="/assets/js/markdown-import.js"></script>
<script>
// Scripts inline rodam antes dos `defer`, e o DOMContentLoaded vem depois dos
// dois — aqui o LmsMarkdownImport já existe.
document.addEventListener('DOMContentLoaded', function () {
    if (!window.LmsMarkdownImport) {
        return;
    }
    window.LmsMarkdownImport.init({
        modalId:  <?= json_encode($mdModalId, JSON_UNESCAPED_UNICODE) ?>,
        editorId: <?= json_encode($mdEditorId, JSON_UNESCAPED_UNICODE) ?>,
        labels: {
            empty:       <?= json_encode(__t('markdown.import.err.empty'), JSON_UNESCAPED_UNICODE) ?>,
            unavailable: <?= json_encode(__t('markdown.import.err.unavailable'), JSON_UNESCAPED_UNICODE) ?>
        }
    });
});
</script>
