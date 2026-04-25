<?php
declare(strict_types=1);

/**
 * Partial compartilhado pelo new.php e edit.php da avaliação (E7-01).
 *
 * Variáveis esperadas do caller:
 *   $mode           'new' | 'edit'
 *   $formAction     URL do POST
 *   $old            array com os campos do form
 *   $errors         array field → i18n key
 *   $submissions    int — quantos alunos já entregaram (só > 0 em edit)
 *   $cuId           int — CU dona da avaliação
 *   $evaluationId   int|null — só em edit
 *   $evaluationName string — título atual (edit) ou nome da CU (new)
 *   $currentPdfPath ?string — caminho relativo do PDF atual (edit; null em new)
 */
$maxMb = (int) (EvaluationBriefStorage::maxBytes() / (1024 * 1024));
?>
<div class="row justify-content-center">
    <div class="col-12 col-lg-10">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
            <h1 class="h4 mb-0">
                <?= e($mode === 'new' ? __t('evaluations.new.title') : __t('evaluations.edit.title', ['name' => $evaluationName])) ?>
            </h1>
            <a href="/teacher/cu/<?= (int) $cuId ?>" class="btn btn-sm btn-outline-secondary">
                <?= e(__t('common.cancel')) ?>
            </a>
        </div>

        <?php if ($submissions > 0): ?>
            <div class="alert alert-warning" role="alert">
                <?= e(__t('evaluations.form.warning_submissions', ['count' => (string) $submissions])) ?>
            </div>
        <?php endif; ?>

        <?php if ($errors !== []): ?>
            <div class="alert alert-danger" role="alert">
                <?= e(__t('evaluations.form.has_errors')) ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="<?= e($formAction) ?>" class="card card-body shadow-sm"
              enctype="multipart/form-data" novalidate>
            <?= csrf_field() ?>

            <div class="mb-3">
                <label for="f-title" class="form-label"><?= e(__t('evaluations.form.title')) ?></label>
                <input type="text" name="title" id="f-title"
                       class="form-control form-control-lg<?= isset($errors['title']) ? ' is-invalid' : '' ?>"
                       value="<?= e((string) $old['title']) ?>"
                       required minlength="3" maxlength="200">
                <?php if (isset($errors['title'])): ?>
                    <div class="invalid-feedback"><?= e(__t($errors['title'])) ?></div>
                <?php endif; ?>
            </div>

            <div class="row g-3">
                <div class="col-12 col-md-6 mb-3">
                    <label for="f-type" class="form-label"><?= e(__t('evaluations.form.type')) ?></label>
                    <select name="type" id="f-type"
                            class="form-select<?= isset($errors['type']) ? ' is-invalid' : '' ?>">
                        <?php foreach (Evaluation::TYPES as $t): ?>
                            <option value="<?= e($t) ?>" <?= ($old['type'] ?? 'projeto') === $t ? 'selected' : '' ?>>
                                <?= e(__t('evaluations.type.' . $t)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text"><?= e(__t('evaluations.form.type_hint')) ?></div>
                    <?php if (isset($errors['type'])): ?>
                        <div class="invalid-feedback"><?= e(__t($errors['type'])) ?></div>
                    <?php endif; ?>
                </div>
                <div class="col-12 col-md-6 mb-3">
                    <label for="f-xp" class="form-label"><?= e(__t('evaluations.form.xp')) ?></label>
                    <input type="number" name="xp_value" id="f-xp"
                           class="form-control<?= isset($errors['xp_value']) ? ' is-invalid' : '' ?>"
                           value="<?= (int) $old['xp_value'] ?>" min="0" max="9999" step="1">
                    <div class="form-text"><?= e(__t('evaluations.form.xp_hint')) ?></div>
                    <?php if (isset($errors['xp_value'])): ?>
                        <div class="invalid-feedback"><?= e(__t($errors['xp_value'])) ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="mb-3">
                <label for="f-instructions" class="form-label"><?= e(__t('evaluations.form.instructions')) ?></label>
                <textarea id="f-instructions" name="instructions" rows="10" class="form-control"><?= e((string) $old['instructions']) ?></textarea>
                <div class="form-text"><?= e(__t('evaluations.form.instructions_hint')) ?></div>
            </div>

            <div class="mb-3" id="pdf-group" <?= ($old['type'] ?? 'projeto') === 'quiz' ? 'style="display:none"' : '' ?>>
                <label for="f-pdf" class="form-label">
                    <?= e(__t('evaluations.form.pdf')) ?>
                    <?php if ($mode === 'new'): ?>
                        <span class="text-danger">*</span>
                    <?php endif; ?>
                </label>
                <?php if ($mode === 'edit' && $currentPdfPath !== null): ?>
                    <div class="mb-2 small">
                        <span class="text-muted"><?= e(__t('evaluations.form.pdf_current')) ?>:</span>
                        <code><?= e(basename($currentPdfPath)) ?></code>
                    </div>
                <?php endif; ?>
                <input type="file" name="pdf" id="f-pdf"
                       accept="application/pdf,application/zip,.pdf,.zip"
                       class="form-control<?= isset($errors['pdf']) ? ' is-invalid' : '' ?>">
                <div class="form-text">
                    <?= e(__t('evaluations.form.pdf_hint', ['mb' => (string) $maxMb])) ?>
                    <?php if ($mode === 'edit'): ?>
                        — <?= e(__t('evaluations.form.pdf_keep_hint')) ?>
                    <?php endif; ?>
                </div>
                <?php if (isset($errors['pdf'])): ?>
                    <div class="invalid-feedback"><?= e(__t($errors['pdf'])) ?></div>
                <?php endif; ?>
            </div>

            <?php if ($mode === 'edit' && ($old['type'] ?? 'projeto') === 'quiz'): ?>
                <div class="alert alert-info mb-3" role="alert">
                    <?= e(__t('evaluations.form.quiz_hint')) ?>
                    <a href="/teacher/evaluation/<?= (int) $evaluationId ?>/quiz" class="btn btn-sm btn-primary ms-2">
                        <?= e(__t('evaluations.form.quiz_edit_btn')) ?>
                    </a>
                </div>
            <?php endif; ?>

            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" id="f-submission-open"
                       name="submission_open" value="1" <?= $old['submission_open'] ? 'checked' : '' ?>>
                <label class="form-check-label" for="f-submission-open">
                    <?= e(__t('evaluations.form.submission_open')) ?>
                </label>
                <div class="form-text"><?= e(__t('evaluations.form.submission_open_hint')) ?></div>
            </div>

            <div class="d-flex flex-wrap gap-2">
                <button type="submit" class="btn btn-primary btn-lg">
                    <?= e(__t($mode === 'new' ? 'evaluations.form.submit_new' : 'common.save')) ?>
                </button>
                <a href="/teacher/cu/<?= (int) $cuId ?>" class="btn btn-outline-secondary btn-lg">
                    <?= e(__t('common.cancel')) ?>
                </a>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/tinymce@6/tinymce.min.js" referrerpolicy="origin"></script>
<script>
tinymce.init({
    selector: '#f-instructions',
    height: 320,
    menubar: false,
    plugins: 'lists link table code codesample autolink',
    toolbar: 'undo redo | blocks | bold italic underline | alignleft aligncenter alignright | ' +
             'bullist numlist | link table | codesample code removeformat',
    block_formats: 'Parágrafo=p; Título 2=h2; Título 3=h3; Título 4=h4',
    codesample_languages: [
        { text: 'Python',     value: 'python' },
        { text: 'C#',         value: 'csharp' },
        { text: 'JavaScript', value: 'javascript' },
        { text: 'HTML/XML',   value: 'markup' },
        { text: 'CSS',        value: 'css' }
    ],
    branding: false,
    promotion: false,
    convert_urls: false,
    relative_urls: false,
    content_style: 'body{font-family:system-ui,sans-serif;font-size:15px;line-height:1.6}',
    mobile: { toolbar_mode: 'floating' }
});

// E20-02: alterna visibilidade do bloco de upload de PDF conforme o type.
(function () {
    var sel = document.getElementById('f-type');
    var pdf = document.getElementById('pdf-group');
    var pdfInput = document.getElementById('f-pdf');
    if (!sel || !pdf) return;
    sel.addEventListener('change', function () {
        var isQuiz = sel.value === 'quiz';
        pdf.style.display = isQuiz ? 'none' : '';
        if (pdfInput) pdfInput.required = !isQuiz;
    });
})();
</script>
