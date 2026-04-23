<?php
declare(strict_types=1);

/**
 * /teacher/groups/{id} — detalhe do grupo (E4-04).
 * Mostra nome do grupo, lista paginada de membros e modal para adicionar
 * alunos via multi-select (só alunos ativos do tenant que ainda não são
 * membros). Remover membro é submit inline com confirmação JS.
 */

$tenantId = current_tenant_id();
if ($tenantId === null) {
    http_response_code(403);
    require LMS_ROOT . '/src/templates/errors/403.php';
    return;
}

$groupId = (int) ($_REQUEST['id'] ?? 0);
$group   = Group::findForTenant($groupId, $tenantId);
if ($group === null) {
    http_response_code(404);
    require LMS_ROOT . '/src/templates/errors/404.php';
    return;
}

$page    = max(1, (int) ($_GET['page'] ?? 1));
$members = GroupMember::listByGroup($groupId, $tenantId, $page);

$memberIds       = GroupMember::memberIds($groupId, $tenantId);
$availableStudents = array_values(array_filter(
    Student::listActiveForSelect($tenantId),
    static fn(array $s): bool => !in_array($s['id'], $memberIds, true)
));

$page_title = (string) $group['name'];

ob_start();
?>
<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
    <div>
        <nav aria-label="breadcrumb" class="small mb-1">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="/teacher/groups" class="text-decoration-none"><?= e(__t('groups.title')) ?></a></li>
                <li class="breadcrumb-item active" aria-current="page"><?= e((string) $group['name']) ?></li>
            </ol>
        </nav>
        <h1 class="h4 mb-0"><?= e((string) $group['name']) ?></h1>
        <small class="text-muted">
            <?= e(__t('group_members.section.subtitle', ['count' => (string) $members['total']])) ?>
        </small>
    </div>
    <?php if ($availableStudents !== []): ?>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addMemberModal">
            + <?= e(__t('group_members.add_button')) ?>
        </button>
    <?php endif; ?>
</div>

<?php if ($members['total'] === 0): ?>
    <div class="card card-body text-center text-muted py-5">
        <p class="lead mb-2"><?= e(__t('group_members.empty.title')) ?></p>
        <?php if ($availableStudents !== []): ?>
            <p class="small mb-3"><?= e(__t('group_members.empty.hint')) ?></p>
            <div>
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addMemberModal">
                    + <?= e(__t('group_members.add_button')) ?>
                </button>
            </div>
        <?php else: ?>
            <p class="small mb-0"><?= e(__t('group_members.empty.no_students_hint')) ?></p>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="card shadow-sm">
        <ul class="list-group list-group-flush">
            <?php foreach ($members['rows'] as $m): ?>
                <li class="list-group-item d-flex align-items-center gap-2 flex-wrap">
                    <div class="flex-grow-1">
                        <a href="/teacher/students/<?= (int) $m['student_id'] ?>" class="fw-semibold text-decoration-none">
                            <?= e((string) $m['name']) ?>
                        </a>
                        <small class="text-muted ms-2 text-break"><?= e((string) $m['email']) ?></small>
                        <?php if ((int) $m['active'] === 0): ?>
                            <span class="badge text-bg-secondary ms-2"><?= e(__t('students.status.inactive')) ?></span>
                        <?php endif; ?>
                        <div class="small text-muted">
                            <?= e(__t('group_members.joined_at', ['date' => substr((string) $m['joined_at'], 0, 10)])) ?>
                        </div>
                    </div>
                    <form method="POST"
                          action="/teacher/groups/<?= $groupId ?>/remove-member/<?= (int) $m['student_id'] ?>"
                          class="m-0 js-remove-member-form"
                          data-confirm="<?= e(__t('group_members.remove_confirm', ['name' => (string) $m['name']])) ?>">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-sm btn-outline-danger">
                            <?= e(__t('group_members.action.remove')) ?>
                        </button>
                    </form>
                </li>
            <?php endforeach; ?>
        </ul>

        <?php if ($members['total_pages'] > 1): ?>
            <nav aria-label="<?= e(__t('group_members.pagination')) ?>" class="p-2">
                <ul class="pagination pagination-sm justify-content-center mb-0">
                    <?php for ($p = 1; $p <= $members['total_pages']; $p++): ?>
                        <li class="page-item <?= $p === $members['page'] ? 'active' : '' ?>">
                            <a class="page-link" href="/teacher/groups/<?= $groupId ?>?page=<?= $p ?>"><?= $p ?></a>
                        </li>
                    <?php endfor; ?>
                </ul>
            </nav>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if ($availableStudents !== []): ?>
<!-- Modal: adicionar membros -->
<div class="modal fade" id="addMemberModal" tabindex="-1" aria-hidden="true" aria-labelledby="addMemberModalLabel">
    <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down">
        <form method="POST" action="/teacher/groups/<?= $groupId ?>/add-member" class="modal-content" novalidate>
            <?= csrf_field() ?>
            <div class="modal-header">
                <h5 class="modal-title" id="addMemberModalLabel"><?= e(__t('group_members.add.title')) ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= e(__t('common.cancel')) ?>"></button>
            </div>
            <div class="modal-body">
                <label for="addMemberStudentIds" class="form-label"><?= e(__t('group_members.form.pick_students')) ?></label>
                <select name="student_ids[]" id="addMemberStudentIds" class="form-select" multiple size="8" required>
                    <?php foreach ($availableStudents as $s): ?>
                        <option value="<?= (int) $s['id'] ?>">
                            <?= e($s['name']) ?> (<?= e($s['email']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text"><?= e(__t('group_members.form.pick_students_hint')) ?></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?= e(__t('common.cancel')) ?></button>
                <button type="submit" class="btn btn-primary"><?= e(__t('group_members.add.confirm')) ?></button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
document.querySelectorAll('form.js-remove-member-form[data-confirm]').forEach(function (form) {
    form.addEventListener('submit', function (event) {
        if (!window.confirm(form.dataset.confirm)) {
            event.preventDefault();
        }
    });
});
</script>
<?php
$page_content = ob_get_clean();
require LMS_ROOT . '/src/templates/layout.php';
