<?php
declare(strict_types=1);

/**
 * /teacher/cu/{id} — detalhe da CU com conteúdo HTML (E5-01).
 *
 * Primeira tela de detalhe individual da CU (antes, CUs eram listadas dentro
 * da página da CC em /teacher/courses/{c}/cc/{cc}). Aqui o professor vê o
 * conteúdo sanitizado atual (se publicado ou rascunho), badge de status, e
 * botão para editar. E6 (atividades) e E7 (avaliação) acoplarão blocos
 * adicionais nesta mesma tela no futuro.
 */

$tenantId = current_tenant_id();
if ($tenantId === null) {
    http_response_code(403);
    require LMS_ROOT . '/src/templates/errors/403.php';
    return;
}

$cuId = (int) ($_REQUEST['id'] ?? 0);
$cu   = CompetenceUnit::findForTenant($cuId, $tenantId);
if ($cu === null) {
    http_response_code(404);
    require LMS_ROOT . '/src/templates/errors/404.php';
    return;
}

$courseId   = (int) $cu['course_id'];
$ccId       = (int) $cu['core_competency_id'];
$isArchived = (int) $cu['course_archived'] === 1;

// E25-02: link "Editar critérios" só aparece em Actvet + LO mode.
$tenant   = Tenant::findById($tenantId);
$isActvet = $tenant !== null && (int) ($tenant['is_actvet'] ?? 0) === 1;
$isLoMode = (string) ($cu['course_grading_mode'] ?? 'grade') === 'learning_outcomes';
$showLoLink = $isActvet && $isLoMode;
$loCount    = $showLoLink ? LearningOutcome::countByCu($cuId) : 0;

$content = Content::findForCu($cuId, $tenantId);
$hasContent   = $content !== null;
$isPublished  = $hasContent && (int) $content['published'] === 1;

$attachments = ContentAttachment::listByCu($cuId, $tenantId);
$activities  = Activity::listByCu($cuId, $tenantId);
$activityCount = count($activities);
$evaluation    = Evaluation::findByCu($cuId, $tenantId);
$roster        = CuRoster::listForCu($cuId, $tenantId);
$rosterCount   = count($roster);

$tree       = curriculum_tree($courseId, $tenantId);
$activeCcId = $ccId;
$activeCuId = $cuId;

// Breadcrumb precisa do nome da CC e do curso — mais barato buscar uma vez
// do que duas queries separadas. A árvore já tem tudo.
$ccName = '';
$courseName = (string) $tree['name'];
foreach ($tree['ccs'] as $navCc) {
    if ((int) $navCc['id'] === $ccId) {
        $ccName = (string) $navCc['name'];
        break;
    }
}

$page_title = (string) $cu['name'];

ob_start();
?>
<?= breadcrumbs([
    ['label' => __t('courses.index.title'), 'url' => '/teacher/courses'],
    ['label' => $courseName, 'url' => '/teacher/courses/' . $courseId],
    ['label' => $ccName,     'url' => '/teacher/courses/' . $courseId . '/cc/' . $ccId],
    ['label' => (string) $cu['name']],
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
                <h1 class="h4 mb-1"><?= e((string) $cu['name']) ?></h1>
                <?php if ($hasContent): ?>
                    <?php if ($isPublished): ?>
                        <span class="badge text-bg-success"><?= e(__t('content.status.published')) ?></span>
                    <?php else: ?>
                        <span class="badge text-bg-secondary"><?= e(__t('content.status.unpublished')) ?></span>
                    <?php endif; ?>
                <?php else: ?>
                    <span class="badge text-bg-light border"><?= e(__t('content.status.empty')) ?></span>
                <?php endif; ?>
            </div>
            <?php if (!$isArchived): ?>
                <div class="d-flex gap-2 flex-wrap">
                    <?php if ($showLoLink): ?>
                        <a href="/teacher/cu/<?= $cuId ?>/learning-outcomes"
                           class="btn btn-sm btn-outline-primary">
                            <?= e(__t('learning_outcomes.edit_link')) ?>
                            <span class="badge text-bg-light ms-1"><?= (int) $loCount ?>/5</span>
                        </a>
                    <?php endif; ?>
                    <a href="/teacher/cu/<?= $cuId ?>/content/edit"
                       class="btn btn-sm btn-primary">
                        <?= e(__t($hasContent ? 'content.edit_button' : 'content.create_button')) ?>
                    </a>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($isArchived): ?>
            <div class="alert alert-warning" role="alert">
                <?= e(__t('courses.edit.archived_notice')) ?>
            </div>
        <?php endif; ?>

        <div class="card shadow-sm mb-3">
            <div class="card-body content-render">
                <?php if ($hasContent): ?>
                    <?= (string) $content['html'] ?>
                <?php else: ?>
                    <p class="text-muted mb-0 text-center py-4">
                        <?= e(__t('content.none_yet')) ?>
                    </p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Atividades (E6-02) -->
        <div class="card shadow-sm mb-3">
            <div class="card-header d-flex align-items-center justify-content-between gap-2 flex-wrap">
                <div>
                    <h2 class="h6 mb-0"><?= e(__t('activities.section.title')) ?></h2>
                    <small class="text-muted">
                        <?= e(__t('activities.section.subtitle', ['count' => (string) $activityCount])) ?>
                    </small>
                </div>
                <?php if (!$isArchived): ?>
                    <a href="/teacher/cu/<?= $cuId ?>/activity/new" class="btn btn-sm btn-primary">
                        + <?= e(__t('activities.new_button')) ?>
                    </a>
                <?php endif; ?>
            </div>
            <?php if ($activityCount === 0): ?>
                <div class="card-body text-center text-muted py-4">
                    <p class="mb-0"><?= e(__t('activities.empty')) ?></p>
                </div>
            <?php else: ?>
                <ul class="list-group list-group-flush">
                    <?php foreach ($activities as $i => $act): ?>
                        <?php
                            $aid  = (int) $act['id'];
                            $open = (int) $act['submission_open'] === 1;
                            $subs = (int) $act['submission_count'];
                        ?>
                        <li class="list-group-item d-flex align-items-center gap-2 flex-wrap">
                            <span class="text-muted small" style="min-width: 1.5rem;"><?= $i + 1 ?>.</span>
                            <div class="flex-grow-1">
                                <a href="/teacher/activity/<?= $aid ?>/edit" class="fw-semibold text-decoration-none">
                                    <?= e((string) $act['title']) ?>
                                </a>
                                <small class="text-muted ms-2">
                                    <?= e(__t('activities.type.' . $act['type'])) ?> ·
                                    <?= (int) $act['xp_value'] ?> XP ·
                                    <?php if ($subs > 0): ?>
                                        <a href="/teacher/activity/<?= $aid ?>/submissions" class="text-decoration-none">
                                            <?= $subs ?> <?= e(__t('activities.submissions_label')) ?>
                                        </a>
                                    <?php else: ?>
                                        <?= $subs ?> <?= e(__t('activities.submissions_label')) ?>
                                    <?php endif; ?>
                                </small>
                            </div>

                            <?php if ($open): ?>
                                <span class="badge text-bg-success"><?= e(__t('activities.status.open')) ?></span>
                            <?php else: ?>
                                <span class="badge text-bg-secondary"><?= e(__t('activities.status.closed')) ?></span>
                            <?php endif; ?>

                            <?php if (!$isArchived): ?>
                                <div class="d-flex gap-1">
                                    <form method="POST" action="/teacher/activity/<?= $aid ?>/move-up" class="d-inline">
                                        <?= csrf_field() ?>
                                        <button type="submit" class="btn btn-sm btn-outline-secondary"
                                                <?= $i === 0 ? 'disabled' : '' ?>
                                                aria-label="<?= e(__t('activities.action.move_up')) ?>">↑</button>
                                    </form>
                                    <form method="POST" action="/teacher/activity/<?= $aid ?>/move-down" class="d-inline">
                                        <?= csrf_field() ?>
                                        <button type="submit" class="btn btn-sm btn-outline-secondary"
                                                <?= $i === $activityCount - 1 ? 'disabled' : '' ?>
                                                aria-label="<?= e(__t('activities.action.move_down')) ?>">↓</button>
                                    </form>
                                    <form method="POST" action="/teacher/activity/<?= $aid ?>/toggle" class="d-inline">
                                        <?= csrf_field() ?>
                                        <button type="submit" class="btn btn-sm btn-outline-<?= $open ? 'warning' : 'success' ?>">
                                            <?= e(__t($open ? 'activities.action.close' : 'activities.action.open')) ?>
                                        </button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <!-- Avaliação (E7-01) — 1 por CU (ADR-007) -->
        <div class="card shadow-sm mb-3">
            <div class="card-header d-flex align-items-center justify-content-between gap-2 flex-wrap">
                <h2 class="h6 mb-0"><?= e(__t('evaluations.section.title')) ?></h2>
                <?php if ($evaluation === null && !$isArchived): ?>
                    <a href="/teacher/cu/<?= $cuId ?>/evaluation/new" class="btn btn-sm btn-primary">
                        + <?= e(__t('evaluations.new_button')) ?>
                    </a>
                <?php endif; ?>
            </div>
            <?php if ($evaluation === null): ?>
                <div class="card-body text-center text-muted py-4">
                    <p class="mb-0"><?= e(__t('evaluations.empty')) ?></p>
                </div>
            <?php else: ?>
                <?php
                    $evId   = (int) $evaluation['id'];
                    $evOpen = (int) $evaluation['submission_open'] === 1;
                ?>
                <div class="card-body">
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <div class="flex-grow-1">
                            <a href="/teacher/evaluation/<?= $evId ?>/edit" class="fw-semibold text-decoration-none">
                                <?= e((string) $evaluation['title']) ?>
                            </a>
                            <small class="text-muted ms-2">
                                <?= (int) $evaluation['xp_value'] ?> XP
                                <?php if ($evaluation['pdf_path'] !== null): ?>
                                    · <?= e(__t('evaluations.badge.has_pdf')) ?>
                                <?php endif; ?>
                            </small>
                        </div>
                        <?php if ($evOpen): ?>
                            <span class="badge text-bg-success"><?= e(__t('evaluations.status.open')) ?></span>
                        <?php else: ?>
                            <span class="badge text-bg-secondary"><?= e(__t('evaluations.status.closed')) ?></span>
                        <?php endif; ?>
                        <?php if (!$isArchived): ?>
                            <a href="/teacher/evaluation/<?= $evId ?>/edit" class="btn btn-sm btn-outline-secondary">
                                <?= e(__t('common.edit')) ?>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Alunos (E11-03) -->
        <?php if ($rosterCount > 0): ?>
        <div class="card shadow-sm mb-3" x-data="{ onlyActive: true }">
            <div class="card-header d-flex align-items-center justify-content-between gap-2 flex-wrap">
                <div>
                    <h2 class="h6 mb-0"><?= e(__t('cu_roster.title')) ?></h2>
                    <small class="text-muted">
                        <?= e(__t('cu_roster.subtitle', ['count' => (string) $rosterCount])) ?>
                    </small>
                </div>
                <label class="form-check form-switch m-0">
                    <input type="checkbox" class="form-check-input" role="switch" x-model="onlyActive">
                    <span class="form-check-label small"><?= e(__t('cu_roster.only_active')) ?></span>
                </label>
            </div>

            <!-- Tabela (≥ md) -->
            <div class="d-none d-md-block table-responsive">
                <table class="table align-middle mb-0 small">
                    <thead class="table-light">
                        <tr>
                            <th><?= e(__t('cu_roster.col.student')) ?></th>
                            <?php foreach ($activities as $i => $act): ?>
                                <th class="text-center" style="min-width:70px" title="<?= e((string) $act['title']) ?>">
                                    <span class="badge text-bg-light border"><?= e(__t('cu_roster.col.activity_n', ['n' => (string) ($i + 1)])) ?></span>
                                </th>
                            <?php endforeach; ?>
                            <?php if ($evaluation !== null): ?>
                                <th class="text-center" style="min-width:80px"><?= e(__t('cu_roster.col.evaluation')) ?></th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($roster as $r): ?>
                            <tr x-show="!onlyActive || <?= (int) $r['active'] ?> === 1" x-transition.opacity>
                                <td>
                                    <a href="/teacher/students/<?= (int) $r['id'] ?>" class="text-decoration-none" title="<?= e((string) $r['name']) ?>">
                                        <?= e(format_short_name((string) $r['name'])) ?>
                                    </a>
                                    <?php if ((int) $r['active'] === 0): ?>
                                        <span class="badge text-bg-secondary ms-1"><?= e(__t('cu_roster.badge.inactive')) ?></span>
                                    <?php endif; ?>
                                </td>
                                <?php foreach ($activities as $act):
                                    $aid    = (int) $act['id'];
                                    $status = (string) ($r['activity_statuses'][$aid] ?? 'not_submitted');
                                    [$bg, $icon, $label] = match ($status) {
                                        'with_feedback' => ['bg-success-subtle text-success-emphasis', '✓', __t('cu_roster.activity.with_feedback')],
                                        'pending'       => ['bg-warning-subtle text-warning-emphasis', '⏳', __t('cu_roster.activity.pending')],
                                        default         => ['bg-light text-muted',                     '—', __t('cu_roster.activity.not_submitted')],
                                    };
                                    $cellContent = '<span class="d-inline-block px-2 py-1 rounded ' . $bg . '" title="' . e($label) . '">' . $icon . '</span>';
                                    $cellHref = $status !== 'not_submitted'
                                        ? '/teacher/activity/' . $aid . '/submission/' . (int) $r['id']
                                        : null;
                                ?>
                                    <td class="text-center">
                                        <?php if ($cellHref !== null): ?>
                                            <a href="<?= e($cellHref) ?>" class="text-decoration-none"><?= $cellContent ?></a>
                                        <?php else: ?>
                                            <?= $cellContent ?>
                                        <?php endif; ?>
                                    </td>
                                <?php endforeach; ?>
                                <?php if ($evaluation !== null):
                                    $ev = $r['evaluation_status'];
                                    $evState = (string) $ev['state'];
                                    $gradeStr = isset($ev['grade']) && $ev['grade'] !== null
                                        ? number_format((float) $ev['grade'], 1, ',', '')
                                        : null;
                                    [$bg2, $label2] = match ($evState) {
                                        'approved' => ['bg-success-subtle text-success-emphasis', $gradeStr . ' · ' . __t('cu_roster.evaluation.approved')],
                                        'failed'   => ['bg-danger-subtle text-danger-emphasis',   $gradeStr . ' · ' . __t('cu_roster.evaluation.failed')],
                                        'retry'    => ['bg-info-subtle text-info-emphasis',      $gradeStr . ' · ' . __t('cu_roster.evaluation.retry')],
                                        'pending'  => ['bg-warning-subtle text-warning-emphasis', __t('cu_roster.evaluation.pending')],
                                        default    => ['bg-light text-muted',                    __t('cu_roster.evaluation.not_submitted')],
                                    };
                                    $evCellContent = '<span class="d-inline-block px-2 py-1 rounded ' . $bg2 . '">' . e($label2) . '</span>';
                                    $evHref = $evState !== 'not_submitted'
                                        ? '/teacher/evaluation/' . (int) $evaluation['id'] . '/submission/' . (int) $r['id']
                                        : null;
                                ?>
                                    <td class="text-center">
                                        <?php if ($evHref !== null): ?>
                                            <a href="<?= e($evHref) ?>" class="text-decoration-none"><?= $evCellContent ?></a>
                                        <?php else: ?>
                                            <?= $evCellContent ?>
                                        <?php endif; ?>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Cards (< md) -->
            <div class="d-md-none">
                <ul class="list-group list-group-flush">
                    <?php foreach ($roster as $r): ?>
                        <li class="list-group-item" x-show="!onlyActive || <?= (int) $r['active'] ?> === 1" x-transition.opacity>
                            <div class="d-flex align-items-center gap-2 mb-2">
                                <a href="/teacher/students/<?= (int) $r['id'] ?>" class="fw-semibold text-decoration-none flex-grow-1" title="<?= e((string) $r['name']) ?>">
                                    <?= e(format_short_name((string) $r['name'])) ?>
                                </a>
                                <?php if ((int) $r['active'] === 0): ?>
                                    <span class="badge text-bg-secondary"><?= e(__t('cu_roster.badge.inactive')) ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="d-flex flex-wrap gap-1">
                                <?php foreach ($activities as $i => $act):
                                    $aid    = (int) $act['id'];
                                    $status = (string) ($r['activity_statuses'][$aid] ?? 'not_submitted');
                                    [$bg, $icon] = match ($status) {
                                        'with_feedback' => ['bg-success-subtle text-success-emphasis', '✓'],
                                        'pending'       => ['bg-warning-subtle text-warning-emphasis', '⏳'],
                                        default         => ['bg-light text-muted',                     '—'],
                                    };
                                ?>
                                    <span class="badge <?= e($bg) ?>" title="<?= e((string) $act['title']) ?>">
                                        <?= e(__t('cu_roster.col.activity_n', ['n' => (string) ($i + 1)])) ?> <?= $icon ?>
                                    </span>
                                <?php endforeach; ?>
                                <?php if ($evaluation !== null):
                                    $ev = $r['evaluation_status'];
                                    $evState = (string) $ev['state'];
                                    $gradeStr = isset($ev['grade']) && $ev['grade'] !== null
                                        ? number_format((float) $ev['grade'], 1, ',', '')
                                        : '';
                                    [$bg2, $icon2] = match ($evState) {
                                        'approved' => ['bg-success-subtle text-success-emphasis', '✓ ' . $gradeStr],
                                        'failed'   => ['bg-danger-subtle text-danger-emphasis',   '✗ ' . $gradeStr],
                                        'retry'    => ['bg-info-subtle text-info-emphasis',      '↻ ' . $gradeStr],
                                        'pending'  => ['bg-warning-subtle text-warning-emphasis', '⏳'],
                                        default    => ['bg-light text-muted',                    '—'],
                                    };
                                ?>
                                    <span class="badge <?= e($bg2) ?>">
                                        <?= e(__t('cu_roster.col.evaluation')) ?> <?= e($icon2) ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($attachments !== []): ?>
            <div class="card shadow-sm">
                <div class="card-header">
                    <h2 class="h6 mb-0"><?= e(__t('student.content.attachments_title')) ?></h2>
                </div>
                <ul class="list-group list-group-flush">
                    <?php foreach ($attachments as $att): ?>
                        <?php $aid = (int) $att['id']; ?>
                        <li class="list-group-item d-flex align-items-center gap-2 flex-wrap">
                            <div class="flex-grow-1">
                                <a href="/teacher/cu/<?= $cuId ?>/attachment/<?= $aid ?>"
                                   class="fw-semibold text-decoration-none">
                                    <?= e((string) $att['filename']) ?>
                                </a>
                                <small class="text-muted ms-2">
                                    <?= e((string) $att['mime']) ?> ·
                                    <?= e(number_format((int) $att['size_bytes'] / 1024, 1, ',', '.')) ?> KB
                                </small>
                            </div>
                            <a href="/teacher/cu/<?= $cuId ?>/attachment/<?= $aid ?>"
                               class="btn btn-sm btn-outline-primary">
                                <?= e(__t('student.content.download')) ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Offcanvas (mobile) -->
<div class="offcanvas offcanvas-start" tabindex="-1" id="curriculumOffcanvas" aria-labelledby="curriculumOffcanvasLabel">
    <div class="offcanvas-header">
        <h5 class="offcanvas-title" id="curriculumOffcanvasLabel"><?= e(__t('nav.curriculum.title')) ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body">
        <?php require LMS_ROOT . '/src/templates/partials/curriculum_nav.php'; ?>
    </div>
</div>

<style>
.content-render img          { max-width: 100%; height: auto; }
.content-render table        { width: 100%; }
.content-render iframe       { width: 100%; aspect-ratio: 16 / 9; border: 0; }
.content-render pre:not([class*="language-"]) { background: #f6f8fa; padding: .75rem; border-radius: .25rem; overflow-x: auto; }
.content-render pre[class*="language-"]       { margin: .75rem 0; border-radius: .25rem; }
.content-render blockquote   { border-left: 3px solid #dee2e6; padding-left: 1rem; color: #6c757d; }
</style>
<?php
$page_content = ob_get_clean();
require LMS_ROOT . '/src/templates/layout.php';
