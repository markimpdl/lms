<?php
/**
 * Partial: formulario de licao (E36-03), compartilhado por new.php e edit.php.
 *
 * Espera no escopo do caller:
 *   $cuId       int
 *   $cu         array  CU dona (pra breadcrumb e titulo)
 *   $old        array  keys 'title', 'html', 'xp_value', 'published'
 *   $errors     array  field => chave i18n
 *   $formAction string action do <form>
 *   $isEdit     bool
 *   $lessonId   ?int   so no modo edicao
 *   $imagePickerOptions list<array{title:string,value:string}>
 *
 * O HTML da licao passa por ContentSanitizer::purify() no POST (ver new.php /
 * edit.php). A allowlist do TinyMCE aqui espelha a do sanitizador — o que
 * escapar pela UI o backend remove de qualquer forma.
 */
?>
<div class="d-flex align-items-center justify-content-between gap-2 flex-wrap mb-3">
    <div>
        <h1 class="h4 mb-1">
            <?= e(__t($isEdit ? 'lessons.edit.title' : 'lessons.new.title')) ?>
        </h1>
        <small class="text-muted"><?= e((string) $cu['name']) ?></small>
    </div>
    <a href="/teacher/cu/<?= (int) $cuId ?>" class="btn btn-outline-secondary btn-sm">
        <?= e(__t('common.back')) ?>
    </a>
</div>

<form method="POST" action="<?= e($formAction) ?>">
    <?= csrf_field() ?>

    <div class="card shadow-sm mb-3">
        <div class="card-body">
            <div class="mb-3">
                <label for="f-title" class="form-label"><?= e(__t('lessons.form.title')) ?></label>
                <input type="text" name="title" id="f-title" maxlength="200"
                       class="form-control<?= isset($errors['title']) ? ' is-invalid' : '' ?>"
                       value="<?= e((string) $old['title']) ?>" required>
                <?php if (isset($errors['title'])): ?>
                    <div class="invalid-feedback d-block"><?= e(__t($errors['title'])) ?></div>
                <?php endif; ?>
            </div>

            <div class="row g-3">
                <div class="col-12 col-md-4">
                    <label for="f-xp" class="form-label"><?= e(__t('lessons.form.xp_value')) ?></label>
                    <input type="number" name="xp_value" id="f-xp" min="0" step="1"
                           class="form-control<?= isset($errors['xp_value']) ? ' is-invalid' : '' ?>"
                           value="<?= (int) $old['xp_value'] ?>">
                    <div class="form-text"><?= e(__t('lessons.form.xp_value.help')) ?></div>
                    <?php if (isset($errors['xp_value'])): ?>
                        <div class="invalid-feedback d-block"><?= e(__t($errors['xp_value'])) ?></div>
                    <?php endif; ?>
                </div>
                <div class="col-12 col-md-8 d-flex align-items-end">
                    <div class="form-check mb-2">
                        <input type="checkbox" name="published" id="f-published" value="1"
                               class="form-check-input"
                               <?= !empty($old['published']) ? 'checked' : '' ?>>
                        <label for="f-published" class="form-check-label">
                            <?= e(__t('lessons.form.published')) ?>
                        </label>
                        <div class="form-text"><?= e(__t('lessons.form.published.help')) ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm mb-3">
        <div class="card-body">
            <label for="lessonHtml" class="form-label"><?= e(__t('lessons.form.content')) ?></label>
            <textarea name="html" id="lessonHtml" class="form-control" rows="18"><?= e((string) $old['html']) ?></textarea>
        </div>
    </div>

    <div class="d-flex gap-2 flex-wrap">
        <button type="submit" class="btn btn-primary">
            <?= e(__t($isEdit ? 'common.save' : 'lessons.form.create')) ?>
        </button>
        <a href="/teacher/cu/<?= (int) $cuId ?>" class="btn btn-outline-secondary">
            <?= e(__t('common.cancel')) ?>
        </a>
        <?php if ($isEdit): ?>
            <button type="button" class="btn btn-outline-danger ms-auto"
                    data-bs-toggle="modal" data-bs-target="#deleteConfirmModal"
                    data-item-name="<?= e((string) $old['title']) ?>"
                    data-action-url="/teacher/lesson/<?= (int) $lessonId ?>/delete"
                    data-counts="<?= e(json_encode($deleteCounts ?? [], JSON_UNESCAPED_UNICODE)) ?>">
                <?= e(__t('lessons.delete.button')) ?>
            </button>
        <?php endif; ?>
    </div>
</form>

<?php if ($isEdit): ?>
    <?php require LMS_ROOT . '/src/templates/partials/delete_confirm_modal.php'; ?>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/tinymce@6/tinymce.min.js" referrerpolicy="origin"></script>
<script>
// Mesmo resolver de video do editor de conteudo da CU: so YouTube e Vimeo,
// convertidos pro iframe canonico. Qualquer outro provedor retorna vazio e o
// plugin nao insere nada. O ContentSanitizer (URI.SafeIframeRegexp) reforca
// isso no backend — aqui eh so a UX bloquear na hora do paste.
function resolveVideoUrl(url) {
    var m;
    m = url.match(/(?:youtube\.com\/(?:watch\?v=|embed\/|shorts\/)|youtu\.be\/)([A-Za-z0-9_-]{6,})/);
    if (m) {
        return '<iframe src="https://www.youtube.com/embed/' + m[1] +
               '" frameborder="0" allowfullscreen loading="lazy" class="content-video"></iframe>';
    }
    m = url.match(/(?:player\.vimeo\.com\/video\/|vimeo\.com\/)(\d+)/);
    if (m) {
        return '<iframe src="https://player.vimeo.com/video/' + m[1] +
               '" frameborder="0" allowfullscreen loading="lazy" class="content-video"></iframe>';
    }
    return '';
}

// Imagens ja anexadas na CU — o mesmo acervo do editor de conteudo, servido
// pela rota autenticada de view.
var availableImages = <?= json_encode($imagePickerOptions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

tinymce.init({
    selector: '#lessonHtml',
    height: 500,
    menubar: false,
    plugins: 'lists link table code codesample autolink media image',
    toolbar: 'undo redo | blocks | bold italic underline strikethrough forecolor | ' +
             'alignleft aligncenter alignright | bullist numlist | link table image media | ' +
             'codesample code removeformat',
    block_formats: '<?= e(__t('content.editor.block_formats')) ?>',
    codesample_languages: [
        { text: 'Python',     value: 'python' },
        { text: 'C#',         value: 'csharp' },
        { text: 'JavaScript', value: 'javascript' },
        { text: 'HTML/XML',   value: 'markup' },
        { text: 'CSS',        value: 'css' }
    ],
    image_list: availableImages,
    image_caption: false,
    image_description: true,
    image_dimensions: true,
    media_live_embeds: true,
    // Toda URL do plugin `media` passa pelo resolver: sem match, nada entra.
    media_url_resolver: function (data) {
        return new Promise(function (resolve) {
            resolve({ html: resolveVideoUrl(data.url) });
        });
    },
    branding: false,
    promotion: false,
    convert_urls: false
});
</script>
