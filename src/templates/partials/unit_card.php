<?php
declare(strict_types=1);

/**
 * Partial: UnitCard — card de CU dentro da grid de uma CC (E14-03).
 *
 * Espera no escopo:
 *   $unit       array com id, name, workload_hours, xp_total
 *   $unitIndex  int — numeração 1..N dentro da CC (pra eyebrow "UNIT N")
 *   $studentId  int — pra calcular status
 *   $gradStart  string (hex) — gradient do ícone
 *   $gradEnd    string (hex)
 */

$cuId    = (int) ($unit['id'] ?? 0);
$cuName  = (string) ($unit['name'] ?? '');
$hours   = (int) ($unit['workload_hours'] ?? 0);
$xpTotal = (int) ($unit['xp_total'] ?? 0);

$s = student_cu_status($cuId, $studentId);
$status  = (string) $s['status'];
$percent = (int)    $s['percent'];
$statusClass = str_replace('_', '-', $status);

$iconGradient = sprintf('linear-gradient(135deg, %s, %s)', $gradStart, $gradEnd);
?>
<a class="lms-unit-card lms-unit-card--<?= e($statusClass) ?>" href="/student/cu/<?= (int) $cuId ?>">
    <div class="lms-unit-card__top">
        <div class="lms-unit-card__icon" style="background: <?= e($iconGradient) ?>;" aria-hidden="true">
            <?= (int) $unitIndex ?>
        </div>
        <div class="lms-progress-ring lms-progress-ring--<?= e($statusClass) ?> lms-unit-card__ring"
             style="--pct: <?= (int) $percent ?>;"
             role="progressbar"
             aria-valuenow="<?= (int) $percent ?>" aria-valuemin="0" aria-valuemax="100">
            <span class="lms-progress-ring__label"><?= (int) $percent ?>%</span>
        </div>
    </div>

    <div class="lms-unit-card__body">
        <span class="lms-unit-card__eyebrow">
            <?= e(__t('student.course_page.unit_n', ['n' => (string) $unitIndex])) ?>
        </span>
        <h3 class="lms-unit-card__title"><?= e($cuName) ?></h3>
    </div>

    <footer class="lms-unit-card__footer">
        <span class="lms-status-badge lms-status-badge--<?= e($statusClass) ?>">
            <span class="lms-status-badge__dot" aria-hidden="true"></span>
            <?= e(__t('status.cu.' . $status)) ?>
        </span>
        <span class="lms-unit-card__meta">
            <?php if ($hours > 0): ?>
                <span title="<?= e(__t('student.course_page.unit_hours_tooltip')) ?>">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <circle cx="12" cy="12" r="10"></circle>
                        <polyline points="12 6 12 12 16 14"></polyline>
                    </svg>
                    <?= (int) $hours ?>h
                </span>
            <?php endif; ?>
            <?php if ($xpTotal > 0): ?>
                <span class="lms-unit-card__xp">+<?= (int) $xpTotal ?> XP</span>
            <?php endif; ?>
        </span>
    </footer>
</a>
