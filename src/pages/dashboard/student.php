<?php
declare(strict_types=1);

// Auth + papel garantidos pelo front controller via src/routes.php (E1-05).
$user = current_user();
$tree = StudentCurriculum::forStudent((int) $user['id']);

$page_title = __t('dashboard.student.title');

ob_start();
?>
<div class="py-3">
    <h1 class="h3 mb-3"><?= e(__t('dashboard.student.title')) ?></h1>
    <p class="lead text-muted mb-4"><?= e(__t('dashboard.student.welcome', ['name' => $user['name']])) ?></p>

    <?php if ($tree === []): ?>
        <div class="card card-body text-center text-muted py-5">
            <p class="lead mb-0"><?= e(__t('dashboard.student.no_courses')) ?></p>
            <p class="small mb-0"><?= e(__t('dashboard.student.no_courses_hint')) ?></p>
        </div>
    <?php else: ?>
        <?php foreach ($tree as $course): ?>
            <?php $archived = (int) $course['course_archived'] === 1; ?>
            <div class="card shadow-sm mb-3">
                <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
                    <h2 class="h6 mb-0">
                        <?= e((string) $course['course_name']) ?>
                        <?php if ($archived): ?>
                            <span class="badge text-bg-warning ms-2"><?= e(__t('courses.status.archived')) ?></span>
                        <?php endif; ?>
                    </h2>
                </div>
                <?php if ($course['ccs'] === []): ?>
                    <div class="card-body text-muted small">
                        <?= e(__t('dashboard.student.course_empty')) ?>
                    </div>
                <?php else: ?>
                    <div class="list-group list-group-flush">
                        <?php foreach ($course['ccs'] as $cc): ?>
                            <div class="list-group-item">
                                <div class="fw-semibold mb-2"><?= e((string) $cc['name']) ?></div>
                                <?php if ($cc['cus'] === []): ?>
                                    <div class="small text-muted"><?= e(__t('dashboard.student.cc_empty')) ?></div>
                                <?php else: ?>
                                    <ul class="list-unstyled mb-0 ps-3">
                                        <?php foreach ($cc['cus'] as $cu): ?>
                                            <li class="mb-1">
                                                <a href="/student/cu/<?= (int) $cu['id'] ?>" class="text-decoration-none">
                                                    · <?= e((string) $cu['name']) ?>
                                                </a>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
<?php
$page_content = ob_get_clean();
require LMS_ROOT . '/src/templates/layout.php';
