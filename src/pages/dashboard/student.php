<?php
declare(strict_types=1);

/**
 * /student — "Meus cursos" (E14-02, rewrite).
 *
 * Header com eyebrow + H1 + contador + filter pills; grid de CourseCards
 * responsivo (auto-fill minmax 300). Status/percent calculados on-the-fly
 * via `StudentProgress`; cover gradient derivado do `course.id` por hash
 * (Course::coverGradient).
 */

// Auth + papel garantidos pelo front controller.
$user      = current_user();
$studentId = (int) $user['id'];

$courses = Course::listForStudentWithProgress($studentId);

// Enriquecimento on-the-fly: status, percent, units_done, cover gradient.
$total = 0;
$active = 0;
$completed = 0;
foreach ($courses as &$c) {
    $courseId = (int) $c['course_id'];
    $s = student_course_status($courseId, $studentId);
    $c['status']  = $s['status'];
    $c['percent'] = (int) $s['percent'];

    // units_done = round(units_total * percent/100) — aproximação suficiente
    // pro card; percent já vem da fórmula em `StudentProgress::courseStatus`.
    $unitsTotal = (int) $c['units_total'];
    $c['units_done'] = $unitsTotal > 0
        ? (int) round(($c['percent'] / 100) * $unitsTotal)
        : 0;

    [$c['grad_start'], $c['grad_end']] = Course::coverGradient($courseId);

    $total++;
    if ($c['status'] === 'completed') {
        $completed++;
    } else {
        $active++;
    }
}
unset($c);

$firstName = explode(' ', trim((string) ($user['name'] ?? '')))[0] ?? '';

$page_title = __t('dashboard.student.title');

ob_start();
?>
<div x-data="{ filter: 'all' }">
    <header class="lms-dashboard-header">
        <div>
            <span class="lms-dashboard-eyebrow">
                <?= e(__t('student.my_courses.eyebrow', ['name' => $firstName])) ?>
            </span>
            <h1 class="lms-dashboard-title"><?= e(__t('dashboard.student.title')) ?></h1>
            <?php if ($total > 0): ?>
                <p class="lms-dashboard-subtitle">
                    <?= e(__t('student.my_courses.subtitle', [
                        'total'     => (string) $total,
                        'active'    => (string) $active,
                        'completed' => (string) $completed,
                    ])) ?>
                </p>
            <?php endif; ?>
        </div>

        <?php if ($total > 0): ?>
            <div class="lms-filter-pills" role="tablist" aria-label="<?= e(__t('student.my_courses.filter.aria')) ?>">
                <button type="button" class="lms-filter-pill"
                        :class="{ 'is-active': filter === 'all' }"
                        @click="filter = 'all'" role="tab"
                        :aria-selected="(filter === 'all').toString()">
                    <?= e(__t('student.my_courses.filter.all')) ?>
                    <span class="lms-filter-pill__count"><?= (int) $total ?></span>
                </button>
                <button type="button" class="lms-filter-pill"
                        :class="{ 'is-active': filter === 'active' }"
                        @click="filter = 'active'" role="tab"
                        :aria-selected="(filter === 'active').toString()">
                    <?= e(__t('student.my_courses.filter.active')) ?>
                    <span class="lms-filter-pill__count"><?= (int) $active ?></span>
                </button>
                <button type="button" class="lms-filter-pill"
                        :class="{ 'is-active': filter === 'completed' }"
                        @click="filter = 'completed'" role="tab"
                        :aria-selected="(filter === 'completed').toString()">
                    <?= e(__t('student.my_courses.filter.completed')) ?>
                    <span class="lms-filter-pill__count"><?= (int) $completed ?></span>
                </button>
            </div>
        <?php endif; ?>
    </header>

    <?php if ($total === 0): ?>
        <div class="lms-dashboard-empty">
            <p class="lms-dashboard-empty__title"><?= e(__t('dashboard.student.no_courses')) ?></p>
            <p class="lms-dashboard-empty__hint"><?= e(__t('dashboard.student.no_courses_hint')) ?></p>
        </div>
    <?php else: ?>
        <div class="lms-course-grid">
            <?php foreach ($courses as $course): ?>
                <?php require LMS_ROOT . '/src/templates/partials/course_card.php'; ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<?php
$page_content = ob_get_clean();
require LMS_ROOT . '/src/templates/layout.php';
