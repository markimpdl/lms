<?php
/**
 * Partial: 3 campos de modos de progressão do curso (E19-01).
 *
 * Espera variáveis no escopo do caller:
 *   $old    array com keys 'cc_mode' (string), 'activity_mode' (string),
 *           'eval_after_activities' (int 0/1)
 *   $errors array (pode estar vazio) — checa keys 'cc_mode' e 'activity_mode'
 */
?>
<fieldset class="border rounded p-3 mb-3">
    <legend class="form-label fw-semibold fs-6 px-2 mb-2 w-auto float-none">
        <?= e(__t('courses.form.progression.title')) ?>
    </legend>
    <p class="text-muted small mb-3"><?= e(__t('courses.form.progression.help')) ?></p>

    <div class="row g-3">
        <div class="col-12 col-md-6">
            <label for="f-cc-mode" class="form-label">
                <?= e(__t('courses.form.cc_mode.label')) ?>
            </label>
            <select name="cc_mode" id="f-cc-mode"
                    class="form-select<?= isset($errors['cc_mode']) ? ' is-invalid' : '' ?>">
                <option value="sequential" <?= ($old['cc_mode'] ?? 'sequential') === 'sequential' ? 'selected' : '' ?>>
                    <?= e(__t('courses.form.mode.sequential')) ?>
                </option>
                <option value="free" <?= ($old['cc_mode'] ?? '') === 'free' ? 'selected' : '' ?>>
                    <?= e(__t('courses.form.mode.free')) ?>
                </option>
            </select>
            <div class="form-text"><?= e(__t('courses.form.cc_mode.help')) ?></div>
            <?php if (isset($errors['cc_mode'])): ?>
                <div class="invalid-feedback d-block"><?= e(__t($errors['cc_mode'])) ?></div>
            <?php endif; ?>
        </div>

        <div class="col-12 col-md-6">
            <label for="f-activity-mode" class="form-label">
                <?= e(__t('courses.form.activity_mode.label')) ?>
            </label>
            <select name="activity_mode" id="f-activity-mode"
                    class="form-select<?= isset($errors['activity_mode']) ? ' is-invalid' : '' ?>">
                <option value="sequential" <?= ($old['activity_mode'] ?? 'sequential') === 'sequential' ? 'selected' : '' ?>>
                    <?= e(__t('courses.form.mode.sequential')) ?>
                </option>
                <option value="free" <?= ($old['activity_mode'] ?? '') === 'free' ? 'selected' : '' ?>>
                    <?= e(__t('courses.form.mode.free')) ?>
                </option>
            </select>
            <div class="form-text"><?= e(__t('courses.form.activity_mode.help')) ?></div>
            <?php if (isset($errors['activity_mode'])): ?>
                <div class="invalid-feedback d-block"><?= e(__t($errors['activity_mode'])) ?></div>
            <?php endif; ?>
        </div>
    </div>

    <div class="form-check mt-3">
        <input type="checkbox" name="eval_after_activities" id="f-eval-after"
               value="1" class="form-check-input"
               <?= !empty($old['eval_after_activities']) ? 'checked' : '' ?>>
        <label for="f-eval-after" class="form-check-label">
            <?= e(__t('courses.form.eval_after_activities.label')) ?>
        </label>
        <div class="form-text"><?= e(__t('courses.form.eval_after_activities.help')) ?></div>
    </div>
</fieldset>
