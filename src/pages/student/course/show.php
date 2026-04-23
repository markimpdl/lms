<?php
declare(strict_types=1);

/**
 * /student/course/{id} — página do aluno de um curso específico (E5-05
 * polish). Lista as CCs e CUs do curso. Exige matrícula ativa.
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

$archived = (int) $course['course_archived'] === 1;
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
                <div class="card shadow-sm mb-3">
                    <div class="card-header">
                        <h2 class="h6 mb-0"><?= e((string) $cc['name']) ?></h2>
                    </div>
                    <?php if ($cc['cus'] === []): ?>
                        <div class="card-body text-muted small">
                            <?= e(__t('dashboard.student.cc_empty')) ?>
                        </div>
                    <?php else: ?>
                        <ul class="list-group list-group-flush">
                            <?php foreach ($cc['cus'] as $cu): ?>
                                <li class="list-group-item">
                                    <a href="/student/cu/<?= (int) $cu['id'] ?>" class="text-decoration-none">
                                        · <?= e((string) $cu['name']) ?>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
<?php
$page_content = ob_get_clean();
require LMS_ROOT . '/src/templates/layout.php';
