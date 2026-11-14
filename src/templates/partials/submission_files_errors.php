<?php
declare(strict_types=1);

/**
 * Alerta de erros do form de entrega (atividade + avaliação). As chaves de
 * limite usam :max / :count; quando um arquivo específico falhou
 * (SubmissionFiles::storeAll), mostra o nome dele.
 *
 * Variáveis: $errors (list<string> de chaves i18n), $errorFileName (?string).
 */
?>
<?php if ($errors !== []): ?>
    <div class="alert alert-danger" role="alert">
        <?php foreach ($errors as $__ek): ?>
            <div>
                <?= e(__t($__ek, [
                    'max'   => SubmissionFiles::humanSize(SubmissionFiles::MAX_TOTAL_BYTES),
                    'count' => SubmissionFiles::MAX_FILES,
                ])) ?>
                <?php if (($errorFileName ?? null) !== null): ?>
                    <small>(<?= e((string) $errorFileName) ?>)</small>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
