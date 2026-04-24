<?php
declare(strict_types=1);

/**
 * Partial: Hero banner da página do curso do aluno (E14-03).
 *
 * Espera no escopo:
 *   $courseName   string
 *   $firstName    string
 *   $overallPct   int (0-100)
 *   $unitsDone    int
 *   $unitsTotal   int
 *   $ccCount      int
 *   $gradStart    string (hex) — cover gradient do curso (Course::coverGradient)
 *   $gradEnd      string (hex)
 */

$gradient = sprintf('linear-gradient(135deg, %s 0%%, %s 100%%)', $gradStart, $gradEnd);
?>
<section class="lms-course-hero" style="background: <?= e($gradient) ?>;">
    <span class="lms-course-hero__circle lms-course-hero__circle--a" aria-hidden="true"></span>
    <span class="lms-course-hero__circle lms-course-hero__circle--b" aria-hidden="true"></span>

    <div class="lms-course-hero__body">
        <span class="lms-course-hero__eyebrow">
            <?= e(__t('student.my_courses.eyebrow', ['name' => $firstName])) ?>
        </span>
        <h1 class="lms-course-hero__title"><?= e($courseName) ?></h1>

        <div class="lms-course-hero__stats">
            <div class="lms-course-hero__stat">
                <span class="lms-course-hero__stat-eyebrow"><?= e(__t('student.course_page.overall')) ?></span>
                <span class="lms-course-hero__stat-value"><?= (int) $overallPct ?>%</span>
            </div>
            <div class="lms-course-hero__stat">
                <span class="lms-course-hero__stat-eyebrow"><?= e(__t('student.course_page.units')) ?></span>
                <span class="lms-course-hero__stat-value"><?= (int) $unitsDone ?>/<?= (int) $unitsTotal ?></span>
            </div>
            <div class="lms-course-hero__stat">
                <span class="lms-course-hero__stat-eyebrow"><?= e(__t('student.course_page.cc_count')) ?></span>
                <span class="lms-course-hero__stat-value"><?= (int) $ccCount ?></span>
            </div>
        </div>
    </div>

    <div class="lms-course-hero__ring-panel">
        <div class="lms-progress-ring lms-course-hero__ring"
             style="--pct: <?= (int) $overallPct ?>;"
             role="progressbar"
             aria-valuenow="<?= (int) $overallPct ?>" aria-valuemin="0" aria-valuemax="100">
            <span class="lms-progress-ring__label"><?= (int) $overallPct ?>%</span>
        </div>
    </div>
</section>
