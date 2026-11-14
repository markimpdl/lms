<?php
declare(strict_types=1);

/**
 * Campo de arquivos de uma entrega (atividade ou avaliação) — multi-arquivo.
 *
 * Variáveis esperadas (setadas pelo caller antes do require):
 *   $fileFieldLabel        string  rótulo do campo
 *   $fileFieldHint         string  dica de extensões aceitas
 *   $fileFieldAccept       string  atributo accept do input
 *   $fileFieldError        bool    marca o input como inválido
 *   $fileFieldRequired     bool    exige ao menos 1 arquivo (avaliação)
 *   $fileFieldExisting     list<array{id:int,filename:string,size_bytes:int}>
 *                                  arquivos já enviados (com checkbox remover)
 *   $fileFieldDownloadBase string  URL do download (+ ?f=<id> quando id > 0)
 *
 * O JS só AVISA (soma e quantidade em tempo real, bloqueia o envio acima do
 * teto pra não subir 10 MB à toa). A regra de verdade é do servidor:
 * SubmissionFiles::checkLimits.
 */

$__ffMaxBytes = SubmissionFiles::MAX_TOTAL_BYTES;
$__ffMaxFiles = SubmissionFiles::MAX_FILES;
$__ffId       = 'f-files-' . bin2hex(random_bytes(3));
?>
<div class="mb-3 lms-files-field" data-max-bytes="<?= $__ffMaxBytes ?>" data-max-files="<?= $__ffMaxFiles ?>">
    <label for="<?= e($__ffId) ?>" class="form-label"><?= e($fileFieldLabel) ?></label>

    <?php if ($fileFieldExisting !== []): ?>
        <ul class="list-group mb-2">
            <?php foreach ($fileFieldExisting as $__ff): ?>
                <li class="list-group-item d-flex align-items-center justify-content-between gap-2 flex-wrap py-2">
                    <span class="text-break">
                        <a href="<?= e($fileFieldDownloadBase . ($__ff['id'] > 0 ? '?f=' . $__ff['id'] : '')) ?>"><?= e($__ff['filename']) ?></a>
                        <small class="text-muted">· <?= e(SubmissionFiles::humanSize($__ff['size_bytes'])) ?></small>
                    </span>
                    <label class="form-check-label small d-flex align-items-center gap-1 mb-0">
                        <input type="checkbox" class="form-check-input mt-0 lms-files-remove"
                               name="remove_files[]" value="<?= (int) $__ff['id'] ?>"
                               data-size="<?= (int) $__ff['size_bytes'] ?>">
                        <?= e(__t('submissions.form.remove_file')) ?>
                    </label>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <input type="file" name="files[]" id="<?= e($__ffId) ?>" multiple
           class="form-control lms-files-input<?= $fileFieldError ? ' is-invalid' : '' ?>"
           accept="<?= e($fileFieldAccept) ?>"<?= $fileFieldRequired ? ' required' : '' ?>>
    <div class="form-text">
        <?= e($fileFieldHint) ?>
        · <?= e(__t('submissions.form.files_limit', [
            'count' => $__ffMaxFiles,
            'max'   => SubmissionFiles::humanSize($__ffMaxBytes),
        ])) ?>
    </div>
    <div class="small mt-1 lms-files-total" aria-live="polite"
         data-label="<?= e(__t('submissions.form.files_total')) ?>"
         data-over-size="<?= e(__t('submissions.err.total_size', ['max' => SubmissionFiles::humanSize($__ffMaxBytes)])) ?>"
         data-over-count="<?= e(__t('submissions.err.too_many', ['count' => $__ffMaxFiles])) ?>"></div>
</div>
<script>
(function () {
    var root = document.getElementById(<?= json_encode($__ffId) ?>).closest('.lms-files-field');
    var input = root.querySelector('.lms-files-input');
    var out = root.querySelector('.lms-files-total');
    var form = root.closest('form');
    var maxBytes = +root.dataset.maxBytes, maxFiles = +root.dataset.maxFiles;

    function fmt(b) {
        return b >= 1048576 ? (b % 1048576 === 0 ? String(b / 1048576) : (b / 1048576).toFixed(1).replace('.', ',')) + ' MB'
                            : Math.max(1, Math.round(b / 1024)) + ' KB';
    }
    function state() {
        var bytes = 0, count = 0;
        root.querySelectorAll('.lms-files-remove').forEach(function (cb) {
            if (!cb.checked) { bytes += +cb.dataset.size; count++; }
        });
        Array.prototype.forEach.call(input.files || [], function (f) { bytes += f.size; count++; });
        return { bytes: bytes, count: count };
    }
    function render() {
        var s = state();
        var err = s.count > maxFiles ? out.dataset.overCount
                : (s.bytes > maxBytes ? out.dataset.overSize : '');
        out.textContent = s.count === 0 ? '' : out.dataset.label + ': ' + s.count + ' · ' + fmt(s.bytes) + ' / ' + fmt(maxBytes)
            + (err ? ' — ' + err : '');
        out.className = 'small mt-1 lms-files-total ' + (err ? 'text-danger fw-semibold' : 'text-muted');
        input.classList.toggle('is-invalid', !!err);
        return !err;
    }
    input.addEventListener('change', render);
    root.querySelectorAll('.lms-files-remove').forEach(function (cb) { cb.addEventListener('change', render); });
    if (form) {
        form.addEventListener('submit', function (ev) {
            // Botão com formaction (ex.: remover entrega) não passa pelo limite.
            if (ev.submitter && ev.submitter.hasAttribute('formaction')) { return; }
            if (!render()) { ev.preventDefault(); out.scrollIntoView({ block: 'center' }); }
        });
    }
    render();
})();
</script>
