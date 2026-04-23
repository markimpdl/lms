<?php
declare(strict_types=1);

// Auth + papel garantidos pelo front controller via src/routes.php (E1-05).
$user      = current_user();
$studentId = (int) $user['id'];
$tree      = StudentCurriculum::forStudent($studentId);

$page_title = __t('dashboard.student.title');

ob_start();
?>
<div class="py-3">
    <h1 class="h3 mb-3"><?= e(__t('dashboard.student.title')) ?></h1>
    <p class="text-muted mb-4"><?= e(__t('dashboard.student.welcome', ['name' => $user['name']])) ?></p>

    <?php if ($tree === []): ?>
        <div class="card card-body text-center text-muted py-5">
            <p class="lead mb-0"><?= e(__t('dashboard.student.no_courses')) ?></p>
            <p class="small mb-0"><?= e(__t('dashboard.student.no_courses_hint')) ?></p>
        </div>
    <?php else: ?>
        <div class="d-flex flex-column gap-2">
            <?php foreach ($tree as $course): ?>
                <?php
                    $archived = (int) $course['course_archived'] === 1;
                    $s        = student_course_status((int) $course['course_id'], $studentId);
                    $status   = $s['status'];
                    $percent  = (int) $s['percent'];
                    $enrolledDate = substr((string) $course['enrolled_at'], 0, 10);
                ?>
                <a href="/student/course/<?= (int) $course['course_id'] ?>"
                   class="lms-card lms-card--<?= e(str_replace('_', '-', $status)) ?>">
                    <div class="lms-card__body">
                        <div class="lms-card__title">
                            <?= e((string) $course['course_name']) ?>
                            <?php if ($archived): ?>
                                <span class="badge text-bg-warning ms-1"><?= e(__t('courses.status.archived')) ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="lms-card__meta">
                            <?= e(__t('student.course.enrolled_at', ['date' => $enrolledDate])) ?>
                        </div>
                    </div>
                    <span class="badge text-bg-<?= $status === 'completed' ? 'success' : ($status === 'in_progress' ? 'warning' : 'secondary') ?>">
                        <?= e(__t('status.course.' . $status)) ?>
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
</div>
<?php
$page_content = ob_get_clean();
require LMS_ROOT . '/src/templates/layout.php';
