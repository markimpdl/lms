<?php
declare(strict_types=1);

/**
 * /teacher/cu/{id}/content/edit — editor de conteúdo da CU (E5-01).
 *
 * GET: renderiza TinyMCE 6 community via CDN com allowlist alinhada ao
 * HtmlPurifier do backend. POST: sanitiza via HtmlPurifier e faz UPSERT em
 * `contents` via Content::upsertForCu. Checkbox "Publicar" controla
 * `contents.published`.
 *
 * O HTML salvo é considerado seguro para renderizar direto (sem `e()`) —
 * o Purifier já garantiu allowlist de tags/atributos e regex de iframe.
 */

$tenantId = current_tenant_id();
if ($tenantId === null) {
    http_response_code(403);
    require LMS_ROOT . '/src/templates/errors/403.php';
    return;
}

$cuId = (int) ($_REQUEST['id'] ?? 0);
$cu   = CompetenceUnit::findForTenant($cuId, $tenantId);
if ($cu === null) {
    http_response_code(404);
    require LMS_ROOT . '/src/templates/errors/404.php';
    return;
}

if ((int) $cu['course_archived'] === 1) {
    flash('danger', __t('courses.edit.archived_notice'));
    header('Location: /teacher/cu/' . $cuId);
    return;
}

$existing  = Content::findForCu($cuId, $tenantId);
$dirtyHtml = (string) ($existing['html'] ?? '');
$published = $existing !== null ? (int) $existing['published'] === 1 : false;

$attachments = ContentAttachment::listByCu($cuId, $tenantId);

// Imagens disponíveis para o picker do TinyMCE (image plugin). Só MIME de
// imagem; URL vai pela rota autenticada que E5-04 vai implementar
// (`/teacher/cu/{id}/attachment/{aid}/view` ainda não existe — por enquanto
// essa URL é placeholder. O usuário pode escolher entre imagens já subidas).
$imagePickerOptions = [];
foreach ($attachments as $att) {
    if (str_starts_with((string) $att['mime'], 'image/')) {
        $imagePickerOptions[] = [
            'title' => (string) $att['filename'],
            'value' => '/teacher/cu/' . $cuId . '/attachment/' . (int) $att['id'] . '/view',
        ];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_verify();
    } catch (RuntimeException) {
        flash('danger', __t('auth.forbidden'));
        header('Location: /teacher/cu/' . $cuId . '/content/edit');
        return;
    }

    $dirtyHtml = (string) ($_POST['html']      ?? '');
    $published = isset($_POST['published']);

    $clean = HtmlPurifier::purify($dirtyHtml);

    $result = Content::upsertForCu($cuId, $tenantId, $clean, $published);
    if ($result === 'ok') {
        flash('success', __t('content.saved'));
        header('Location: /teacher/cu/' . $cuId, true, 303);
        return;
    }
    if ($result === 'course_archived') {
        flash('danger', __t('courses.edit.archived_notice'));
        header('Location: /teacher/cu/' . $cuId);
        return;
    }
    // not_found — CU sumiu no meio do fluxo
    http_response_code(404);
    require LMS_ROOT . '/src/templates/errors/404.php';
    return;
}

$courseId = (int) $cu['course_id'];
$ccId     = (int) $cu['core_competency_id'];

$page_title = __t('content.edit.title', ['name' => (string) $cu['name']]);

ob_start();
?>
<?= breadcrumbs([
    ['label' => __t('courses.index.title'), 'url' => '/teacher/courses'],
    ['label' => (string) $cu['name'],        'url' => '/teacher/cu/' . $cuId],
    ['label' => __t('content.edit.breadcrumb')],
]) ?>

<div class="row justify-content-center">
    <div class="col-12 col-lg-10">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
            <h1 class="h4 mb-0"><?= e(__t('content.edit.title', ['name' => (string) $cu['name']])) ?></h1>
            <a href="/teacher/cu/<?= $cuId ?>" class="btn btn-sm btn-outline-secondary">
                <?= e(__t('common.cancel')) ?>
            </a>
        </div>

        <form method="POST" action="/teacher/cu/<?= $cuId ?>/content/edit" class="card card-body shadow-sm mb-3" novalidate>
            <?= csrf_field() ?>

            <label for="contentHtml" class="form-label">
                <?= e(__t('content.form.body')) ?>
            </label>
            <textarea id="contentHtml" name="html" rows="20" class="form-control"><?= e($dirtyHtml) ?></textarea>
            <div class="form-text mb-3">
                <?= e(__t('content.form.body_hint')) ?>
            </div>

            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" id="fPublished"
                       name="published" value="1" <?= $published ? 'checked' : '' ?>>
                <label class="form-check-label" for="fPublished">
                    <?= e(__t('content.form.publish')) ?>
                </label>
                <div class="form-text"><?= e(__t('content.form.publish_hint')) ?></div>
            </div>

            <div class="d-flex flex-wrap gap-2">
                <button type="submit" class="btn btn-primary btn-lg">
                    <?= e(__t('common.save')) ?>
                </button>
                <a href="/teacher/cu/<?= $cuId ?>" class="btn btn-outline-secondary btn-lg">
                    <?= e(__t('common.cancel')) ?>
                </a>
            </div>
        </form>

        <!-- Seção Anexos (E5-03) -->
        <div class="card shadow-sm mb-3">
            <div class="card-header">
                <h2 class="h6 mb-0"><?= e(__t('attachments.section')) ?></h2>
                <small class="text-muted"><?= e(__t('attachments.section_hint')) ?></small>
            </div>
            <div class="card-body">
                <form method="POST" action="/teacher/cu/<?= $cuId ?>/attachment"
                      enctype="multipart/form-data" class="mb-3 d-flex gap-2 flex-wrap align-items-end" novalidate>
                    <?= csrf_field() ?>
                    <div class="flex-grow-1">
                        <label for="fAttachment" class="form-label"><?= e(__t('attachments.upload_label')) ?></label>
                        <input type="file" name="attachment" id="fAttachment" class="form-control"
                               accept=".pdf,.zip,.txt,.png,.jpg,.jpeg,.gif,.webp" required>
                        <div class="form-text"><?= e(__t('attachments.upload_hint')) ?></div>
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <?= e(__t('attachments.upload_button')) ?>
                    </button>
                </form>

                <?php if ($attachments === []): ?>
                    <p class="text-muted small mb-0"><?= e(__t('attachments.none')) ?></p>
                <?php else: ?>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($attachments as $att): ?>
                            <?php $aid = (int) $att['id']; $isImg = str_starts_with((string) $att['mime'], 'image/'); ?>
                            <li class="list-group-item d-flex align-items-center gap-2 flex-wrap">
                                <div class="flex-grow-1">
                                    <div class="fw-semibold"><?= e((string) $att['filename']) ?></div>
                                    <small class="text-muted">
                                        <?= e((string) $att['mime']) ?> ·
                                        <?= e(number_format((int) $att['size_bytes'] / 1024, 1, ',', '.')) ?> KB ·
                                        <?= e(substr((string) $att['created_at'], 0, 16)) ?>
                                        <?php if ($isImg): ?>
                                            · <span class="badge text-bg-info"><?= e(__t('attachments.image_available_in_editor')) ?></span>
                                        <?php endif; ?>
                                    </small>
                                </div>
                                <form method="POST"
                                      action="/teacher/cu/<?= $cuId ?>/attachment/<?= $aid ?>/delete"
                                      class="m-0 js-att-delete-form"
                                      data-confirm="<?= e(__t('attachments.delete_confirm', ['name' => (string) $att['filename']])) ?>">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="btn btn-sm btn-outline-danger">
                                        <?= e(__t('attachments.delete_button')) ?>
                                    </button>
                                </form>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/tinymce@6/tinymce.min.js" referrerpolicy="origin"></script>
<script>
// Resolver de URL para o plugin `media` (E5-02): só aceita YouTube e Vimeo,
// converte qualquer forma (watch, youtu.be, shorts, vimeo.com) no iframe
// canônico `/embed/` ou `player.vimeo.com/video/`. Qualquer outro provedor
// retorna string vazia → o plugin não insere iframe algum. Mesmo que passasse,
// o HtmlPurifier no backend (URI.SafeIframeRegexp) remove iframes fora dessa
// allowlist — mas aqui a UX já bloqueia na hora do paste.
function resolveVideoUrl(url) {
    var m;

    // YouTube: watch?v=ID, youtu.be/ID, /shorts/ID, /embed/ID
    m = url.match(/(?:youtube\.com\/(?:watch\?v=|embed\/|shorts\/)|youtu\.be\/)([A-Za-z0-9_-]{6,})/);
    if (m) {
        return '<iframe src="https://www.youtube.com/embed/' + m[1] +
               '" frameborder="0" allowfullscreen loading="lazy" class="content-video"></iframe>';
    }

    // Vimeo: vimeo.com/ID ou player.vimeo.com/video/ID (com opcional /h=hash)
    m = url.match(/(?:player\.vimeo\.com\/video\/|vimeo\.com\/)(\d+)/);
    if (m) {
        return '<iframe src="https://player.vimeo.com/video/' + m[1] +
               '" frameborder="0" allowfullscreen loading="lazy" class="content-video"></iframe>';
    }

    return '';
}

// Imagens disponíveis pro picker do plugin `image` (E5-03) — injetadas
// do PHP no formato {title, value}. value = URL protegida da view.
var availableImages = <?= json_encode($imagePickerOptions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

tinymce.init({
    selector: '#contentHtml',
    height: 500,
    menubar: false,
    plugins: 'lists link table code codesample autolink media image',
    toolbar: 'undo redo | blocks | bold italic underline strikethrough forecolor | ' +
             'alignleft aligncenter alignright | bullist numlist | link table image media | ' +
             'codesample code removeformat',
    block_formats: 'Parágrafo=p; Título 2=h2; Título 3=h3; Título 4=h4',
    codesample_languages: [
        { text: 'Python',     value: 'python' },
        { text: 'C#',         value: 'csharp' },
        { text: 'JavaScript', value: 'javascript' },
        { text: 'HTML/XML',   value: 'markup' },
        { text: 'CSS',        value: 'css' }
    ],
    // Plugin `image` — picker com lista das imagens já anexadas (E5-03).
    // `image_list` é nativamente aceito pelo plugin e vira dropdown
    // "Image list" no dialog de inserir imagem. URL dos itens passa pelo
    // nosso endpoint autenticado de view.
    image_list: availableImages,
    image_caption: false,
    image_description: true,
    image_dimensions: true,

    // Plugin `media` — restringe aos provedores YouTube e Vimeo.
    media_live_embeds: false,
    media_alt_source: false,
    media_poster: false,
    media_dimensions: false,
    media_url_resolver: function (data, resolve) {
        var html = resolveVideoUrl(data.source);
        resolve({ html: html });
    },
    branding: false,
    promotion: false,
    skin: 'oxide',
    content_style: 'body{font-family:system-ui,sans-serif;font-size:15px;line-height:1.6}' +
                   'table{width:100%;border-collapse:collapse}' +
                   'table td,table th{border:1px solid #dee2e6;padding:.4rem}' +
                   'iframe.content-video{width:100%;aspect-ratio:16/9;border:0}',
    mobile: { toolbar_mode: 'floating' }
});

// Confirmação do botão "Remover" de anexos (E5-03).
document.querySelectorAll('form.js-att-delete-form[data-confirm]').forEach(function (form) {
    form.addEventListener('submit', function (event) {
        if (!window.confirm(form.dataset.confirm)) {
            event.preventDefault();
        }
    });
});
</script>
<?php
$page_content = ob_get_clean();
require LMS_ROOT . '/src/templates/layout.php';
