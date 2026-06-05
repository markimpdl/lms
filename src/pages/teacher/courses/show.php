<?php
declare(strict_types=1);

/**
 * /teacher/courses/{id} — detalhes do curso + listagem e CRUD de CCs (E3-01 + E3-02).
 * E3-04 adiciona breadcrumbs via helper + sidebar de currículo (coluna ≥md,
 * offcanvas <md).
 */

$myTenantId = current_tenant_id();
if ($myTenantId === null) {
    http_response_code(403);
    require LMS_ROOT . '/src/templates/errors/403.php';
    return;
}

$courseId = (int) ($_REQUEST['id'] ?? 0);

// E32 (ADR-033): conteúdo usa o tenant do DONO (acesso compartilhado: dono ou
// colaborador); dados de aluno usam o MEU tenant (cada professor só os seus).
// Para o dono, $authoringTenantId === $myTenantId → comportamento idêntico.
$authoringTenantId = effective_authoring_tenant($courseId);
if ($authoringTenantId === null) {
    http_response_code(404);
    require LMS_ROOT . '/src/templates/errors/404.php';
    return;
}
$isOwner = ($authoringTenantId === $myTenantId);

$course = Course::findForTenant($courseId, $authoringTenantId);
if ($course === null) {
    http_response_code(404);
    require LMS_ROOT . '/src/templates/errors/404.php';
    return;
}

$isArchived = (int) $course['archived'] === 1;
$ccs = CoreCompetency::listByCourse($courseId, $authoringTenantId);
$ccCount = count($ccs);
$tree = curriculum_tree($courseId, $authoringTenantId);
$activeCcId = 0;
$activeCuId = 0;
$courseCountsFormatted = format_delete_counts(Course::countDescendants($courseId, $authoringTenantId));
// E31-03: cursos de destino para "Copiar CC para…" — meus cursos próprios.
$copyTargetCourses = Course::listActiveForSelect($myTenantId);

// Dados de aluno: estritamente o MEU tenant (colaborador só vê os seus).
$enrolledPage = max(1, (int) ($_GET['students_page'] ?? 1));
$enrolled = Enrollment::listByCourse($courseId, $myTenantId, $enrolledPage);
$metrics  = CourseMetrics::forCourse($courseId, $myTenantId);

// E32-03: colaboradores — só o dono gerencia. Captura o "desfazer" one-shot.
$collaborators = $isOwner ? CourseCollaborator::listByCourse($courseId) : [];
$collabUndo = null;
if ($isOwner
    && isset($_SESSION['collab_undo'])
    && (int) ($_SESSION['collab_undo']['course_id'] ?? 0) === $courseId
) {
    $collabUndo = $_SESSION['collab_undo'];
}
unset($_SESSION['collab_undo']);

$page_title = (string) $course['name'];

ob_start();
?>
<?= breadcrumbs([
    ['label' => __t('courses.index.title'), 'url' => '/teacher/courses'],
    ['label' => (string) $course['name']],
]) ?>

<button type="button" class="btn btn-outline-secondary d-md-none mb-2"
        data-bs-toggle="offcanvas" data-bs-target="#curriculumOffcanvas">
    ☰ <?= e(__t('nav.curriculum.open')) ?>
</button>

<div class="row g-3">
    <aside class="col-md-3 d-none d-md-block">
        <div class="card shadow-sm sticky-top" style="top: 1rem;">
            <div class="card-body p-2">
                <?php require LMS_ROOT . '/src/templates/partials/curriculum_nav.php'; ?>
            </div>
        </div>
    </aside>

    <div class="col-12 col-md-9">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
            <div>
                <h1 class="h4 mb-1"><?= e((string) $course['name']) ?></h1>
                <div class="small text-muted">
                    <?= (int) $course['year'] ?> · <?= e(strtoupper((string) $course['language'])) ?> ·
                    <?php if ($isArchived): ?>
                        <span class="badge text-bg-warning"><?= e(__t('courses.status.archived')) ?></span>
                        <?php if ($course['archived_at'] !== null): ?>
                            <span class="ms-1"><?= e(__t('courses.archived_at', ['date' => date('Y-m-d H:i', strtotime((string) $course['archived_at']))])) ?></span>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="badge text-bg-success"><?= e(__t('courses.status.active')) ?></span>
                    <?php endif; ?>
                    <?php if ($isOwner && $collaborators !== []): ?>
                        <span class="badge text-bg-info"><?= e(__t('collab.badge.shared_by_you')) ?></span>
                    <?php elseif (!$isOwner): ?>
                        <span class="badge text-bg-info"><?= e(__t('collab.badge.shared_with_you_short')) ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="d-flex gap-2 flex-wrap">
                <a href="/teacher/courses/<?= (int) $course['id'] ?>/matrix" class="btn btn-outline-secondary">
                    <?= e(__t('courses.action.matrix')) ?>
                </a>
                <a href="/teacher/courses/<?= (int) $course['id'] ?>/ranking" class="btn btn-outline-secondary">
                    <?= e(__t('ranking.link.course_ranking')) ?>
                </a>
                <?php if (!$isArchived): ?>
                    <a href="/teacher/courses/<?= (int) $course['id'] ?>/edit" class="btn btn-outline-primary">
                        <?= e(__t('courses.action.edit')) ?>
                    </a>
                <?php endif; ?>
<?php if ($isOwner): /* arquivar/excluir são exclusivos do dono (ADR-033) */ ?>
                <form method="POST" action="/teacher/courses/<?= (int) $course['id'] ?>/toggle-archive" class="d-inline">
                    <?= csrf_field() ?>
                    <?php if ($isArchived): ?>
                        <button type="submit" class="btn btn-outline-success">
                            <?= e(__t('courses.action.restore')) ?>
                        </button>
                    <?php else: ?>
                        <button type="submit" class="btn btn-outline-warning"
                                onclick="return confirm(<?= e(json_encode(__t('courses.archive_confirm', ['name' => $course['name']]), JSON_UNESCAPED_UNICODE)) ?>);">
                            <?= e(__t('courses.action.archive')) ?>
                        </button>
                    <?php endif; ?>
                </form>
                <button type="button" class="btn btn-outline-danger"
                        data-bs-toggle="modal" data-bs-target="#deleteConfirmModal"
                        data-item-name="<?= e((string) $course['name']) ?>"
                        data-action-url="/teacher/courses/<?= (int) $course['id'] ?>/delete"
                        data-counts="<?= e(json_encode($courseCountsFormatted, JSON_UNESCAPED_UNICODE)) ?>">
                    <?= e(__t('delete.action')) ?>
                </button>
<?php endif; ?>
            </div>
        </div>

        <?php if (!empty($course['description'])): ?>
            <div class="card card-body shadow-sm mb-3">
                <p class="mb-0" style="white-space: pre-line;"><?= e((string) $course['description']) ?></p>
            </div>
        <?php endif; ?>

        <?php if ($metrics !== null && ($metrics['activities_count'] + $metrics['evaluations_count']) > 0): ?>
            <div class="card card-body shadow-sm mb-3">
                <h2 class="h6 text-muted text-uppercase mb-3" style="letter-spacing:.06em"><?= e(__t('metrics.course.title')) ?></h2>
                <div class="row text-center g-3">
                    <div class="col-6 col-md-4 col-lg-2">
                        <small class="text-muted text-uppercase fw-semibold d-block"><?= e(__t('metrics.course.activities')) ?></small>
                        <div class="h5 mb-0"><?= (int) $metrics['activities_count'] ?></div>
                    </div>
                    <div class="col-6 col-md-4 col-lg-2">
                        <small class="text-muted text-uppercase fw-semibold d-block"><?= e(__t('metrics.course.evaluations')) ?></small>
                        <div class="h5 mb-0"><?= (int) $metrics['evaluations_count'] ?></div>
                    </div>
                    <div class="col-6 col-md-4 col-lg-2">
                        <small class="text-muted text-uppercase fw-semibold d-block"><?= e(__t('metrics.course.enrolled')) ?></small>
                        <div class="h5 mb-0"><?= (int) $metrics['enrolled'] ?></div>
                    </div>
                    <div class="col-6 col-md-4 col-lg-2">
                        <small class="text-muted text-uppercase fw-semibold d-block"><?= e(__t('metrics.course.completion')) ?></small>
                        <div class="h5 mb-0 <?= $metrics['pct_completion'] >= 80 ? 'text-success' : ($metrics['pct_completion'] >= 50 ? 'text-warning-emphasis' : '') ?>">
                            <?= (int) $metrics['pct_completion'] ?>%
                        </div>
                    </div>
                    <?php if ($metrics['evaluations_count'] > 0): ?>
                        <div class="col-6 col-md-4 col-lg-2">
                            <small class="text-muted text-uppercase fw-semibold d-block"><?= e(__t('metrics.course.approval')) ?></small>
                            <div class="h5 mb-0 <?= ($metrics['pct_approved_avg'] ?? 0) >= 80 ? 'text-success' : (($metrics['pct_approved_avg'] ?? 0) >= 50 ? 'text-warning-emphasis' : '') ?>">
                                <?= $metrics['pct_approved_avg'] !== null ? (int) $metrics['pct_approved_avg'] . '%' : '—' ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    <div class="col-6 col-md-4 col-lg-2">
                        <small class="text-muted text-uppercase fw-semibold d-block"><?= e(__t('metrics.course.avg_feedback')) ?></small>
                        <div class="h5 mb-0"><?= e(format_duration_minutes($metrics['avg_feedback_minutes'])) ?></div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Core Competencies -->
        <div class="card shadow-sm mb-3">
            <div class="card-header d-flex align-items-center justify-content-between gap-2 flex-wrap">
                <div>
                    <h2 class="h6 mb-0"><?= e(__t('cc.section.title')) ?></h2>
                    <small class="text-muted"><?= e(__t('cc.section.subtitle', ['count' => (string) $ccCount])) ?></small>
                </div>
                <?php if (!$isArchived): ?>
                    <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#ccNewModal">
                        + <?= e(__t('cc.new_button')) ?>
                    </button>
                <?php endif; ?>
            </div>

            <?php if ($ccCount === 0): ?>
                <div class="card-body text-center text-muted py-4">
                    <p class="mb-0"><?= e(__t('cc.empty')) ?></p>
                </div>
            <?php else: ?>
                <ul class="list-group list-group-flush">
                    <?php foreach ($ccs as $i => $cc): ?>
                        <?php $ccId = (int) $cc['id']; $ccName = (string) $cc['name']; ?>
                        <li class="list-group-item d-flex align-items-center gap-2">
                            <span class="text-muted small" style="min-width: 1.5rem;"><?= $i + 1 ?>.</span>
                            <div class="flex-grow-1">
                                <a href="/teacher/courses/<?= (int) $course['id'] ?>/cc/<?= $ccId ?>" class="fw-semibold text-decoration-none"><?= e($ccName) ?></a>
                                <small class="text-muted ms-2"><?= (int) $cc['cu_count'] ?> <?= e(__t('courses.col.cu_count')) ?></small>
                            </div>
                            <?php if (!$isArchived): ?>
                                <?php $ccCountsFormatted = format_delete_counts([
                                    'cus'         => (int) $cc['cu_count'],
                                    'activities'  => (int) $cc['activities_count'],
                                    'evaluations' => (int) $cc['evaluations_count'],
                                ]); ?>
                                <div class="d-flex gap-1">
                                    <form method="POST" action="/teacher/cc/<?= $ccId ?>/move-up" class="d-inline">
                                        <?= csrf_field() ?>
                                        <button type="submit" class="btn btn-sm btn-outline-secondary" <?= $i === 0 ? 'disabled' : '' ?>
                                                aria-label="<?= e(__t('cc.action.move_up')) ?>">↑</button>
                                    </form>
                                    <form method="POST" action="/teacher/cc/<?= $ccId ?>/move-down" class="d-inline">
                                        <?= csrf_field() ?>
                                        <button type="submit" class="btn btn-sm btn-outline-secondary" <?= $i === $ccCount - 1 ? 'disabled' : '' ?>
                                                aria-label="<?= e(__t('cc.action.move_down')) ?>">↓</button>
                                    </form>
                                    <button type="button" class="btn btn-sm btn-outline-primary"
                                            data-bs-toggle="modal" data-bs-target="#ccEditModal"
                                            data-cc-id="<?= $ccId ?>" data-cc-name="<?= e($ccName) ?>"
                                            aria-label="<?= e(__t('cc.action.rename')) ?>">✎</button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary"
                                            data-bs-toggle="modal" data-bs-target="#ccCopyModal"
                                            data-cc-id="<?= $ccId ?>" data-cc-name="<?= e($ccName) ?>"
                                            aria-label="<?= e(__t('cc.action.copy')) ?>">⧉</button>
                                    <button type="button" class="btn btn-sm btn-outline-danger"
                                            data-bs-toggle="modal" data-bs-target="#deleteConfirmModal"
                                            data-item-name="<?= e($ccName) ?>"
                                            data-action-url="/teacher/cc/<?= $ccId ?>/delete"
                                            data-counts="<?= e(json_encode($ccCountsFormatted, JSON_UNESCAPED_UNICODE)) ?>"
                                            aria-label="<?= e(__t('delete.action')) ?>">🗑</button>
                                </div>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <?php if ($isOwner): ?>
        <!-- Colaboradores (E32-03) — só o dono gerencia -->
        <div class="card shadow-sm mb-3">
            <div class="card-header">
                <h2 class="h6 mb-0"><?= e(__t('collab.section.title')) ?></h2>
                <small class="text-muted"><?= e(__t('collab.section.subtitle')) ?></small>
            </div>
            <div class="card-body">
                <?php if ($collabUndo !== null): ?>
                    <div class="alert alert-secondary d-flex align-items-center justify-content-between gap-2 flex-wrap" role="alert">
                        <span><?= e(__t('collab.removed', ['name' => (string) $collabUndo['name']])) ?></span>
                        <form method="POST" action="/teacher/courses/<?= (int) $course['id'] ?>/collaborators" class="m-0">
                            <?= csrf_field() ?>
                            <input type="hidden" name="email" value="<?= e((string) $collabUndo['email']) ?>">
                            <button type="submit" class="btn btn-sm btn-outline-secondary"><?= e(__t('collab.undo')) ?></button>
                        </form>
                    </div>
                <?php endif; ?>

                <?php if ($collaborators === []): ?>
                    <p class="text-muted small mb-3"><?= e(__t('collab.empty')) ?></p>
                <?php else: ?>
                    <ul class="list-group list-group-flush mb-3">
                        <?php foreach ($collaborators as $col): ?>
                            <li class="list-group-item d-flex align-items-center justify-content-between gap-2 flex-wrap px-0">
                                <div>
                                    <span class="fw-semibold"><?= e((string) $col['name']) ?></span>
                                    <small class="text-muted ms-2 text-break"><?= e((string) $col['email']) ?></small>
                                </div>
                                <form method="POST" action="/teacher/courses/<?= (int) $course['id'] ?>/collaborators/<?= (int) $col['user_id'] ?>/remove" class="m-0">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="btn btn-sm btn-outline-danger"><?= e(__t('collab.action.remove')) ?></button>
                                </form>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <form method="POST" action="/teacher/courses/<?= (int) $course['id'] ?>/collaborators" class="row g-2 align-items-end">
                    <?= csrf_field() ?>
                    <div class="col-12 col-sm-8">
                        <label for="collabEmail" class="form-label"><?= e(__t('collab.form.email')) ?></label>
                        <input type="email" id="collabEmail" name="email" class="form-control" required
                               placeholder="<?= e(__t('collab.form.email_placeholder')) ?>">
                    </div>
                    <div class="col-12 col-sm-4 d-grid">
                        <button type="submit" class="btn btn-primary"><?= e(__t('collab.action.add')) ?></button>
                    </div>
                </form>
                <p class="form-text mb-0"><?= e(__t('collab.help')) ?></p>
            </div>
        </div>
        <?php endif; ?>

        <!-- Alunos matriculados (E4-02) -->
        <div class="card shadow-sm mb-3">
            <div class="card-header">
                <h2 class="h6 mb-0"><?= e(__t('enrollments.course_students.title')) ?></h2>
                <small class="text-muted">
                    <?= e(__t('enrollments.course_students.subtitle', ['count' => (string) $enrolled['total']])) ?>
                </small>
            </div>
            <?php if ($enrolled['total'] === 0): ?>
                <div class="card-body text-center text-muted py-4">
                    <p class="mb-0"><?= e(__t('enrollments.course_students.empty')) ?></p>
                </div>
            <?php else: ?>
                <ul class="list-group list-group-flush">
                    <?php foreach ($enrolled['rows'] as $row): ?>
                        <?php
                            $rowStatus    = (string) ($row['status'] ?? 'active');
                            $rowStartsAt  = $row['access_starts_at'] ?? null;
                            $rowEndsAt    = $row['access_ends_at']   ?? null;
                            $rowBlockedAt = $row['blocked_at']       ?? null;
                            $isBlocked    = $rowBlockedAt !== null;
                            $periodLabel  = format_period_label($rowStartsAt, $rowEndsAt);
                            // datetime-local espera "YYYY-MM-DDTHH:MM"; converter do MySQL DATETIME.
                            $startsLocal  = $rowStartsAt !== null ? str_replace(' ', 'T', substr((string) $rowStartsAt, 0, 16)) : '';
                            $endsLocal    = $rowEndsAt   !== null ? str_replace(' ', 'T', substr((string) $rowEndsAt,   0, 16)) : '';
                        ?>
                        <?php $rowFullName = (string) $row['name']; ?>
                        <li class="list-group-item d-flex align-items-center gap-2 flex-wrap">
                            <div class="flex-grow-1">
                                <a href="/teacher/students/<?= (int) $row['student_id'] ?>"
                                   class="fw-semibold text-decoration-none"
                                   title="<?= e($rowFullName) ?>">
                                    <?= e(format_short_name($rowFullName)) ?>
                                </a>
                                <small class="text-muted ms-2 text-break"><?= e((string) $row['email']) ?></small>
                                <?php if ((int) $row['active'] === 0): ?>
                                    <span class="badge text-bg-secondary ms-2"><?= e(__t('students.status.inactive')) ?></span>
                                <?php endif; ?>
                                <?php if ($isBlocked): ?>
                                    <span class="badge bg-warning-subtle text-warning-emphasis ms-2"
                                          title="<?= e((string) $rowBlockedAt) ?>">
                                        <?= e(__t('enrollments.blocked.badge', ['date' => substr((string) $rowBlockedAt, 0, 10)])) ?>
                                    </span>
                                <?php endif; ?>
                                <div class="small text-muted">
                                    <?= e(__t('enrollments.enrolled_at', ['date' => substr((string) $row['enrolled_at'], 0, 10)])) ?>
                                    · <?= e(__t('enrollments.period.label')) ?>: <?= e($periodLabel) ?>
                                </div>
                            </div>

                            <button type="button" class="btn btn-sm btn-outline-secondary"
                                    data-bs-toggle="modal" data-bs-target="#periodModal"
                                    data-student-id="<?= (int) $row['student_id'] ?>"
                                    data-student-name="<?= e((string) $row['name']) ?>"
                                    data-starts-at="<?= e($startsLocal) ?>"
                                    data-ends-at="<?= e($endsLocal) ?>">
                                <?= e(__t('enrollments.action.edit_period')) ?>
                            </button>

                            <form method="POST"
                                  action="/teacher/courses/<?= (int) $course['id'] ?>/enrollment/<?= (int) $row['student_id'] ?>/block"
                                  class="m-0 d-inline">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-sm <?= $isBlocked ? 'btn-outline-success' : 'btn-outline-warning' ?>">
                                    <?= e(__t($isBlocked ? 'enrollments.action.unblock' : 'enrollments.action.block')) ?>
                                </button>
                            </form>

                            <button type="button" class="btn btn-sm btn-outline-danger"
                                    data-bs-toggle="modal" data-bs-target="#deleteConfirmModal"
                                    data-item-name="<?= e((string) $row['email']) ?>"
                                    data-action-url="/teacher/courses/<?= (int) $course['id'] ?>/enrollment/<?= (int) $row['student_id'] ?>/remove"
                                    data-counts="[]">
                                <?= e(__t('enrollments.action.remove')) ?>
                            </button>

                            <form method="POST"
                                  action="/teacher/courses/<?= (int) $course['id'] ?>/enrollment/<?= (int) $row['student_id'] ?>/status"
                                  class="m-0 d-inline-flex align-items-center gap-2">
                                <?= csrf_field() ?>
                                <label class="small text-muted mb-0 d-none d-md-inline" for="es-<?= (int) $row['student_id'] ?>">
                                    <?= e(__t('enrollment.status.label')) ?>:
                                </label>
                                <select id="es-<?= (int) $row['student_id'] ?>"
                                        name="status"
                                        class="form-select form-select-sm js-enrollment-status"
                                        aria-label="<?= e(__t('enrollment.status.label')) ?>">
                                    <option value="active"    <?= $rowStatus === 'active'    ? 'selected' : '' ?>><?= e(__t('enrollment.status.active')) ?></option>
                                    <option value="absent"    <?= $rowStatus === 'absent'    ? 'selected' : '' ?>><?= e(__t('enrollment.status.absent')) ?></option>
                                    <option value="completed" <?= $rowStatus === 'completed' ? 'selected' : '' ?>><?= e(__t('enrollment.status.completed')) ?></option>
                                </select>
                            </form>
                        </li>
                    <?php endforeach; ?>
                </ul>

                <script>
                (function () {
                    document.querySelectorAll('select.js-enrollment-status').forEach(function (sel) {
                        sel.addEventListener('change', function () { sel.form.submit(); });
                    });
                })();
                </script>

                <!-- Modal único de edição de período (E17-01); populado via show.bs.modal -->
                <div class="modal fade" id="periodModal" tabindex="-1" aria-hidden="true" aria-labelledby="periodModalTitle">
                    <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down">
                        <form id="periodForm" method="POST" action="" class="modal-content" novalidate>
                            <?= csrf_field() ?>
                            <div class="modal-header">
                                <h2 class="modal-title h5" id="periodModalTitle">
                                    <?= e(__t('enrollments.period.modal_title')) ?>
                                    <small class="text-muted ms-1" id="periodStudentName"></small>
                                </h2>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= e(__t('common.cancel')) ?>"></button>
                            </div>
                            <div class="modal-body">
                                <div class="row g-3">
                                    <div class="col-12 col-sm-6">
                                        <label for="periodStart" class="form-label"><?= e(__t('enrollments.field.access_starts_at')) ?></label>
                                        <input type="datetime-local" name="access_starts_at" id="periodStart" class="form-control">
                                    </div>
                                    <div class="col-12 col-sm-6">
                                        <label for="periodEnd" class="form-label"><?= e(__t('enrollments.field.access_ends_at')) ?></label>
                                        <input type="datetime-local" name="access_ends_at" id="periodEnd" class="form-control">
                                    </div>
                                </div>
                                <p class="form-text mt-2 mb-0"><?= e(__t('enrollments.field.access.help')) ?></p>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?= e(__t('common.cancel')) ?></button>
                                <button type="submit" class="btn btn-primary"><?= e(__t('common.save')) ?></button>
                            </div>
                        </form>
                    </div>
                </div>

                <script>
                (function () {
                    var modal = document.getElementById('periodModal');
                    if (!modal) return;
                    var form  = document.getElementById('periodForm');
                    var nameEl = document.getElementById('periodStudentName');
                    var startEl = document.getElementById('periodStart');
                    var endEl   = document.getElementById('periodEnd');
                    modal.addEventListener('show.bs.modal', function (event) {
                        var btn = event.relatedTarget;
                        if (!btn) return;
                        nameEl.textContent = btn.getAttribute('data-student-name') || '';
                        startEl.value = btn.getAttribute('data-starts-at') || '';
                        endEl.value   = btn.getAttribute('data-ends-at')   || '';
                        form.action = '/teacher/courses/<?= (int) $course['id'] ?>/enrollment/' + btn.getAttribute('data-student-id') + '/period';
                    });
                })();
                </script>

                <?php if ($enrolled['total_pages'] > 1): ?>
                    <nav aria-label="pagination" class="p-2">
                        <ul class="pagination pagination-sm justify-content-center mb-0">
                            <?php for ($p = 1; $p <= $enrolled['total_pages']; $p++): ?>
                                <li class="page-item <?= $p === $enrolled['page'] ? 'active' : '' ?>">
                                    <a class="page-link" href="/teacher/courses/<?= (int) $course['id'] ?>?students_page=<?= $p ?>#enrolled"><?= $p ?></a>
                                </li>
                            <?php endfor; ?>
                        </ul>
                    </nav>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Offcanvas (mobile) com a mesma árvore -->
<div class="offcanvas offcanvas-start" tabindex="-1" id="curriculumOffcanvas" aria-labelledby="curriculumOffcanvasLabel">
    <div class="offcanvas-header">
        <h5 class="offcanvas-title" id="curriculumOffcanvasLabel"><?= e(__t('nav.curriculum.title')) ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body">
        <?php require LMS_ROOT . '/src/templates/partials/curriculum_nav.php'; ?>
    </div>
</div>

<?php if (!$isArchived): ?>
<!-- Modal: Nova CC -->
<div class="modal fade" id="ccNewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down">
        <form method="POST" action="/teacher/cc/new" class="modal-content" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="course_id" value="<?= (int) $course['id'] ?>">
            <div class="modal-header">
                <h5 class="modal-title"><?= e(__t('cc.new.title')) ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <label for="ccNewName" class="form-label"><?= e(__t('cc.form.name')) ?></label>
                <input type="text" id="ccNewName" name="name" class="form-control form-control-lg"
                       required minlength="2" maxlength="150" autofocus>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?= e(__t('common.cancel')) ?></button>
                <button type="submit" class="btn btn-primary"><?= e(__t('common.save')) ?></button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Editar CC -->
<div class="modal fade" id="ccEditModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down">
        <form method="POST" action="" id="ccEditForm" class="modal-content" novalidate>
            <?= csrf_field() ?>
            <div class="modal-header">
                <h5 class="modal-title"><?= e(__t('cc.edit.title')) ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <label for="ccEditName" class="form-label"><?= e(__t('cc.form.name')) ?></label>
                <input type="text" id="ccEditName" name="name" class="form-control form-control-lg"
                       required minlength="2" maxlength="150">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?= e(__t('common.cancel')) ?></button>
                <button type="submit" class="btn btn-primary"><?= e(__t('common.save')) ?></button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    var modal = document.getElementById('ccEditModal');
    if (!modal) return;
    modal.addEventListener('show.bs.modal', function (event) {
        var btn = event.relatedTarget;
        if (!btn) return;
        var id = btn.getAttribute('data-cc-id');
        var name = btn.getAttribute('data-cc-name');
        var form = document.getElementById('ccEditForm');
        form.action = '/teacher/cc/' + id + '/rename';
        document.getElementById('ccEditName').value = name;
    });
})();
</script>

<!-- Modal: Copiar CC para outro curso (E31-03) -->
<div class="modal fade" id="ccCopyModal" tabindex="-1" aria-hidden="true" aria-labelledby="ccCopyTitle">
    <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down">
        <form method="POST" action="" id="ccCopyForm" class="modal-content" novalidate>
            <?= csrf_field() ?>
            <div class="modal-header">
                <h5 class="modal-title" id="ccCopyTitle"><?= e(__t('cc.copy.title')) ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= e(__t('common.cancel')) ?>"></button>
            </div>
            <div class="modal-body">
                <p class="mb-2"><?= e(__t('cc.copy.intro')) ?> <strong id="ccCopyName"></strong></p>
                <?php if ($copyTargetCourses === []): ?>
                    <p class="text-muted mb-0"><?= e(__t('copy.no_targets')) ?></p>
                <?php else: ?>
                    <label for="ccCopyTarget" class="form-label"><?= e(__t('copy.target_course')) ?></label>
                    <select id="ccCopyTarget" name="target_course_id" class="form-select form-select-lg" required>
                        <?php foreach ($copyTargetCourses as $tc): ?>
                            <option value="<?= (int) $tc['id'] ?>"><?= e((string) $tc['name']) ?> (<?= (int) $tc['year'] ?>)</option>
                        <?php endforeach; ?>
                    </select>
                    <p class="form-text mb-0"><?= e(__t('copy.hint')) ?></p>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?= e(__t('common.cancel')) ?></button>
                <button type="submit" class="btn btn-primary" <?= $copyTargetCourses === [] ? 'disabled' : '' ?>><?= e(__t('cc.action.copy')) ?></button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    var modal = document.getElementById('ccCopyModal');
    if (!modal) return;
    modal.addEventListener('show.bs.modal', function (event) {
        var btn = event.relatedTarget;
        if (!btn) return;
        document.getElementById('ccCopyForm').action = '/teacher/cc/' + btn.getAttribute('data-cc-id') + '/copy';
        document.getElementById('ccCopyName').textContent = btn.getAttribute('data-cc-name') || '';
    });
})();
</script>
<?php endif; ?>

<?php require LMS_ROOT . '/src/templates/partials/delete_confirm_modal.php'; ?>
<?php
$page_content = ob_get_clean();
require LMS_ROOT . '/src/templates/layout.php';
