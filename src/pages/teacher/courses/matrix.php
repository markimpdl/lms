<?php
declare(strict_types=1);

/**
 * /teacher/courses/{id}/matrix — matriz alunos × CUs do curso (E11-02).
 *
 * Tabela consolidada: alunos em linhas, CUs em colunas (agrupadas por CC).
 * Cada célula mostra % + cor por status. Coluna "Média" por aluno e
 * linha "Turma" por CU na parte inferior.
 *
 * Filtros client-side (Alpine.js): grupo + só ativos.
 */

$tenantId = current_tenant_id();
if ($tenantId === null) {
    http_response_code(403);
    require LMS_ROOT . '/src/templates/errors/403.php';
    return;
}

$courseId = (int) ($_REQUEST['id'] ?? 0);

// E32-05 (ADR-033): dono OU colaborador pode ver a matriz do curso. O acesso
// e o tenant de autoria (conteúdo) vêm de effective_authoring_tenant; os
// alunos/grupos seguem por current_tenant_id() (cada professor só os seus).
$authoringTenantId = effective_authoring_tenant($courseId);
if ($authoringTenantId === null) {
    http_response_code(404);
    require LMS_ROOT . '/src/templates/errors/404.php';
    return;
}

$matrix = CourseMatrix::forCourse($courseId, $authoringTenantId, $tenantId);
if ($matrix === null) {
    http_response_code(404);
    require LMS_ROOT . '/src/templates/errors/404.php';
    return;
}

$course   = $matrix['course'];
$ccs      = $matrix['ccs'];
$students = $matrix['students'];
$cells    = $matrix['cells'];
$groups   = $matrix['groups'];

// Flatten CUs com ref ao CC pra cabeçalho
$cuColumns = [];
foreach ($ccs as $cc) {
    foreach ($cc['cus'] as $cu) {
        $cuColumns[] = [
            'cu_id'   => (int) $cu['id'],
            'cu_name' => (string) $cu['name'],
            'cc_name' => (string) $cc['name'],
        ];
    }
}
$cuCount = count($cuColumns);

// Média por aluno (average dos percents)
$avgByStudent = [];
foreach ($students as $s) {
    $sid = (int) $s['id'];
    if ($cuCount === 0) {
        $avgByStudent[$sid] = 0;
        continue;
    }
    $sum = 0;
    foreach ($cuColumns as $c) {
        $sum += (int) ($cells[$sid][$c['cu_id']]['percent'] ?? 0);
    }
    $avgByStudent[$sid] = (int) round($sum / $cuCount);
}

// JSON pra Alpine: groups + active por aluno (filter client-side)
$studentGroupsJson = json_encode(
    array_column($students, 'groups', 'id'),
    JSON_UNESCAPED_UNICODE
);
$studentActiveJson = json_encode(
    array_column($students, 'active', 'id'),
    JSON_UNESCAPED_UNICODE
);

// Bag dos status por aluno×CU pra recalcular "Média da turma" reativo:
// quando o filtro de grupo muda, a media so deve considerar alunos visiveis.
// Pre-PHP-compute distorcia o valor (ficava sempre o do conjunto inteiro).
$cellStatusByStudent = [];
foreach ($students as $s) {
    $sid = (int) $s['id'];
    $cellStatusByStudent[$sid] = [];
    foreach ($cuColumns as $c) {
        $cuId = (int) $c['cu_id'];
        $cellStatusByStudent[$sid][$cuId] = (string) ($cells[$sid][$cuId]['status'] ?? 'not_started');
    }
}
$cellStatusJson = json_encode($cellStatusByStudent, JSON_UNESCAPED_UNICODE);
$studentIdsJson = json_encode(array_map(static fn($s): int => (int) $s['id'], $students));

$page_title = __t('course_matrix.page_title', ['name' => (string) $course['name']]);

ob_start();
?>
<?= breadcrumbs([
    ['label' => __t('courses.index.title'), 'url' => '/teacher/courses'],
    ['label' => (string) $course['name'],   'url' => '/teacher/courses/' . (int) $course['id']],
    ['label' => __t('course_matrix.breadcrumb')],
]) ?>

<div x-data="{
    groupFilter: 'all',
    onlyActive: true,
    studentGroups: <?= htmlspecialchars($studentGroupsJson, ENT_QUOTES, 'UTF-8') ?>,
    studentActive: <?= htmlspecialchars($studentActiveJson, ENT_QUOTES, 'UTF-8') ?>,
    studentIds: <?= htmlspecialchars($studentIdsJson, ENT_QUOTES, 'UTF-8') ?>,
    cellStatus: <?= htmlspecialchars($cellStatusJson, ENT_QUOTES, 'UTF-8') ?>,
    matches(id) {
        if (this.onlyActive && this.studentActive[id] !== 1) return false;
        if (this.groupFilter === 'all') return true;
        if (this.groupFilter === 'none') return (this.studentGroups[id] || []).length === 0;
        const gid = parseInt(this.groupFilter, 10);
        return (this.studentGroups[id] || []).includes(gid);
    },
    classPct(cuId) {
        const visible = this.studentIds.filter(id => this.matches(id));
        if (visible.length === 0) return 0;
        const completed = visible.filter(id =>
            (this.cellStatus[id] || {})[cuId] === 'completed'
        ).length;
        return Math.round((completed / visible.length) * 100);
    },
    classPctClass(cuId) {
        const p = this.classPct(cuId);
        if (p >= 100) return 'bg-success-subtle text-success-emphasis';
        if (p > 0)    return 'bg-warning-subtle text-warning-emphasis';
        return 'bg-light text-muted';
    }
}">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
        <div>
            <h1 class="h4 mb-1"><?= e(__t('course_matrix.title')) ?></h1>
            <small class="text-muted"><?= e((string) $course['name']) ?></small>
        </div>
        <div class="d-flex gap-2 flex-wrap align-items-center">
            <select x-model="groupFilter" class="form-select form-select-sm" style="width:auto">
                <option value="all"><?= e(__t('course_matrix.filter.all_groups')) ?></option>
                <option value="none"><?= e(__t('course_matrix.filter.no_group')) ?></option>
                <?php foreach ($groups as $g): ?>
                    <option value="<?= (int) $g['id'] ?>"><?= e((string) $g['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <label class="form-check form-switch m-0">
                <input type="checkbox" class="form-check-input" role="switch" x-model="onlyActive">
                <span class="form-check-label small"><?= e(__t('course_matrix.filter.only_active')) ?></span>
            </label>
        </div>
    </div>

    <?php if ($students === []): ?>
        <div class="card card-body text-center text-muted py-5">
            <p class="lead mb-0"><?= e(__t('course_matrix.empty.students')) ?></p>
        </div>
    <?php elseif ($cuCount === 0): ?>
        <div class="card card-body text-center text-muted py-5">
            <p class="lead mb-0"><?= e(__t('course_matrix.empty.cus')) ?></p>
        </div>
    <?php else: ?>
        <div class="card shadow-sm">
            <div class="table-responsive">
                <table class="table align-middle mb-0 small">
                    <thead class="table-light">
                        <!-- Linha superior: nome da CC agrupando -->
                        <tr>
                            <th rowspan="2" style="min-width:160px"><?= e(__t('course_matrix.col.student')) ?></th>
                            <?php foreach ($ccs as $cc): $count = count($cc['cus']); if ($count === 0) continue; ?>
                                <th colspan="<?= $count ?>" class="text-center text-muted text-uppercase" style="font-size:10px; letter-spacing:.06em">
                                    <?= e((string) $cc['name']) ?>
                                </th>
                            <?php endforeach; ?>
                            <th rowspan="2" class="text-center" style="min-width:80px"><?= e(__t('course_matrix.col.avg')) ?></th>
                        </tr>
                        <tr>
                            <?php foreach ($cuColumns as $i => $c): ?>
                                <th class="text-center" style="min-width:56px" title="<?= e($c['cu_name']) ?>">
                                    <?= e(__t('course_matrix.col.cu_n', ['n' => (string) ($i + 1)])) ?>
                                </th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($students as $s): $sid = (int) $s['id']; $fullName = (string) $s['name']; $shortName = format_short_name($fullName); ?>
                            <tr x-show="matches(<?= $sid ?>)" x-transition.opacity>
                                <td>
                                    <a href="/teacher/students/<?= $sid ?>" class="text-decoration-none" title="<?= e($fullName) ?>">
                                        <?= e($shortName) ?>
                                    </a>
                                    <?php if ((int) $s['active'] === 0): ?>
                                        <span class="badge text-bg-secondary ms-1"><?= e(__t('cu_roster.badge.inactive')) ?></span>
                                    <?php endif; ?>
                                </td>
                                <?php foreach ($cuColumns as $c):
                                    $cuId = $c['cu_id'];
                                    $cell = $cells[$sid][$cuId] ?? ['status' => 'not_started', 'percent' => 0];
                                    $status  = (string) $cell['status'];
                                    $percent = (int) $cell['percent'];
                                    $bg = match ($status) {
                                        'completed'    => 'bg-success-subtle text-success-emphasis',
                                        'in_progress'  => 'bg-warning-subtle text-warning-emphasis',
                                        default        => 'bg-light text-muted',
                                    };
                                ?>
                                    <td class="text-center p-1">
                                        <a href="/teacher/cu/<?= $cuId ?>" class="d-inline-block text-decoration-none">
                                            <span class="d-inline-block px-2 py-1 rounded <?= e($bg) ?>" style="min-width:44px; font-weight:600">
                                                <?= e((string) $percent) ?>%
                                            </span>
                                        </a>
                                    </td>
                                <?php endforeach; ?>
                                <td class="text-center">
                                    <?php
                                        $avg = (int) ($avgByStudent[$sid] ?? 0);
                                        $avgBg = $avg >= 100 ? 'bg-success-subtle text-success-emphasis'
                                                             : ($avg > 0 ? 'bg-warning-subtle text-warning-emphasis'
                                                                         : 'bg-light text-muted');
                                    ?>
                                    <span class="d-inline-block px-2 py-1 rounded <?= e($avgBg) ?>" style="min-width:52px; font-weight:700">
                                        <?= e((string) $avg) ?>%
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-light">
                        <tr>
                            <th class="text-muted"><?= e(__t('course_matrix.row.class_avg')) ?></th>
                            <?php foreach ($cuColumns as $c): $cuId = (int) $c['cu_id']; ?>
                                <td class="text-center p-1">
                                    <span class="d-inline-block px-2 py-1 rounded"
                                          :class="classPctClass(<?= $cuId ?>)"
                                          style="font-size:11px"
                                          x-text="classPct(<?= $cuId ?>) + '%'"></span>
                                </td>
                            <?php endforeach; ?>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>
<?php
$page_content = ob_get_clean();
require LMS_ROOT . '/src/templates/layout.php';
