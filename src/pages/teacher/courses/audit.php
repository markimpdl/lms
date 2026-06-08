<?php
declare(strict_types=1);

/**
 * /teacher/courses/{id}/audit — auditoria de conteúdo do curso (E33 / F24).
 *
 * Lista quem criou/editou/excluiu Core Competence, Competence Unit, conteúdo,
 * atividade e avaliação, mais recentes primeiro. Visível a qualquer professor
 * com acesso ao curso (dono + colaboradores) — gate por effective_authoring_tenant
 * (E32/ADR-033). Só leitura. O actor pode ser de outro tenant (colaborador).
 */

$courseId = (int) ($_REQUEST['id'] ?? 0);

// Gate: dono OU colaborador. Mesmo padrão da matriz/ranking do curso.
$authoringTenantId = effective_authoring_tenant($courseId);
if ($authoringTenantId === null) {
    http_response_code(404);
    require LMS_ROOT . '/src/templates/errors/404.php';
    return;
}

$course = Course::findForTenant($courseId, $authoringTenantId);
if ($course === null) {
    http_response_code(404);
    require LMS_ROOT . '/src/templates/errors/404.php';
    return;
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$data = CourseAuditLog::listForCourse($courseId, $page);

// Badge de cor por ação.
$actionBadge = static function (string $action): string {
    return match ($action) {
        'create' => 'text-bg-success',
        'update' => 'text-bg-warning',
        'delete' => 'text-bg-danger',
        default  => 'text-bg-secondary',
    };
};

$page_title = __t('course_audit.page_title', ['name' => (string) $course['name']]);

ob_start();
?>
<?= breadcrumbs([
    ['label' => __t('courses.index.title'), 'url' => '/teacher/courses'],
    ['label' => (string) $course['name'],   'url' => '/teacher/courses/' . $courseId],
    ['label' => __t('course_audit.breadcrumb')],
]) ?>

<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
    <div>
        <h1 class="h4 mb-1"><?= e(__t('course_audit.title')) ?></h1>
        <small class="text-muted"><?= e((string) $course['name']) ?></small>
    </div>
    <a href="/teacher/courses/<?= $courseId ?>" class="btn btn-outline-secondary">
        <?= e(__t('common.back')) ?>
    </a>
</div>

<?php if ($data['total'] === 0): ?>
    <div class="card card-body text-center text-muted py-5">
        <p class="lead mb-0"><?= e(__t('course_audit.empty')) ?></p>
    </div>
<?php else: ?>

    <!-- ≥ lg: tabela -->
    <div class="d-none d-lg-block table-responsive">
        <table class="table align-middle small mb-0">
            <thead class="table-light">
                <tr>
                    <th><?= e(__t('course_audit.col.when')) ?></th>
                    <th><?= e(__t('course_audit.col.who')) ?></th>
                    <th><?= e(__t('course_audit.col.action')) ?></th>
                    <th><?= e(__t('course_audit.col.entity')) ?></th>
                    <th><?= e(__t('course_audit.col.label')) ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($data['rows'] as $row): ?>
                <tr>
                    <td class="text-muted text-nowrap"><?= e(substr((string) $row['created_at'], 0, 16)) ?></td>
                    <td><?= e((string) $row['actor_name']) ?></td>
                    <td>
                        <span class="badge <?= e($actionBadge((string) $row['action'])) ?>">
                            <?= e(__t('course_audit.action.' . (string) $row['action'])) ?>
                        </span>
                    </td>
                    <td><?= e(__t('course_audit.entity.' . (string) $row['entity_type'])) ?></td>
                    <td class="fw-semibold text-break"><?= e((string) $row['entity_label']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- < lg: cartões -->
    <div class="d-lg-none">
        <?php foreach ($data['rows'] as $row): ?>
            <div class="card shadow-sm mb-2">
                <div class="card-body py-2">
                    <div class="d-flex justify-content-between align-items-start gap-2 mb-1">
                        <span class="fw-semibold text-break"><?= e((string) $row['entity_label']) ?></span>
                        <span class="badge <?= e($actionBadge((string) $row['action'])) ?>">
                            <?= e(__t('course_audit.action.' . (string) $row['action'])) ?>
                        </span>
                    </div>
                    <div class="small text-muted">
                        <?= e(__t('course_audit.entity.' . (string) $row['entity_type'])) ?>
                        · <?= e((string) $row['actor_name']) ?>
                        · <?= e(substr((string) $row['created_at'], 0, 16)) ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if ($data['total_pages'] > 1): ?>
        <nav aria-label="<?= e(__t('course_audit.pagination')) ?>" class="mt-3">
            <ul class="pagination pagination-sm justify-content-center">
                <?php for ($p = 1; $p <= $data['total_pages']; $p++): ?>
                    <li class="page-item <?= $p === $data['page'] ? 'active' : '' ?>">
                        <a class="page-link" href="/teacher/courses/<?= $courseId ?>/audit?page=<?= $p ?>"><?= $p ?></a>
                    </li>
                <?php endfor; ?>
            </ul>
        </nav>
    <?php endif; ?>
<?php endif; ?>
<?php
$page_content = ob_get_clean();
require LMS_ROOT . '/src/templates/layout.php';
