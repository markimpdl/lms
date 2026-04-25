<?php
declare(strict_types=1);

/**
 * /student/course/{id} — tela do curso pro aluno (E14-03, rewrite).
 *
 * Hero banner com gradient do curso + stats (overall%/units/cc count) +
 * ProgressRing 112; por-CC section header (ícone letter, eyebrow, H2,
 * barra, ring 72) + grid de UnitCards com workload e XP total.
 *
 * E14-00 segue sendo respeitado: chama `Enrollment::touchLastAccess`
 * no topo pra atualizar last_access_at antes do render.
 */

$user = current_user();
if ($user === null || ($user['role'] ?? '') !== 'student') {
    http_response_code(403);
    require LMS_ROOT . '/src/templates/errors/403.php';
    return;
}

$studentId = (int) $user['id'];
$tenantId  = (int) ($user['tenant_id'] ?? 0);
$courseId  = (int) ($_REQUEST['id'] ?? 0);

$course = StudentCurriculum::forStudentCourse($studentId, $courseId);
if ($course === null) {
    http_response_code(404);
    require LMS_ROOT . '/src/templates/errors/404.php';
    return;
}

// Gate de disponibilidade (E17-03): bloqueio manual / fora da janela de
// acesso → flash + redirect pra /student. Aluno sem matrícula já caiu
// em 404 acima.
$availability = enrollment_access_status($studentId, $courseId);
if (!$availability['available']) {
    flash('warning', $availability['message'] ?? __t('enrollment.unavailable.generic'));
    header('Location: /student', true, 303);
    exit;
}

$wasFirstAccess = Enrollment::touchLastAccess($studentId, $courseId);
if ($wasFirstAccess) {
    // Conquistas (E18-04): primeiro acesso ao curso desbloqueia "course_started".
    try {
        AchievementsService::evaluateForEvent($studentId, $tenantId, 'course_started');
    } catch (\Throwable) {
        // swallow
    }
}

$archived    = (int) $course['course_archived'] === 1;
$courseName  = (string) $course['course_name'];
$firstName   = explode(' ', trim((string) ($user['name'] ?? '')))[0] ?? '';
[$gradStart, $gradEnd] = Course::coverGradient($courseId);

// Enriquecimento on-the-fly: status+percent por CU, agregados por CC e por curso.
$ccPayload = [];
$unitsTotal = 0;
$unitsDone  = 0;
$overallSum = 0;
$overallCount = 0;

foreach ($course['ccs'] as $cc) {
    $ccUnitsTot  = count($cc['cus']);
    $ccUnitsDone = 0;
    $ccPctSum    = 0;

    foreach ($cc['cus'] as $cu) {
        $status = student_cu_status((int) $cu['id'], $studentId);
        if ($status['status'] === 'completed') {
            $ccUnitsDone++;
        }
        $ccPctSum += (int) $status['percent'];
        $overallSum += (int) $status['percent'];
        $overallCount++;
    }

    $ccPercent = $ccUnitsTot > 0 ? (int) round($ccPctSum / $ccUnitsTot) : 0;
    $unitsTotal += $ccUnitsTot;
    $unitsDone  += $ccUnitsDone;

    $ccPayload[] = [
        'cc'         => $cc,
        'percent'    => $ccPercent,
        'units_done' => $ccUnitsDone,
        'units_tot'  => $ccUnitsTot,
    ];
}

$overallPct = $overallCount > 0 ? (int) round($overallSum / $overallCount) : 0;
$ccCount    = count($course['ccs']);

$page_title = $courseName;

ob_start();
?>
<?= breadcrumbs([
    ['label' => __t('dashboard.student.title'), 'url' => '/student'],
    ['label' => $courseName],
]) ?>

<?php if ($archived): ?>
    <div class="alert alert-warning mb-3" role="alert">
        <?= e(__t('courses.status.archived')) ?>
    </div>
<?php endif; ?>

<?php require LMS_ROOT . '/src/templates/partials/student_course_hero.php'; ?>

<div class="lms-course-actions">
    <a href="/student/course/<?= (int) $courseId ?>/ranking" class="lms-course-actions__link">
        <?= e(__t('ranking.link.course_ranking')) ?>
    </a>
</div>

<?php if ($course['ccs'] === []): ?>
    <div class="lms-dashboard-empty mt-4">
        <p class="lms-dashboard-empty__title"><?= e(__t('dashboard.student.course_empty')) ?></p>
    </div>
<?php else: ?>
    <div class="lms-cc-list">
        <?php foreach ($ccPayload as $ccIdx => $entry): ?>
            <?php
                $cc          = $entry['cc'];
                $ccIndex     = $ccIdx + 1;
                $ccPercent   = (int) $entry['percent'];
                $ccUnitsDone = (int) $entry['units_done'];
                $ccUnitsTot  = (int) $entry['units_tot'];
                require LMS_ROOT . '/src/templates/partials/student_cc_section.php';
            ?>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php
$page_content = ob_get_clean();
require LMS_ROOT . '/src/templates/layout.php';
