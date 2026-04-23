<?php
declare(strict_types=1);

/**
 * Partial compartilhado pelo new.php e edit.php da atividade (E6-01).
 *
 * Variáveis esperadas do caller:
 *   $mode         'new' | 'edit'
 *   $formAction   URL do POST
 *   $old          array com os campos do form
 *   $errors       array field → i18n key
 *   $submissions  int — quantos alunos já entregaram (só > 0 em edit)
 *   $cuId         int — CU dona da atividade
 *   $activityId   int|null — só em edit
 *   $activityName string — título atual (edit) ou nome da CU (new)
 */
?>
<div class="row justify-content-center">
    <div class="col-12 col-lg-10">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
            <h1 class="h4 mb-0">
                <?= e($mode === 'new' ? __t('activities.new.title') : __t('activities.edit.title', ['name' => $activityName])) ?>
            </h1>
            <a href="/teacher/cu/<?= (int) $cuId ?>" class="btn btn-sm btn-outline-secondary">
                <?= e(__t('common.cancel')) ?>
            </a>
        </div>

        <?php if ($submissions > 0): ?>
            <div class="alert alert-warning" role="alert">
                <?= e(__t('activities.form.warning_submissions', ['count' => (string) $submissions])) ?>
            </div>
        <?php endif; ?>

        <?php if ($errors !== []): ?>
            <div class="alert alert-danger" role="alert">
                <?= e(__t('activities.form.has_errors')) ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="<?= e($formAction) ?>" class="card card-body shadow-sm" novalidate>
            <?= csrf_field() ?>

            <div class="mb-3">
                <label for="f-title" class="form-label"><?= e(__t('activities.form.title')) ?></label>
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
                    <label for="f-type" class="form-label"><?= e(__t('activities.form.type')) ?></label>
                    <select name="type" id="f-type"
                            class="form-select<?= isset($errors['type']) ? ' is-invalid' : '' ?>">
                        <?php foreach (Activity::TYPES as $t): ?>
                            <option value="<?= e($t) ?>" <?= $old['type'] === $t ? 'selected' : '' ?>>
                                <?= e(__t('activities.type.' . $t)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (isset($errors['type'])): ?>
                        <div class="invalid-feedback"><?= e(__t($errors['type'])) ?></div>
                    <?php endif; ?>
                </div>
                <div class="col-12 col-md-6 mb-3">
                    <label for="f-xp" class="form-label"><?= e(__t('activities.form.xp')) ?></label>
                    <input type="number" name="xp_value" id="f-xp"
                           class="form-control<?= isset($errors['xp_value']) ? ' is-invalid' : '' ?>"
                           value="<?= (int) $old['xp_value'] ?>" min="0" max="9999" step="1">
                    <div class="form-text"><?= e(__t('activities.form.xp_hint')) ?></div>
                    <?php if (isset($errors['xp_value'])): ?>
                        <div class="invalid-feedback"><?= e(__t($errors['xp_value'])) ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="mb-3">
                <label for="f-instruction" class="form-label"><?= e(__t('activities.form.instruction')) ?></label>
                <textarea id="f-instruction" name="instruction" rows="12" class="form-control"><?= e((string) $old['instruction']) ?></textarea>
                <div class="form-text"><?= e(__t('activities.form.instruction_hint')) ?></div>
            </div>

            <div class="form-check mb-2">
                <input class="form-check-input" type="checkbox" id="f-submission-open"
                       name="submission_open" value="1" <?= $old['submission_open'] ? 'checked' : '' ?>>
                <label class="form-check-label" for="f-submission-open">
                    <?= e(__t('activities.form.submission_open')) ?>
                </label>
                <div class="form-text"><?= e(__t('activities.form.submission_open_hint')) ?></div>
            </div>

            <div class="form-check mb-3" id="code-run-group">
                <input class="form-check-input" type="checkbox" id="f-allow-code-run"
                       name="allow_online_code_run" value="1"
                       <?= $old['allow_online_code_run'] ? 'checked' : '' ?>
                       <?= $old['type'] !== 'codigo' ? 'disabled' : '' ?>>
                <label class="form-check-label" for="f-allow-code-run">
                    <?= e(__t('activities.form.allow_code_run')) ?>
                    <span class="badge text-bg-light border ms-1"><?= e(__t('common.soon')) ?></span>
                </label>
                <div class="form-text"><?= e(__t('activities.form.allow_code_run_hint')) ?></div>
            </div>

            <div class="d-flex flex-wrap gap-2">
                <button type="submit" class="btn btn-primary btn-lg">
                    <?= e(__t($mode === 'new' ? 'activities.form.submit_new' : 'common.save')) ?>
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
    selector: '#f-instruction',
    height: 400,
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

// Habilita/desabilita o toggle "executar código" conforme o tipo escolhido.
(function () {
    var sel = document.getElementById('f-type');
    var chk = document.getElementById('f-allow-code-run');
    if (!sel || !chk) return;
    sel.addEventListener('change', function () {
        var enabled = sel.value === 'codigo';
        chk.disabled = !enabled;
        if (!enabled) chk.checked = false;
    });
})();
</script>
