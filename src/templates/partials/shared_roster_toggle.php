<?php
declare(strict_types=1);

/**
 * Switch "ver todos os alunos" do curso compartilhado (F25/E34 — ADR-036).
 *
 * Renderize APENAS em curso compartilhado (o caller decide via
 * CourseCollaborator::isShared). Preferência fixa por professor: ON = ver todos
 * os alunos do curso; OFF (default) = só os meus. Afeta só leitura agregada.
 *
 * Espera:
 *   $__sharedToggleReturn  string  caminho interno de retorno após alternar.
 */

$__on  = teacher_shows_all_shared_students();
$__ret = $__sharedToggleReturn ?? '/teacher';
?>
<form method="POST" action="/teacher/preferences/shared-roster" class="m-0">
    <?= csrf_field() ?>
    <input type="hidden" name="return" value="<?= e($__ret) ?>">
    <div class="form-check form-switch m-0" title="<?= e(__t('shared_roster.toggle_hint')) ?>">
        <input class="form-check-input" type="checkbox" role="switch" id="sharedRosterToggle"
               name="show_all" value="1" <?= $__on ? 'checked' : '' ?>
               onchange="this.form.submit()">
        <label class="form-check-label small" for="sharedRosterToggle">
            <?= e(__t('shared_roster.toggle_label')) ?>
        </label>
    </div>
</form>
