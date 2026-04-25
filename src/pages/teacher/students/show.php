<?php
declare(strict_types=1);

/**
 * /teacher/students/{id} — detalhe + edição + toggle + delete do aluno (E4-01).
 *
 * Consolida GET (render) e POST (update) na mesma página, seguindo o padrão
 * do E2-03 (admin/teachers/edit). Email é readonly (ADR-021).
 */

$tenantId = current_tenant_id();
if ($tenantId === null) {
    http_response_code(403);
    require LMS_ROOT . '/src/templates/errors/403.php';
    return;
}

$studentId = (int) ($_REQUEST['id'] ?? 0);
$student   = Student::findForTenant($studentId, $tenantId);
if ($student === null) {
    http_response_code(404);
    require LMS_ROOT . '/src/templates/errors/404.php';
    return;
}

$errors = [];
$old = [
    'name'        => (string) $student['name'],
    'language'    => (string) $student['language'],
    'gender'      => (string) $student['gender'],
    'id_document' => (string) ($student['id_document'] ?? ''),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_verify();
    } catch (RuntimeException) {
        flash('danger', __t('auth.forbidden'));
        header('Location: /teacher/students/' . $studentId);
        exit;
    }

    $old = [
        'name'        => (string) ($_POST['name']        ?? $old['name']),
        'language'    => (string) ($_POST['language']    ?? $old['language']),
        'gender'      => (string) ($_POST['gender']      ?? $old['gender']),
        'id_document' => trim((string) ($_POST['id_document'] ?? $old['id_document'])),
    ];

    $errors = TeacherStudentsController::update($studentId, $old);
}

// Credenciais recém-geradas (pós-cadastro) — exibidas UMA vez e drenadas.
$justCreated = $_SESSION['student_creds_once'] ?? null;
if ($justCreated !== null && (int) ($justCreated['student_id'] ?? 0) === $studentId) {
    unset($_SESSION['student_creds_once']);
} else {
    $justCreated = null;
}

// Senha recém-resetada (E4-05) — mostrada UMA vez e drenada da sessão.
// Só considerada se refere ao aluno aberto agora.
$resetOnce = $_SESSION['student_password_reset_once'] ?? null;
if ($resetOnce !== null && (int) ($resetOnce['student_id'] ?? 0) === $studentId) {
    unset($_SESSION['student_password_reset_once']);
} else {
    $resetOnce = null;
}

$isActive = (int) $student['active'] === 1;

$recentLogins = UserLogin::findRecentForUser($studentId);
$enrollments = Enrollment::listByStudent($studentId, $tenantId);
$enrolledIds = array_map(static fn(array $e): int => (int) $e['course_id'], $enrollments);
$availableCourses = array_values(array_filter(
    Course::listActiveForSelect($tenantId),
    static fn(array $c): bool => !in_array($c['id'], $enrolledIds, true)
));

$studentGroups = GroupMember::listByStudent($studentId, $tenantId);
$studentGroupIds = array_map(static fn(array $g): int => (int) $g['group_id'], $studentGroups);
$availableGroups = array_values(array_filter(
    Group::listForSelect($tenantId),
    static fn(array $g): bool => !in_array($g['id'], $studentGroupIds, true)
));

$deleteCountsFormatted = format_delete_counts([
    'enrollments' => (int) $student['enrollments_count'],
    'groups'      => (int) $student['groups_count'],
]);

$page_title = (string) $student['name'];

ob_start();
?>
<div class="row justify-content-center">
    <div class="col-12 col-md-10 col-lg-8">
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
            <h1 class="h4 mb-0"><?= e((string) $student['name']) ?></h1>
            <a href="/teacher/students" class="btn btn-sm btn-outline-secondary">
                <?= e(__t('common.back')) ?>
            </a>
        </div>

        <?php if ($errors !== []): ?>
            <div class="alert alert-danger" role="alert">
                <?= e(__t('students.form.has_errors')) ?>
            </div>
        <?php endif; ?>

        <?php if ($justCreated !== null): ?>
            <div class="alert alert-success" role="alert">
                <h2 class="h6 mb-2"><?= e(__t('students.creds_title', ['name' => $justCreated['name']])) ?></h2>
                <p class="small mb-2">
                    <?= e(__t(
                        $justCreated['reason'] === 'smtp_unavailable'
                            ? 'students.creds_smtp_off'
                            : 'students.creds_opted_out'
                    )) ?>
                </p>
                <dl class="row mb-0 small">
                    <dt class="col-4 col-md-3"><?= e(__t('students.form.email')) ?></dt>
                    <dd class="col-8 col-md-9"><code><?= e($justCreated['email']) ?></code></dd>
                    <dt class="col-4 col-md-3"><?= e(__t('students.form.password')) ?></dt>
                    <dd class="col-8 col-md-9"><code><?= e($justCreated['password']) ?></code></dd>
                </dl>
            </div>
        <?php endif; ?>

        <?php if ($resetOnce !== null): ?>
            <div class="alert alert-success" role="alert">
                <h2 class="h6 mb-2"><?= e(__t('students.password_reset.creds_title', ['name' => $resetOnce['name']])) ?></h2>
                <p class="small mb-2">
                    <?= e(__t(
                        $resetOnce['reason'] === 'smtp_unavailable'
                            ? 'students.password_reset.creds_smtp_off'
                            : 'students.password_reset.creds_opted_out'
                    )) ?>
                </p>
                <dl class="row mb-0 small">
                    <dt class="col-4 col-md-3"><?= e(__t('students.form.email')) ?></dt>
                    <dd class="col-8 col-md-9"><code><?= e($resetOnce['email']) ?></code></dd>
                    <dt class="col-4 col-md-3"><?= e(__t('students.password_reset.new_password')) ?></dt>
                    <dd class="col-8 col-md-9"><code><?= e($resetOnce['password']) ?></code></dd>
                </dl>
            </div>
        <?php endif; ?>

        <form method="POST" action="/teacher/students/<?= (int) $studentId ?>" novalidate class="card card-body shadow-sm mb-3">
            <?= csrf_field() ?>

            <div class="mb-3">
                <label for="f-email" class="form-label"><?= e(__t('students.form.email')) ?></label>
                <input type="email" id="f-email" class="form-control"
                       value="<?= e((string) $student['email']) ?>" readonly
                       data-bs-toggle="tooltip"
                       title="<?= e(__t('students.email_immutable')) ?>">
                <div class="form-text"><?= e(__t('students.email_immutable')) ?></div>
            </div>

            <div class="mb-3">
                <label for="f-name" class="form-label"><?= e(__t('students.form.name')) ?></label>
                <input type="text" name="name" id="f-name"
                       class="form-control form-control-lg<?= isset($errors['name']) ? ' is-invalid' : '' ?>"
                       value="<?= e($old['name']) ?>"
                       required minlength="3" maxlength="120" autocomplete="off">
                <?php if (isset($errors['name'])): ?>
                    <div class="invalid-feedback"><?= e(__t($errors['name'])) ?></div>
                <?php endif; ?>
            </div>

            <div class="mb-3">
                <label for="f-lang" class="form-label"><?= e(__t('students.form.language')) ?></label>
                <select name="language" id="f-lang"
                        class="form-select form-select-lg<?= isset($errors['language']) ? ' is-invalid' : '' ?>">
                    <option value="pt" <?= $old['language'] === 'pt' ? 'selected' : '' ?>><?= e(__t('profile.lang_pt')) ?></option>
                    <option value="en" <?= $old['language'] === 'en' ? 'selected' : '' ?>><?= e(__t('profile.lang_en')) ?></option>
                </select>
                <?php if (isset($errors['language'])): ?>
                    <div class="invalid-feedback"><?= e(__t($errors['language'])) ?></div>
                <?php endif; ?>
            </div>

            <div class="mb-3">
                <label for="f-gender" class="form-label"><?= e(__t('students.form.gender')) ?></label>
                <select name="gender" id="f-gender" required
                        class="form-select form-select-lg<?= isset($errors['gender']) ? ' is-invalid' : '' ?>">
                    <option value="male"   <?= $old['gender'] === 'male'   ? 'selected' : '' ?>><?= e(__t('students.form.gender.male')) ?></option>
                    <option value="female" <?= $old['gender'] === 'female' ? 'selected' : '' ?>><?= e(__t('students.form.gender.female')) ?></option>
                </select>
                <?php if (isset($errors['gender'])): ?>
                    <div class="invalid-feedback"><?= e(__t($errors['gender'])) ?></div>
                <?php endif; ?>
            </div>

            <div class="mb-3">
                <label for="f-id-doc" class="form-label"><?= e(__t('students.form.id_document')) ?></label>
                <input type="text" name="id_document" id="f-id-doc" inputmode="numeric"
                       pattern="[0-9]{1,30}" maxlength="30" autocomplete="off"
                       class="form-control form-control-lg<?= isset($errors['id_document']) ? ' is-invalid' : '' ?>"
                       value="<?= e($old['id_document']) ?>">
                <div class="form-text"><?= e(__t('students.form.id_document.help')) ?></div>
                <?php if (isset($errors['id_document'])): ?>
                    <div class="invalid-feedback"><?= e(__t($errors['id_document'])) ?></div>
                <?php endif; ?>
            </div>

            <div class="d-flex flex-wrap gap-2">
                <button type="submit" class="btn btn-primary btn-lg">
                    <?= e(__t('students.form.save')) ?>
                </button>
                <a href="/teacher/students" class="btn btn-outline-secondary btn-lg">
                    <?= e(__t('common.cancel')) ?>
                </a>
            </div>
        </form>

        <div class="card shadow-sm mb-3">
            <div class="card-header d-flex align-items-center justify-content-between gap-2 flex-wrap">
                <div>
                    <h2 class="h6 mb-0"><?= e(__t('enrollments.section.title')) ?></h2>
                    <small class="text-muted">
                        <?= e(__t('enrollments.section.subtitle', ['count' => (string) count($enrollments)])) ?>
                    </small>
                </div>
                <?php if ($isActive && $availableCourses !== []): ?>
                    <button type="button" class="btn btn-sm btn-primary"
                            data-bs-toggle="modal" data-bs-target="#enrollModal">
                        + <?= e(__t('enrollments.add_button')) ?>
                    </button>
                <?php endif; ?>
            </div>

            <?php if ($enrollments === []): ?>
                <div class="card-body text-center text-muted py-4">
                    <p class="mb-0"><?= e(__t('enrollments.empty')) ?></p>
                </div>
            <?php else: ?>
                <ul class="list-group list-group-flush">
                    <?php foreach ($enrollments as $enr): ?>
                        <?php $archived = (int) $enr['archived'] === 1; ?>
                        <li class="list-group-item d-flex align-items-center gap-2">
                            <div class="flex-grow-1">
                                <a href="/teacher/courses/<?= (int) $enr['course_id'] ?>" class="fw-semibold text-decoration-none">
                                    <?= e((string) $enr['name']) ?>
                                </a>
                                <small class="text-muted ms-2"><?= (int) $enr['year'] ?> · <?= e(strtoupper((string) $enr['language'])) ?></small>
                                <?php if ($archived): ?>
                                    <span class="badge text-bg-warning ms-2"><?= e(__t('courses.status.archived')) ?></span>
                                <?php endif; ?>
                                <div class="small text-muted">
                                    <?= e(__t('enrollments.enrolled_at', ['date' => substr((string) $enr['enrolled_at'], 0, 10)])) ?>
                                </div>
                            </div>
                            <form method="POST" action="/teacher/students/<?= (int) $studentId ?>/unenroll/<?= (int) $enr['course_id'] ?>" class="m-0 js-unenroll-form"
                                  data-confirm="<?= e(__t('enrollments.unenroll_confirm', ['name' => (string) $enr['name']])) ?>">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-sm btn-outline-danger" aria-label="<?= e(__t('enrollments.action.unenroll')) ?>">
                                    <?= e(__t('enrollments.action.unenroll')) ?>
                                </button>
                            </form>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <!-- Grupos (E4-04) -->
        <div class="card shadow-sm mb-3">
            <div class="card-header d-flex align-items-center justify-content-between gap-2 flex-wrap">
                <div>
                    <h2 class="h6 mb-0"><?= e(__t('group_members.student_section.title')) ?></h2>
                    <small class="text-muted">
                        <?= e(__t('group_members.student_section.subtitle', ['count' => (string) count($studentGroups)])) ?>
                    </small>
                </div>
                <?php if ($isActive && $availableGroups !== []): ?>
                    <button type="button" class="btn btn-sm btn-primary"
                            data-bs-toggle="modal" data-bs-target="#assignGroupsModal">
                        + <?= e(__t('group_members.student_section.assign_button')) ?>
                    </button>
                <?php endif; ?>
            </div>

            <?php if ($studentGroups === []): ?>
                <div class="card-body text-center text-muted py-4">
                    <p class="mb-0"><?= e(__t('group_members.student_section.empty')) ?></p>
                </div>
            <?php else: ?>
                <ul class="list-group list-group-flush">
                    <?php foreach ($studentGroups as $g): ?>
                        <li class="list-group-item d-flex align-items-center gap-2 flex-wrap">
                            <div class="flex-grow-1">
                                <a href="/teacher/groups/<?= (int) $g['group_id'] ?>" class="fw-semibold text-decoration-none">
                                    <?= e((string) $g['name']) ?>
                                </a>
                                <div class="small text-muted">
                                    <?= e(__t('group_members.joined_at', ['date' => substr((string) $g['joined_at'], 0, 10)])) ?>
                                </div>
                            </div>
                            <form method="POST"
                                  action="/teacher/students/<?= (int) $studentId ?>/unassign-group/<?= (int) $g['group_id'] ?>"
                                  class="m-0 js-unassign-group-form"
                                  data-confirm="<?= e(__t('group_members.unassign_confirm', ['name' => (string) $g['name']])) ?>">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-sm btn-outline-danger">
                                    <?= e(__t('group_members.action.remove')) ?>
                                </button>
                            </form>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <!-- Histórico de conexões (E16-04) -->
        <div class="card shadow-sm mb-3">
            <div class="card-header">
                <h2 class="h6 mb-0"><?= e(__t('students.logins.title')) ?></h2>
                <small class="text-muted"><?= e(__t('students.logins.subtitle')) ?></small>
            </div>
            <?php if ($recentLogins === []): ?>
                <div class="card-body text-center text-muted py-4">
                    <p class="mb-0"><?= e(__t('students.logins.empty')) ?></p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table align-middle mb-0 small">
                        <thead class="table-light">
                            <tr>
                                <th><?= e(__t('students.logins.col.datetime')) ?></th>
                                <th><?= e(__t('students.logins.col.ip')) ?></th>
                                <th class="d-none d-md-table-cell"><?= e(__t('students.logins.col.location')) ?></th>
                                <th class="d-none d-lg-table-cell"><?= e(__t('students.logins.col.user_agent')) ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentLogins as $log): ?>
                                <tr>
                                    <td><?= e(format_short_datetime((string) $log['logged_in_at'])) ?></td>
                                    <td class="text-muted"><code><?= e((string) $log['ip']) ?></code></td>
                                    <td class="d-none d-md-table-cell text-muted">
                                        <?= !empty($log['location'])
                                            ? e((string) $log['location'])
                                            : '<span class="text-muted">—</span>' ?>
                                    </td>
                                    <td class="d-none d-lg-table-cell text-muted small text-truncate" style="max-width: 280px;"
                                        title="<?= e((string) ($log['user_agent'] ?? '')) ?>">
                                        <?= !empty($log['user_agent'])
                                            ? e((string) $log['user_agent'])
                                            : '<span class="text-muted">—</span>' ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <div class="card card-body shadow-sm">
            <h2 class="h6 mb-3"><?= e(__t('students.metadata')) ?></h2>
            <dl class="row mb-0 small">
                <dt class="col-6 col-md-4"><?= e(__t('students.metadata.status')) ?></dt>
                <dd class="col-6 col-md-8">
                    <?php if ($isActive): ?>
                        <span class="badge text-bg-success"><?= e(__t('students.status.active')) ?></span>
                    <?php else: ?>
                        <span class="badge text-bg-secondary"><?= e(__t('students.status.inactive')) ?></span>
                    <?php endif; ?>
                </dd>

                <dt class="col-6 col-md-4"><?= e(__t('students.metadata.created_at')) ?></dt>
                <dd class="col-6 col-md-8"><?= e(substr((string) $student['created_at'], 0, 10)) ?></dd>

                <dt class="col-6 col-md-4"><?= e(__t('students.metadata.last_login')) ?></dt>
                <dd class="col-6 col-md-8">
                    <?= $student['last_login_at'] !== null
                        ? e(substr((string) $student['last_login_at'], 0, 16))
                        : '<span class="text-muted">' . e(__t('students.never')) . '</span>' ?>
                </dd>

                <dt class="col-6 col-md-4"><?= e(__t('students.metadata.enrollments')) ?></dt>
                <dd class="col-6 col-md-8"><?= (int) $student['enrollments_count'] ?></dd>

                <dt class="col-6 col-md-4"><?= e(__t('students.metadata.groups')) ?></dt>
                <dd class="col-6 col-md-8"><?= (int) $student['groups_count'] ?></dd>
            </dl>

            <hr>

            <div class="d-flex flex-wrap gap-2 justify-content-end">
                <button type="button" class="btn btn-sm btn-outline-warning"
                        data-bs-toggle="modal" data-bs-target="#resetPasswordModal">
                    <?= e(__t('students.password_reset.button')) ?>
                </button>

                <form method="POST" action="/teacher/students/<?= (int) $studentId ?>/toggle" class="m-0 js-toggle-form"
                      <?php if ($isActive): ?>
                          data-confirm="<?= e(__t('students.deactivate.confirm_short', ['name' => (string) $student['name']])) ?>"
                      <?php endif; ?>>
                    <?= csrf_field() ?>
                    <?php if ($isActive): ?>
                        <button type="submit" class="btn btn-sm btn-outline-danger">
                            <?= e(__t('students.action.deactivate')) ?>
                        </button>
                    <?php else: ?>
                        <button type="submit" class="btn btn-sm btn-outline-success">
                            <?= e(__t('students.action.reactivate')) ?>
                        </button>
                    <?php endif; ?>
                </form>

                <button type="button" class="btn btn-sm btn-outline-danger"
                        data-bs-toggle="modal" data-bs-target="#deleteConfirmModal"
                        data-item-name="<?= e((string) $student['email']) ?>"
                        data-action-url="/teacher/students/<?= (int) $studentId ?>/delete"
                        data-counts="<?= e(json_encode($deleteCountsFormatted, JSON_UNESCAPED_UNICODE)) ?>">
                    <?= e(__t('delete.action')) ?>
                </button>
            </div>
        </div>
    </div>
</div>

<?php require LMS_ROOT . '/src/templates/partials/delete_confirm_modal.php'; ?>

<?php if ($isActive && $availableCourses !== []): ?>
<!-- Modal: matricular em mais cursos -->
<div class="modal fade" id="enrollModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down">
        <form method="POST" action="/teacher/students/<?= (int) $studentId ?>/enroll" class="modal-content" novalidate>
            <?= csrf_field() ?>
            <div class="modal-header">
                <h5 class="modal-title"><?= e(__t('enrollments.add.title')) ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= e(__t('common.cancel')) ?>"></button>
            </div>
            <div class="modal-body">
                <label for="enrollCourseIds" class="form-label"><?= e(__t('enrollments.form.pick_courses')) ?></label>
                <select name="course_ids[]" id="enrollCourseIds" class="form-select" multiple size="8" required>
                    <?php foreach ($availableCourses as $c): ?>
                        <option value="<?= (int) $c['id'] ?>">
                            <?= e($c['name']) ?> (<?= (int) $c['year'] ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text"><?= e(__t('enrollments.form.pick_courses_hint')) ?></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?= e(__t('common.cancel')) ?></button>
                <button type="submit" class="btn btn-primary"><?= e(__t('enrollments.add.confirm')) ?></button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if ($isActive && $availableGroups !== []): ?>
<!-- Modal: atribuir aluno a grupos -->
<div class="modal fade" id="assignGroupsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down">
        <form method="POST" action="/teacher/students/<?= (int) $studentId ?>/assign-groups" class="modal-content" novalidate>
            <?= csrf_field() ?>
            <div class="modal-header">
                <h5 class="modal-title"><?= e(__t('group_members.student_section.assign_title')) ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= e(__t('common.cancel')) ?>"></button>
            </div>
            <div class="modal-body">
                <label for="assignGroupIds" class="form-label"><?= e(__t('group_members.form.pick_groups')) ?></label>
                <select name="group_ids[]" id="assignGroupIds" class="form-select" multiple size="8" required>
                    <?php foreach ($availableGroups as $g): ?>
                        <option value="<?= (int) $g['id'] ?>">
                            <?= e($g['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text"><?= e(__t('group_members.form.pick_groups_hint')) ?></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?= e(__t('common.cancel')) ?></button>
                <button type="submit" class="btn btn-primary"><?= e(__t('group_members.student_section.assign_confirm')) ?></button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Modal de reset de senha (E4-05) -->
<div class="modal fade" id="resetPasswordModal" tabindex="-1" aria-hidden="true" aria-labelledby="resetPasswordTitle">
    <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down">
        <div class="modal-content">
            <form method="POST" action="/teacher/students/<?= (int) $studentId ?>/reset-password" novalidate>
                <?= csrf_field() ?>
                <div class="modal-header">
                    <h2 class="modal-title h5" id="resetPasswordTitle">
                        <?= e(__t('students.password_reset.title', ['name' => (string) $student['name']])) ?>
                    </h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= e(__t('common.cancel')) ?>"></button>
                </div>
                <div class="modal-body">
                    <?php if (!Mailer::isConfigured()): ?>
                        <div class="alert alert-info small" role="alert">
                            <?= e(__t('students.password_reset.smtp_off_notice')) ?>
                        </div>
                    <?php endif; ?>

                    <div class="mb-3">
                        <label for="f-new-password" class="form-label">
                            <?= e(__t('students.password_reset.new_password')) ?>
                        </label>
                        <div class="input-group">
                            <input type="text" name="new_password" id="f-new-password"
                                   class="form-control form-control-lg"
                                   required minlength="8" autocomplete="new-password">
                            <button type="button" class="btn btn-outline-secondary" id="btn-gen-pass-reset">
                                <?= e(__t('students.form.password_generate')) ?>
                            </button>
                        </div>
                        <div class="form-text"><?= e(__t('students.form.password_hint')) ?></div>
                    </div>

                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="f-send-email-reset"
                               name="send_email" value="1" checked>
                        <label class="form-check-label" for="f-send-email-reset">
                            <?= e(__t('students.password_reset.send_email')) ?>
                        </label>
                    </div>

                    <p class="small text-muted mt-3 mb-0">
                        <?= e(__t('students.password_reset.note')) ?>
                    </p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                        <?= e(__t('common.cancel')) ?>
                    </button>
                    <button type="submit" class="btn btn-warning">
                        <?= e(__t('students.password_reset.confirm')) ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
window.addEventListener('load', function () {
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) {
        new bootstrap.Tooltip(el);
    });
});

document.querySelectorAll('form.js-toggle-form[data-confirm], form.js-unenroll-form[data-confirm], form.js-unassign-group-form[data-confirm]').forEach(function (form) {
    form.addEventListener('submit', function (event) {
        if (!window.confirm(form.dataset.confirm)) {
            event.preventDefault();
        }
    });
});

// "Gerar senha forte" no modal de reset — mesma lógica de new.php.
(function () {
    var btn = document.getElementById('btn-gen-pass-reset');
    var input = document.getElementById('f-new-password');
    if (!btn || !input) return;

    function pick(src) { return src.charAt(Math.floor(Math.random() * src.length)); }
    function generate() {
        var lower   = 'abcdefghijkmnpqrstuvwxyz';
        var upper   = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        var digits  = '23456789';
        var symbols = '!@#$%&*?';
        var all     = lower + upper + digits + symbols;
        var out = pick(lower) + pick(upper) + pick(digits) + pick(symbols);
        for (var i = 0; i < 8; i++) out += pick(all);
        return out.split('').sort(function () { return Math.random() - 0.5; }).join('');
    }
    btn.addEventListener('click', function () {
        input.value = generate();
        input.focus();
        input.select();
    });
})();
</script>
<?php
$page_content = ob_get_clean();
require LMS_ROOT . '/src/templates/layout.php';
