<?php
declare(strict_types=1);

/**
 * Partial CourseCard (E14-02).
 *
 * Consumido em /student (dashboard). Espera no escopo:
 *   $course array com:
 *     - course_id, course_name, course_archived
 *     - enrolled_at, last_access_at
 *     - instructor_name
 *     - units_total, total_hours
 *     - status ('not_started'|'in_progress'|'completed'), percent (0-100), units_done
 *     - grad_start, grad_end (hex pro cover — Course::coverGradient)
 */

$c         = $course;
$courseId  = (int)    ($c['course_id']        ?? 0);
$name      = (string) ($c['course_name']      ?? '');
$archived  = (int)    ($c['course_archived']  ?? 0) === 1;
$instr     = (string) ($c['instructor_name']  ?? '');
$status    = (string) ($c['status']           ?? 'not_started');
$percent   = (int)    ($c['percent']          ?? 0);
$unitsDone = (int)    ($c['units_done']       ?? 0);
$unitsTot  = (int)    ($c['units_total']      ?? 0);
$hours     = (int)    ($c['total_hours']      ?? 0);
$lastAcc   = (string) ($c['last_access_at']   ?? '');
$gradFrom  = (string) ($c['grad_start']       ?? '#6366F1');
$gradTo    = (string) ($c['grad_end']         ?? '#EC4899');

$gradient  = sprintf('linear-gradient(135deg, %s, %s)', $gradFrom, $gradTo);

// Disponibilidade do curso (E17-03). Pure logic sobre as 3 colunas que já
// vieram do listForStudentWithProgress — zero query extra. Card desabilitado
// quando blocked_at, NOW < access_starts_at ou NOW > access_ends_at.
$availability = enrollment_availability(
    $c['access_starts_at'] ?? null,
    $c['access_ends_at']   ?? null,
    $c['blocked_at']       ?? null
);
$isUnavailable = !$availability['available'];
$unavailReason = (string) ($availability['reason']  ?? '');
$unavailMsg    = (string) ($availability['message'] ?? '');

// Mapa de status → classe + label i18n + CTA
$statusClass = str_replace('_', '-', $status);
$statusLabel = __t('status.course.' . $status);
$ctaKey = match ($status) {
    'completed'   => 'student.course_card.cta.review',
    'in_progress' => 'student.course_card.cta.resume',
    default       => 'student.course_card.cta.access',
};
$ctaLabel = __t($ctaKey);

$lastAccessText = $lastAcc !== ''
    ? __t('student.course_card.last_access', ['date' => format_short_date($lastAcc)])
    : __t('student.course_card.last_access_none');
?>
<article class="lms-course-card lms-course-card--<?= e($statusClass) ?> <?= $isUnavailable ? 'lms-course-card--unavailable' : '' ?>"
         data-status="<?= e($status) ?>"
         x-show="filter === 'all'
                  || (filter === 'active'    && ['not_started','in_progress'].includes('<?= e($status) ?>'))
                  || (filter === 'completed' && '<?= e($status) ?>' === 'completed')"
         x-transition.opacity>
    <div class="lms-course-card__cover" style="background: <?= e($gradient) ?>;" aria-hidden="true">
        <span class="lms-course-card__cover-circle lms-course-card__cover-circle--a"></span>
        <span class="lms-course-card__cover-circle lms-course-card__cover-circle--b"></span>
    </div>
    <div class="lms-course-card__body">
        <span class="lms-status-badge lms-status-badge--<?= e($statusClass) ?>">
            <span class="lms-status-badge__dot" aria-hidden="true"></span>
            <?= e($statusLabel) ?>
        </span>

        <h3 class="lms-course-card__title"><?= e($name) ?></h3>

        <?php if ($instr !== ''): ?>
            <div class="lms-course-card__instructor">
                <?= e(__t('student.course_card.instructor')) ?>
                <strong><?= e($instr) ?></strong>
            </div>
        <?php endif; ?>

        <div class="lms-course-card__progress">
            <div class="lms-progress-ring lms-progress-ring--<?= e($statusClass) ?>"
                 style="--pct: <?= (int) $percent ?>;"
                 role="progressbar"
                 aria-valuenow="<?= (int) $percent ?>" aria-valuemin="0" aria-valuemax="100">
                <span class="lms-progress-ring__label"><?= (int) $percent ?>%</span>
            </div>
            <div class="lms-course-card__progress-meta">
                <div class="lms-course-card__units">
                    <?= e(__t('student.course_card.units_count', [
                        'done'  => (string) $unitsDone,
                        'total' => (string) $unitsTot,
                    ])) ?>
                </div>
                <?php if ($hours > 0): ?>
                    <div class="lms-course-card__hours">
                        <?= e(__t('student.course_card.total_hours', ['hours' => (string) $hours])) ?>
                    </div>
                <?php endif; ?>
                <div class="lms-course-card__bar" aria-hidden="true">
                    <div class="lms-course-card__bar-fill"
                         style="width: <?= (int) $percent ?>%; background: <?= $status === 'completed' ? 'var(--lms-success)' : e($gradient) ?>;"></div>
                </div>
            </div>
        </div>

        <?php if ($isUnavailable): ?>
            <div class="lms-course-card__unavailable" role="status">
                <span class="lms-course-card__unavailable-badge"><?= e(__t('enrollment.unavailable.badge')) ?></span>
                <p class="lms-course-card__unavailable-msg"><?= e($unavailMsg) ?></p>
            </div>
        <?php else: ?>
            <div class="lms-course-card__footer">
                <span class="lms-course-card__last-access"><?= e($lastAccessText) ?></span>
                <a href="/student/course/<?= (int) $courseId ?>"
                   class="lms-course-card__cta lms-course-card__cta--<?= e($statusClass) ?>"
                   style="<?= $status === 'completed' ? '' : 'background: ' . e($gradient) . ';' ?>">
                    <?= e($ctaLabel) ?>
                    <span aria-hidden="true">→</span>
                </a>
            </div>
        <?php endif; ?>
    </div>
</article>
