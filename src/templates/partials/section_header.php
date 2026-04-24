<?php
declare(strict_types=1);

/**
 * Partial: SectionHeader (E14-04) — accent bar 6×22 + H2 + optional count.
 *
 * Espera no escopo:
 *   $title         string
 *   $count         int|null — badge opcional "(N)"
 *   $accent        string|null — gradient inline pra barra (default primary)
 */

$bar = $accent ?? 'var(--lms-gradient-primary)';
?>
<header class="lms-section-header">
    <span class="lms-section-header__bar" style="background: <?= e($bar) ?>;" aria-hidden="true"></span>
    <h2 class="lms-section-header__title"><?= e($title) ?></h2>
    <?php if (($count ?? null) !== null): ?>
        <span class="lms-section-header__count"><?= (int) $count ?></span>
    <?php endif; ?>
</header>
