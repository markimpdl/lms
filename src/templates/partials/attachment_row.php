<?php
declare(strict_types=1);

/**
 * Partial: AttachmentRow (E14-04) — uma linha de anexo com FileTypeChip,
 * filename + meta (tipo/tamanho) e botão download.
 *
 * Espera no escopo:
 *   $attachment (array):
 *     - filename   string
 *     - mime       string
 *     - size_bytes int
 *     - url        string — download URL autenticada
 */

$a        = $attachment;
$filename = (string) ($a['filename'] ?? '');
$mime     = (string) ($a['mime'] ?? '');
$size     = (int)    ($a['size_bytes'] ?? 0);
$url      = (string) ($a['url'] ?? '#');

// Deriva tipo (4 chars uppercase) + cor a partir do mime/extensão.
$ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
$typeLabel = strtoupper(substr($ext !== '' ? $ext : ($mime !== '' ? explode('/', $mime)[1] ?? '' : ''), 0, 4));

$typeColor = '#EDE9FE'; // default violet tint (md, txt, others)
$typeFg    = '#5B21B6';
if (in_array($ext, ['pdf'], true)) {
    $typeColor = '#FEE2E2'; $typeFg = '#B91C1C';
} elseif (in_array($ext, ['zip', 'rar', '7z'], true)) {
    $typeColor = '#FEF3C7'; $typeFg = '#92400E';
} elseif (in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp'], true)) {
    $typeColor = '#DBEAFE'; $typeFg = '#1E40AF';
}

// Tamanho humano
if ($size > 1024 * 1024) {
    $sizeLabel = number_format($size / (1024 * 1024), 1, current_lang() === 'pt' ? ',' : '.', '') . ' MB';
} elseif ($size > 1024) {
    $sizeLabel = number_format($size / 1024, 1, current_lang() === 'pt' ? ',' : '.', '') . ' KB';
} else {
    $sizeLabel = $size . ' B';
}

$metaLabel = ($typeLabel !== '' ? $typeLabel : __t('student.unit.attachments.file'))
    . ' · ' . $sizeLabel;
?>
<div class="lms-attachment-row">
    <a class="lms-attachment-row__link" href="<?= e($url) ?>">
        <span class="lms-file-chip" style="background: <?= e($typeColor) ?>; color: <?= e($typeFg) ?>;" aria-hidden="true">
            <?= e($typeLabel !== '' ? $typeLabel : '?') ?>
        </span>
        <span class="lms-attachment-row__info">
            <span class="lms-attachment-row__name"><?= e($filename) ?></span>
            <span class="lms-attachment-row__meta"><?= e($metaLabel) ?></span>
        </span>
    </a>
    <a class="lms-attachment-row__btn" href="<?= e($url) ?>" aria-label="<?= e(__t('student.content.download')) ?>">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
            <polyline points="7 10 12 15 17 10"></polyline>
            <line x1="12" y1="15" x2="12" y2="3"></line>
        </svg>
        <?= e(__t('student.content.download')) ?>
    </a>
</div>
