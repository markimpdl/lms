<?php
/**
 * Partial: campos de modos de progressão do curso (E19-01) +
 * grading_mode (E25-01, condicional).
 *
 * Espera variáveis no escopo do caller:
 *   $old      array com keys 'cc_mode', 'activity_mode',
 *             'eval_after_activities', e 'grading_mode' (opcional)
 *   $errors   array (pode estar vazio)
 *   $isActvet bool (opcional, default false) — controla visibilidade do
 *             select grading_mode
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

    <?php if (!empty($isActvet)): ?>
        <div class="mt-3">
            <label for="f-grading-mode" class="form-label">
                <?= e(__t('courses.form.grading_mode.label')) ?>
            </label>
            <select name="grading_mode" id="f-grading-mode" class="form-select">
                <option value="grade" <?= ($old['grading_mode'] ?? 'learning_outcomes') === 'grade' ? 'selected' : '' ?>>
                    <?= e(__t('courses.form.grading_mode.option.grade')) ?>
                </option>
                <option value="learning_outcomes" <?= ($old['grading_mode'] ?? 'learning_outcomes') === 'learning_outcomes' ? 'selected' : '' ?>>
                    <?= e(__t('courses.form.grading_mode.option.learning_outcomes')) ?>
                </option>
            </select>
            <div class="form-text"><?= e(__t('courses.form.grading_mode.help')) ?></div>
        </div>
    <?php endif; ?>
</fieldset>
