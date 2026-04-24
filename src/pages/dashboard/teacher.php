<?php
declare(strict_types=1);

// Auth + papel garantidos pelo front controller via src/routes.php (E1-05).
$user = current_user();

$page_title = __t('dashboard.teacher.title');

ob_start();
?>
<div class="py-4">
    <h1 class="h3 mb-1"><?= e(__t('dashboard.teacher.title')) ?></h1>
    <p class="lead text-muted mb-4"><?= e(__t('dashboard.teacher.welcome', ['name' => $user['name']])) ?></p>

    <div class="row g-3">
        <div class="col-12 col-md-6">
            <a href="/teacher/courses" class="card card-body shadow-sm h-100 text-decoration-none">
                <h2 class="h5 mb-1"><?= e(__t('courses.index.title')) ?></h2>
                <p class="text-muted small mb-0"><?= e(__t('dashboard.teacher.courses_hint')) ?></p>
            </a>
        </div>
        <div class="col-12 col-md-6">
            <a href="/teacher/students" class="card card-body shadow-sm h-100 text-decoration-none">
                <h2 class="h5 mb-1"><?= e(__t('students.title')) ?></h2>
                <p class="text-muted small mb-0"><?= e(__t('dashboard.teacher.students_hint')) ?></p>
            </a>
        </div>
        <div class="col-12 col-md-6">
            <a href="/teacher/groups" class="card card-body shadow-sm h-100 text-decoration-none">
                <h2 class="h5 mb-1"><?= e(__t('groups.title')) ?></h2>
                <p class="text-muted small mb-0"><?= e(__t('dashboard.teacher.groups_hint')) ?></p>
            </a>
        </div>
        <div class="col-12 col-md-6">
            <a href="/teacher/ranks" class="card card-body shadow-sm h-100 text-decoration-none">
                <h2 class="h5 mb-1"><?= e(__t('ranks.title')) ?></h2>
                <p class="text-muted small mb-0"><?= e(__t('dashboard.teacher.ranks_hint')) ?></p>
            </a>
        </div>
    </div>
</div>
<?php
$page_content = ob_get_clean();

require LMS_ROOT . '/src/templates/layout.php';
