<?php
declare(strict_types=1);

/**
 * /admin/teachers — listagem administrativa de professores (E2-01).
 *
 * Auth + papel garantidos pelo front controller via src/routes.php.
 *
 * Os filtros vão e voltam por query string (GET puro, shareable por URL).
 * A ordenação também: cada cabeçalho troca `sort` e `dir` preservando os
 * demais parâmetros. Os botões "Abrir" e "Desativar/Reativar" por linha
 * ficam desabilitados até a implementação de E2-03 e E2-04.
 */

$filters = [
    'q'        => (string) ($_GET['q']        ?? ''),
    'status'   => (string) ($_GET['status']   ?? ''),
    'language' => (string) ($_GET['language'] ?? ''),
    'sort'     => (string) ($_GET['sort']     ?? 'created_at'),
    'dir'      => (string) ($_GET['dir']      ?? 'DESC'),
];
$page = max(1, (int) ($_GET['page'] ?? 1));

$result = TeacherAdmin::list($filters, $page);
$noFilters = $filters['q'] === '' && $filters['status'] === '' && $filters['language'] === '';

/** URL interna da listagem preservando filtros e sobrescrevendo chaves. */
$teachersUrl = static function (array $overrides) use ($filters, $page): string {
    $params = array_merge($filters, ['page' => $page], $overrides);
    foreach ($params as $k => $v) {
        if ($v === '' || $v === null) {
            unset($params[$k]);
        }
    }
    $qs = http_build_query($params);
    return '/admin/teachers' . ($qs !== '' ? '?' . $qs : '');
};

/** Cabeçalho ordenável: inverte dir se já está ordenando por esta coluna. */
$sortable = static function (string $key, string $label) use ($filters, $teachersUrl): string {
    $isCurrent = $filters['sort'] === $key;
    $nextDir   = $isCurrent && strtoupper($filters['dir']) === 'ASC' ? 'DESC' : 'ASC';
    $arrow = '';
    if ($isCurrent) {
        $arrow = ' <span class="text-muted small">' . (strtoupper($filters['dir']) === 'ASC' ? '▲' : '▼') . '</span>';
    }
    return '<a class="text-decoration-none text-reset" href="'
        . e($teachersUrl(['sort' => $key, 'dir' => $nextDir, 'page' => 1]))
        . '">' . e($label) . $arrow . '</a>';
};

// Credenciais recém-geradas (E2-02) — mostradas UMA vez e drenadas da sessão.
$justCreated = $_SESSION['teacher_creds_once'] ?? null;
unset($_SESSION['teacher_creds_once']);

$page_title = __t('admin.teachers.title');

ob_start();
?>
<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
    <h1 class="h4 mb-0"><?= e(__t('admin.teachers.title')) ?></h1>
    <a href="/admin/teachers/new" class="btn btn-primary">
        <?= e(__t('admin.teachers.new')) ?>
    </a>
</div>

<?php if ($justCreated !== null): ?>
    <div class="alert alert-success" role="alert">
        <h2 class="h6 mb-2"><?= e(__t('admin.teachers.creds_title', ['name' => $justCreated['name']])) ?></h2>
        <p class="small mb-2">
            <?= e(__t(
                $justCreated['reason'] === 'smtp_unavailable'
                    ? 'admin.teachers.creds_smtp_off'
                    : 'admin.teachers.creds_opted_out'
            )) ?>
        </p>
        <dl class="row mb-0 small">
            <dt class="col-4 col-md-3"><?= e(__t('auth.email')) ?></dt>
            <dd class="col-8 col-md-9"><code><?= e($justCreated['email']) ?></code></dd>
            <dt class="col-4 col-md-3"><?= e(__t('admin.teachers.form.password')) ?></dt>
            <dd class="col-8 col-md-9"><code><?= e($justCreated['password']) ?></code></dd>
            <dt class="col-4 col-md-3"><?= e(__t('admin.teachers.form.tenant_name')) ?></dt>
            <dd class="col-8 col-md-9"><?= e($justCreated['tenant_name']) ?></dd>
        </dl>
    </div>
<?php endif; ?>

<form method="GET" action="/admin/teachers" class="card card-body mb-3 shadow-sm">
    <div class="row g-2 align-items-end">
        <div class="col-12 col-md-5">
            <label for="f-q" class="form-label"><?= e(__t('admin.teachers.search')) ?></label>
            <input type="search" name="q" id="f-q" class="form-control"
                   value="<?= e($filters['q']) ?>"
                   placeholder="<?= e(__t('admin.teachers.search_placeholder')) ?>">
        </div>
        <div class="col-6 col-md-3">
            <label for="f-status" class="form-label"><?= e(__t('admin.teachers.status')) ?></label>
            <select name="status" id="f-status" class="form-select">
                <option value=""         <?= $filters['status'] === ''         ? 'selected' : '' ?>><?= e(__t('admin.teachers.filter_all')) ?></option>
                <option value="active"   <?= $filters['status'] === 'active'   ? 'selected' : '' ?>><?= e(__t('admin.teachers.filter_active')) ?></option>
                <option value="inactive" <?= $filters['status'] === 'inactive' ? 'selected' : '' ?>><?= e(__t('admin.teachers.filter_inactive')) ?></option>
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label for="f-lang" class="form-label"><?= e(__t('admin.teachers.language')) ?></label>
            <select name="language" id="f-lang" class="form-select">
                <option value=""   <?= $filters['language'] === ''   ? 'selected' : '' ?>><?= e(__t('admin.teachers.filter_all')) ?></option>
                <option value="pt" <?= $filters['language'] === 'pt' ? 'selected' : '' ?>>PT</option>
                <option value="en" <?= $filters['language'] === 'en' ? 'selected' : '' ?>>EN</option>
            </select>
        </div>
        <div class="col-12 col-md-2 d-grid">
            <button type="submit" class="btn btn-outline-primary"><?= e(__t('common.search')) ?></button>
        </div>
    </div>
</form>

<?php if ($result['total'] === 0): ?>
    <div class="card card-body text-center py-5 shadow-sm">
        <?php if ($noFilters): ?>
            <p class="lead mb-3"><?= e(__t('admin.teachers.empty')) ?></p>
            <div>
                <a href="/admin/teachers/new" class="btn btn-primary">
                    <?= e(__t('admin.teachers.empty_cta')) ?>
                </a>
            </div>
        <?php else: ?>
            <p class="text-muted mb-0"><?= e(__t('admin.teachers.no_results')) ?></p>
        <?php endif; ?>
    </div>
<?php else: ?>

    <!-- ≥ lg: tabela -->
    <div class="table-responsive d-none d-lg-block">
        <table class="table table-hover align-middle">
            <thead>
                <tr>
                    <th><?= $sortable('name', __t('admin.teachers.name')) ?></th>
                    <th><?= $sortable('email', __t('auth.email')) ?></th>
                    <th><?= $sortable('language', __t('admin.teachers.language')) ?></th>
                    <th><?= $sortable('active', __t('admin.teachers.status')) ?></th>
                    <th class="text-end"><?= $sortable('active_courses', __t('admin.teachers.courses')) ?></th>
                    <th class="text-end"><?= $sortable('unique_students', __t('admin.teachers.students')) ?></th>
                    <th><?= $sortable('created_at', __t('admin.teachers.created_at')) ?></th>
                    <th><?= $sortable('last_login_at', __t('admin.teachers.last_login')) ?></th>
                    <th class="text-end"><?= e(__t('admin.teachers.actions')) ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($result['rows'] as $row): ?>
                    <tr>
                        <td><?= e($row['name']) ?></td>
                        <td class="text-break"><?= e($row['email']) ?></td>
                        <td><?= e(strtoupper((string) $row['language'])) ?></td>
                        <td>
                            <?php if ((int) $row['active'] === 1): ?>
                                <span class="badge bg-success-subtle text-success border border-success-subtle"><?= e(__t('admin.teachers.active')) ?></span>
                            <?php else: ?>
                                <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle"><?= e(__t('admin.teachers.inactive')) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end"><?= (int) $row['active_courses'] ?></td>
                        <td class="text-end"><?= (int) $row['unique_students'] ?></td>
                        <td><?= e(substr((string) $row['created_at'], 0, 10)) ?></td>
                        <td>
                            <?= $row['last_login_at'] !== null
                                ? e(substr((string) $row['last_login_at'], 0, 16))
                                : '<span class="text-muted">' . e(__t('admin.teachers.never')) . '</span>' ?>
                        </td>
                        <td class="text-end">
                            <div class="btn-group btn-group-sm" role="group">
                                <a href="/admin/teachers/<?= (int) $row['id'] ?>" class="btn btn-outline-primary">
                                    <?= e(__t('admin.teachers.open')) ?>
                                </a>
                                <?php if ((int) $row['active'] === 1): ?>
                                    <a href="#" class="btn btn-outline-secondary disabled" aria-disabled="true" tabindex="-1">
                                        <?= e(__t('admin.teachers.deactivate')) ?>
                                    </a>
                                <?php else: ?>
                                    <a href="#" class="btn btn-outline-success disabled" aria-disabled="true" tabindex="-1">
                                        <?= e(__t('admin.teachers.reactivate')) ?>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- < lg: cartões -->
    <div class="d-lg-none">
        <?php foreach ($result['rows'] as $row): ?>
            <div class="card shadow-sm mb-2">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                        <div class="fw-semibold"><?= e($row['name']) ?></div>
                        <?php if ((int) $row['active'] === 1): ?>
                            <span class="badge bg-success-subtle text-success border border-success-subtle"><?= e(__t('admin.teachers.active')) ?></span>
                        <?php else: ?>
                            <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle"><?= e(__t('admin.teachers.inactive')) ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="text-muted small text-break mb-2"><?= e($row['email']) ?></div>
                    <div class="d-flex flex-wrap gap-3 small mb-2">
                        <div><strong><?= e(__t('admin.teachers.language')) ?>:</strong> <?= e(strtoupper((string) $row['language'])) ?></div>
                        <div><strong><?= e(__t('admin.teachers.courses')) ?>:</strong> <?= (int) $row['active_courses'] ?></div>
                        <div><strong><?= e(__t('admin.teachers.students')) ?>:</strong> <?= (int) $row['unique_students'] ?></div>
                    </div>
                    <div class="small text-muted mb-2">
                        <div><strong><?= e(__t('admin.teachers.created_at')) ?>:</strong> <?= e(substr((string) $row['created_at'], 0, 10)) ?></div>
                        <div>
                            <strong><?= e(__t('admin.teachers.last_login')) ?>:</strong>
                            <?= $row['last_login_at'] !== null
                                ? e(substr((string) $row['last_login_at'], 0, 16))
                                : '<span class="text-muted">' . e(__t('admin.teachers.never')) . '</span>' ?>
                        </div>
                    </div>
                    <div class="d-flex gap-2">
                        <a href="/admin/teachers/<?= (int) $row['id'] ?>" class="btn btn-sm btn-outline-primary">
                            <?= e(__t('admin.teachers.open')) ?>
                        </a>
                        <?php if ((int) $row['active'] === 1): ?>
                            <a href="#" class="btn btn-sm btn-outline-secondary disabled" aria-disabled="true" tabindex="-1">
                                <?= e(__t('admin.teachers.deactivate')) ?>
                            </a>
                        <?php else: ?>
                            <a href="#" class="btn btn-sm btn-outline-success disabled" aria-disabled="true" tabindex="-1">
                                <?= e(__t('admin.teachers.reactivate')) ?>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if ($result['total_pages'] > 1): ?>
        <nav class="mt-3" aria-label="<?= e(__t('admin.teachers.pagination')) ?>">
            <ul class="pagination justify-content-center mb-0">
                <li class="page-item<?= $result['page'] <= 1 ? ' disabled' : '' ?>">
                    <a class="page-link" href="<?= e($teachersUrl(['page' => $result['page'] - 1])) ?>">
                        <?= e(__t('admin.teachers.prev')) ?>
                    </a>
                </li>
                <li class="page-item disabled">
                    <span class="page-link">
                        <?= e(__t('admin.teachers.page_of', ['page' => $result['page'], 'total' => $result['total_pages']])) ?>
                    </span>
                </li>
                <li class="page-item<?= $result['page'] >= $result['total_pages'] ? ' disabled' : '' ?>">
                    <a class="page-link" href="<?= e($teachersUrl(['page' => $result['page'] + 1])) ?>">
                        <?= e(__t('admin.teachers.next')) ?>
                    </a>
                </li>
            </ul>
        </nav>
    <?php endif; ?>

<?php endif; ?>
<?php
$page_content = ob_get_clean();

require LMS_ROOT . '/src/templates/layout.php';
