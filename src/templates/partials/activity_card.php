<?php
declare(strict_types=1);

/**
 * Partial: ActivityCard (E14-04) — suporta atividade regular e avaliação.
 *
 * 6 estados possíveis: unavailable, pending, with-feedback, approved,
 * failed, resubmit-pending. ActivityBadge + CTA variam por estado.
 * Quando `isAssessment=true`, renderiza a description paragraph e a
 * seção-mãe trata do accent color amber→red.
 *
 * Espera no escopo:
 *   $card (array):
 *     - url           string — href do CTA + do card
 *     - type_label    string — "Projeto" / "Código" / "Avaliação"
 *     - title         string
 *     - description   ?string (só pra assessment)
 *     - xp_value      int
 *     - state         string — um dos 6 estados
 *     - progress      int — 0..100 (pro ring)
 *     - grade         ?float — quando available
 *     - cta_label     ?string — null = sem CTA (estado unavailable)
 *     - cta_variant   string — 'gradient' | 'outline'
 *     - is_assessment bool
 *     - accent        string|null — override gradient (pra avaliação)
 */

$c = $card;
$state       = (string) $c['state'];
$stateClass  = $state; // already kebab-case
$url         = (string) ($c['url'] ?? '#');
$typeLabel   = (string) ($c['type_label'] ?? '');
$title       = (string) ($c['title'] ?? '');
$description = $c['description'] ?? null;
$xpValue     = (int)    ($c['xp_value'] ?? 0);
$progress    = (int)    ($c['progress'] ?? 0);
$grade       = $c['grade'] ?? null;
$ctaLabel    = $c['cta_label'] ?? null;
$ctaVariant  = (string) ($c['cta_variant'] ?? 'gradient');
$isAssess    = (bool)   ($c['is_assessment'] ?? false);
$accent      = $c['accent'] ?? null;

$gradeText = null;
if ($grade !== null) {
    $gradeText = number_format((float) $grade, 1, current_lang() === 'pt' ? ',' : '.', '');
}
$gradeColor = null;
if ($grade !== null) {
    $gradeColor = ((float) $grade) >= 6.0 ? 'var(--lms-success-dark)' : 'var(--lms-danger-dark)';
}

$badgeLabel = __t('student.unit.state.' . $state);
$unavailable = $state === 'unavailable';
?>
<article class="lms-activity-card lms-activity-card--<?= e($stateClass) ?><?= $unavailable ? ' is-unavailable' : '' ?>"
         <?= $accent !== null ? 'style="--lms-activity-accent: ' . e($accent) . ';"' : '' ?>>
    <div class="lms-activity-card__ring-wrap">
        <div class="lms-progress-ring lms-activity-card__ring <?= $grade !== null ? 'lms-activity-card__ring--has-grade' : '' ?>"
             style="--pct: <?= (int) $progress ?>;"
             role="img"
             aria-label="<?= e(__t('student.unit.ring_aria', ['percent' => (string) $progress])) ?>">
            <?php if ($gradeText !== null): ?>
                <span class="lms-activity-card__grade" style="color: <?= e($gradeColor) ?>;">
                    <?= e($gradeText) ?>
                </span>
            <?php else: ?>
                <span class="lms-progress-ring__label"><?= (int) $progress ?>%</span>
            <?php endif; ?>
        </div>
    </div>

    <div class="lms-activity-card__body">
        <div class="lms-activity-card__meta">
            <?php if ($typeLabel !== ''): ?>
                <span class="lms-activity-card__type"><?= e($typeLabel) ?></span>
            <?php endif; ?>
            <?php if ($xpValue > 0): ?>
                <span class="lms-activity-card__xp">+<?= (int) $xpValue ?> XP</span>
            <?php endif; ?>
        </div>
        <h3 class="lms-activity-card__title"><?= e($title) ?></h3>
        <?php if ($isAssess && $description !== null && $description !== ''): ?>
            <p class="lms-activity-card__description"><?= e((string) $description) ?></p>
        <?php endif; ?>
    </div>

    <div class="lms-activity-card__actions">
        <span class="lms-status-badge lms-status-badge--<?= e($stateClass) ?>">
            <span class="lms-status-badge__dot" aria-hidden="true"></span>
            <?= e($badgeLabel) ?>
        </span>
        <?php if ($ctaLabel !== null && !$unavailable): ?>
            <a href="<?= e($url) ?>"
               class="lms-activity-card__cta lms-activity-card__cta--<?= e($ctaVariant) ?>">
                <?= e($ctaLabel) ?>
                <span aria-hidden="true">→</span>
            </a>
        <?php endif; ?>
    </div>
</article>
