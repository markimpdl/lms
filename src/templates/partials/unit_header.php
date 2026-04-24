<?php
declare(strict_types=1);

/**
 * Partial: Unit header card (E14-04).
 *
 * Card branco radius 20 com eyebrow "UNIT N · {CC}" em roxo, H1 28/800,
 * meta row (clock + carga horária, star + earned/total XP, dot + % complete)
 * e ProgressRing 84 à direita.
 *
 * Espera no escopo:
 *   $cuName     string
 *   $ccName     string
 *   $unitIndex  int
 *   $workload   int — horas
 *   $xpEarned   int
 *   $xpTotal    int
 *   $percent    int (0-100)
 */
?>
<header class="lms-unit-header">
    <div class="lms-unit-header__body">
        <span class="lms-unit-header__eyebrow">
            <?= e(__t('student.course_page.unit_n', ['n' => (string) $unitIndex])) ?>
            <span aria-hidden="true">·</span>
            <?= e($ccName) ?>
        </span>
        <h1 class="lms-unit-header__title"><?= e($cuName) ?></h1>
        <div class="lms-unit-header__meta">
            <?php if ($workload > 0): ?>
                <span class="lms-unit-header__meta-item">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <circle cx="12" cy="12" r="10"></circle>
                        <polyline points="12 6 12 12 16 14"></polyline>
                    </svg>
                    <?= (int) $workload ?>h
                </span>
            <?php endif; ?>
            <?php if ($xpTotal > 0): ?>
                <span class="lms-unit-header__meta-item lms-unit-header__meta-item--xp">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                        <path d="M12 2l2.95 6.9L22 9.6l-5.5 4.8L18.2 22 12 18.3 5.8 22l1.7-7.6L2 9.6l7.05-.7L12 2z"/>
                    </svg>
                    <?= (int) $xpEarned ?>/<?= (int) $xpTotal ?> XP
                </span>
            <?php endif; ?>
            <span class="lms-unit-header__meta-item">
                <span class="lms-unit-header__dot" aria-hidden="true"></span>
                <?= e(__t('student.unit.percent_complete', ['percent' => (string) $percent])) ?>
            </span>
        </div>
    </div>
    <div class="lms-unit-header__ring">
        <div class="lms-progress-ring lms-progress-ring--in-progress"
             style="--pct: <?= (int) $percent ?>;"
             role="progressbar"
             aria-valuenow="<?= (int) $percent ?>" aria-valuemin="0" aria-valuemax="100">
            <span class="lms-progress-ring__label"><?= (int) $percent ?>%</span>
        </div>
    </div>
</header>
