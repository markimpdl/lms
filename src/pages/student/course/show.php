<?php
declare(strict_types=1);

/**
 * /student/course/{id} — tela do curso pro aluno. Lista CCs como seções
 * com barra de progresso; cada CU vira card no padrão `.lms-card` (issue #99).
 */

$user = current_user();
if ($user === null || ($user['role'] ?? '') !== 'student') {
    http_response_code(403);
    require LMS_ROOT . '/src/templates/errors/403.php';
    return;
}

$studentId = (int) $user['id'];
$courseId  = (int) ($_REQUEST['id'] ?? 0);

$course = StudentCurriculum::forStudentCourse($studentId, $courseId);
if ($course === null) {
    http_response_code(404);
    require LMS_ROOT . '/src/templates/errors/404.php';
    return;
}

// E14-00: registra o último acesso do aluno ao curso pro CourseCard
// (E14-02) mostrar "Último acesso: {data}". Silencioso em falha.
Enrollment::touchLastAccess($studentId, $courseId);

$archived   = (int) $course['course_archived'] === 1;
$page_title = (string) $course['course_name'];

ob_start();
?>
<?= breadcrumbs([
    ['label' => __t('dashboard.student.title'), 'url' => '/student'],
    ['label' => (string) $course['course_name']],
]) ?>

<div class="row justify-content-center">
    <div class="col-12 col-lg-10">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
            <h1 class="h4 mb-0">
                <?= e((string) $course['course_name']) ?>
                <?php if ($archived): ?>
                    <span class="badge text-bg-warning ms-2"><?= e(__t('courses.status.archived')) ?></span>
                <?php endif; ?>
            </h1>
        </div>

        <?php if ($course['ccs'] === []): ?>
            <div class="card card-body text-center text-muted py-5">
                <p class="mb-0"><?= e(__t('dashboard.student.course_empty')) ?></p>
            </div>
        <?php else: ?>
            <?php foreach ($course['ccs'] as $cc): ?>
                <?php
                    // % do CC = média dos percent das CUs (placeholder até E6/E7).
                    // Hoje é sempre 0 porque student_cu_status é placeholder.
                    $ccPercent = 0;
                    if ($cc['cus'] !== []) {
                        $sum = 0;
                        foreach ($cc['cus'] as $cu) {
                            $sum += student_cu_status((int) $cu['id'], $studentId)['percent'];
                        }
                        $ccPercent = (int) round($sum / count($cc['cus']));
                    }
                ?>
                <section class="mb-4">
                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
                        <h2 class="h6 mb-0"><?= e((string) $cc['name']) ?></h2>
                        <small class="text-muted"><?= e((string) $ccPercent) ?>%</small>
                    </div>
                    <div class="progress mb-3" style="height: 6px;" role="progressbar"
                         aria-valuenow="<?= e((string) $ccPercent) ?>" aria-valuemin="0" aria-valuemax="100">
                        <div class="progress-bar" style="width: <?= e((string) $ccPercent) ?>%;"></div>
                    </div>

                    <?php if ($cc['cus'] === []): ?>
                        <p class="small text-muted mb-0"><?= e(__t('dashboard.student.cc_empty')) ?></p>
                    <?php else: ?>
                        <div class="d-flex flex-column gap-2">
                            <?php foreach ($cc['cus'] as $cu): ?>
                                <?php
                                    $s       = student_cu_status((int) $cu['id'], $studentId);
                                    $status  = $s['status'];
                                    $percent = (int) $s['percent'];
                                ?>
                                <a href="/student/cu/<?= (int) $cu['id'] ?>"
                                   class="lms-card lms-card--<?= e(str_replace('_', '-', $status)) ?>">
                                    <div class="lms-card__body">
                                        <div class="lms-card__title"><?= e((string) $cu['name']) ?></div>
                                    </div>
                                    <span class="badge text-bg-<?= $status === 'completed' ? 'success' : ($status === 'in_progress' ? 'warning' : 'secondary') ?>">
                                        <?= e(__t('status.cu.' . $status)) ?>
                                    </span>
                                    <div class="lms-progress-ring lms-progress-ring--<?= e(str_replace('_', '-', $status)) ?>"
                                         style="--pct: <?= e((string) $percent) ?>;"
                                         role="progressbar"
                                         aria-label="<?= e(__t('student.course.progress_aria', ['percent' => (string) $percent])) ?>"
                                         aria-valuenow="<?= e((string) $percent) ?>" aria-valuemin="0" aria-valuemax="100">
                                        <span class="lms-progress-ring__label"><?= e((string) $percent) ?>%</span>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
<?php
$page_content = ob_get_clean();
require LMS_ROOT . '/src/templates/layout.php';
