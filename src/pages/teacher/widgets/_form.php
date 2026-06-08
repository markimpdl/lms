<?php
declare(strict_types=1);

/**
 * Form de widget (E35 / F26) — partial compartilhado por new.php e edit.php.
 *
 * Espera:
 *   $formAction  string
 *   $old         array{name,render_mode,icon,width_hint,height_hint}
 *   $errors      array<string,string>  (chaves i18n por campo)
 *   $zipRequired bool                  (true em new; false em edit = re-upload opcional)
 *   $submitLabel string
 *   $maxMb       int
 */

$err = static fn (string $k): string => isset($errors[$k]) ? '<div class="invalid-feedback d-block">' . e(__t($errors[$k])) . '</div>' : '';
?>
<form method="POST" action="<?= e($formAction) ?>" enctype="multipart/form-data" class="card card-body shadow-sm" novalidate>
    <?= csrf_field() ?>

    <div class="mb-3">
        <label for="wName" class="form-label"><?= e(__t('widgets.field.name')) ?></label>
        <input type="text" id="wName" name="name" maxlength="120" required
               class="form-control <?= isset($errors['name']) ? 'is-invalid' : '' ?>"
               value="<?= e((string) $old['name']) ?>">
        <?= $err('name') ?>
    </div>

    <div class="mb-3">
        <label class="form-label d-block"><?= e(__t('widgets.field.render_mode')) ?></label>
        <div class="form-check">
            <input class="form-check-input" type="radio" name="render_mode" id="wInline" value="inline"
                   <?= (string) $old['render_mode'] !== 'window' ? 'checked' : '' ?>>
            <label class="form-check-label" for="wInline">
                <?= e(__t('widgets.mode.inline')) ?> — <span class="text-muted small"><?= e(__t('widgets.mode.inline_hint')) ?></span>
            </label>
        </div>
        <div class="form-check">
            <input class="form-check-input" type="radio" name="render_mode" id="wWindow" value="window"
                   <?= (string) $old['render_mode'] === 'window' ? 'checked' : '' ?>>
            <label class="form-check-label" for="wWindow">
                <?= e(__t('widgets.mode.window')) ?> — <span class="text-muted small"><?= e(__t('widgets.mode.window_hint')) ?></span>
            </label>
        </div>
    </div>

    <div class="mb-3">
        <label for="wZip" class="form-label">
            <?= e(__t('widgets.field.zip')) ?>
            <?php if (!$zipRequired): ?><span class="text-muted small">(<?= e(__t('widgets.field.zip_optional')) ?>)</span><?php endif; ?>
        </label>
        <input type="file" id="wZip" name="zip" accept=".zip"
               class="form-control <?= isset($errors['zip']) ? 'is-invalid' : '' ?>" <?= $zipRequired ? 'required' : '' ?>>
        <div class="form-text"><?= e(__t('widgets.field.zip_hint', ['mb' => (string) $maxMb])) ?></div>
        <?= $err('zip') ?>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-12 col-sm-6">
            <label for="wIcon" class="form-label"><?= e(__t('widgets.field.icon')) ?></label>
            <input type="text" id="wIcon" name="icon" maxlength="255"
                   class="form-control" placeholder="icon.png"
                   value="<?= e((string) ($old['icon'] ?? '')) ?>">
            <div class="form-text"><?= e(__t('widgets.field.icon_hint')) ?></div>
        </div>
        <div class="col-6 col-sm-3">
            <label for="wH" class="form-label"><?= e(__t('widgets.field.height')) ?></label>
            <input type="number" id="wH" name="height_hint" min="100" max="2000"
                   class="form-control" value="<?= $old['height_hint'] !== null ? (int) $old['height_hint'] : '' ?>">
            <div class="form-text"><?= e(__t('widgets.field.height_hint')) ?></div>
        </div>
    </div>

    <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary"><?= e($submitLabel) ?></button>
        <a href="/teacher/widgets" class="btn btn-outline-secondary"><?= e(__t('common.cancel')) ?></a>
    </div>
</form>
