<?php
declare(strict_types=1);

/**
 * /student/ranking — ranking do tenant pro aluno (E9-03 + filtros E9-04).
 *
 * 3 janelas (Geral / 7d / 30d) selecionáveis via `?window=`. Linha do aluno
 * logado destacada. Paginação 50/página via `?page=`. Filtros opcionais de
 * grupo (`?group=ID`) e ano (`?year=YYYY` ou `all`); ano default = ano
 * corrente. Spec: `doc/08-gamificacao-e-ranking.md`.
 *
 * Auth + papel garantidos pelo front controller; tenant_id vem do user da
 * sessão e o `RankingService` faz o filtro multi-tenant.
 */

$user      = current_user();
$studentId = (int) ($user['id'] ?? 0);
$tenantId  = (int) ($user['tenant_id'] ?? 0);

// Janela: whitelist + fallback silencioso.
$window = (string) ($_GET['window'] ?? 'all');
if (!in_array($window, RankingService::WINDOWS, true)) {
    $window = 'all';
}

// Lista de grupos do tenant (pra dropdown e validação de input).
$groups = $tenantId > 0 ? Group::listForSelect($tenantId) : [];

// Lista de anos com eventos no tenant (pra dropdown). Ordenada DESC.
$years = [];
if ($tenantId > 0) {
    $stmt = Database::pdo()->prepare(
        'SELECT DISTINCT YEAR(created_at) AS y
           FROM xp_events
          WHERE tenant_id = ?
          ORDER BY y DESC'
    );
    $stmt->execute([$tenantId]);
    $years = array_map(static fn (array $r): int => (int) $r['y'], $stmt->fetchAll());
}

// Filtro de grupo: precisa existir no tenant; senão ignora silenciosamente.
$rawGroupId = (int) ($_GET['group'] ?? 0);
$groupId    = null;
if ($rawGroupId > 0) {
    foreach ($groups as $g) {
        if ((int) $g['id'] === $rawGroupId) {
            $groupId = $rawGroupId;
            break;
        }
    }
}

// Filtro de ano:
//   - sem `?year=` na URL → default = ano corrente
//   - `?year=all`         → "todos os anos" (null no Service)
//   - `?year=YYYY`        → válido entre 2020-2099, senão fallback pro corrente
$currentYear = (int) date('Y');
$rawYear     = $_GET['year'] ?? null;
if ($rawYear === 'all') {
    $year = null;
} elseif ($rawYear === null) {
    $year = $currentYear;
} else {
    $rawYearInt = (int) $rawYear;
    $year = ($rawYearInt >= 2020 && $rawYearInt <= 2099) ? $rawYearInt : $currentYear;
}

$page    = max(1, (int) ($_GET['page'] ?? 1));
$perPage = RankingService::DEFAULT_PER_PAGE;

$filters = [];
if ($groupId !== null) { $filters['group_id'] = $groupId; }
if ($year    !== null) { $filters['year']     = $year; }

$result = $tenantId > 0
    ? RankingService::compute($tenantId, $window, $filters, $page, $perPage)
    : ['rows' => [], 'total' => 0];
$rows     = $result['rows'];
$total    = $result['total'];
$lastPage = max(1, (int) ceil($total / $perPage));

// Builder de querystring que preserva o estado completo dos filtros. Aceita
// overrides; passar null em uma chave remove. Também resseta `page` quando
// se troca janela ou filtros (overrides explícitos passam por cima).
$qs = static function (array $overrides = []) use ($window, $groupId, $year, $page): string {
    $params = ['window' => $window, 'page' => (string) $page];
    if ($groupId !== null) { $params['group'] = (string) $groupId; }
    if ($year    !== null) { $params['year']  = (string) $year; }
    foreach ($overrides as $k => $v) {
        if ($v === null) {
            unset($params[$k]);
        } else {
            $params[$k] = (string) $v;
        }
    }
    return '?' . http_build_query($params);
};

$page_title = __t('ranking.title');

ob_start();
?>
<header class="lms-dashboard-header lms-ranking-header">
    <div>
        <span class="lms-dashboard-eyebrow"><?= e(__t('ranking.eyebrow')) ?></span>
        <h1 class="lms-dashboard-title"><?= e(__t('ranking.title')) ?></h1>
        <p class="lms-dashboard-subtitle"><?= e(__t('ranking.subtitle')) ?></p>
    </div>

    <div class="lms-filter-pills" role="tablist" aria-label="<?= e(__t('ranking.window.aria')) ?>">
        <?php foreach (RankingService::WINDOWS as $w): ?>
            <a class="lms-filter-pill <?= $w === $window ? 'is-active' : '' ?>"
               href="<?= e($qs(['window' => $w, 'page' => 1])) ?>"
               role="tab"
               aria-selected="<?= $w === $window ? 'true' : 'false' ?>">
                <?= e(__t('ranking.window.' . $w)) ?>
            </a>
        <?php endforeach; ?>
    </div>
</header>

<form method="get" class="lms-ranking-filters" aria-label="<?= e(__t('ranking.filter.aria')) ?>">
    <input type="hidden" name="window" value="<?= e($window) ?>">

    <label class="lms-ranking-filter">
        <span class="lms-ranking-filter__label"><?= e(__t('ranking.filter.group')) ?></span>
        <select name="group" class="form-select form-select-sm">
            <option value=""><?= e(__t('ranking.filter.all_groups')) ?></option>
            <?php foreach ($groups as $g): ?>
                <option value="<?= (int) $g['id'] ?>" <?= $groupId === (int) $g['id'] ? 'selected' : '' ?>>
                    <?= e((string) $g['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </label>

    <label class="lms-ranking-filter">
        <span class="lms-ranking-filter__label"><?= e(__t('ranking.filter.year')) ?></span>
        <select name="year" class="form-select form-select-sm">
            <option value="all" <?= $year === null ? 'selected' : '' ?>>
                <?= e(__t('ranking.filter.all_years')) ?>
            </option>
            <?php foreach ($years as $y): ?>
                <option value="<?= (int) $y ?>" <?= $year === (int) $y ? 'selected' : '' ?>>
                    <?= (int) $y ?>
                </option>
            <?php endforeach; ?>
            <?php if ($year !== null && !in_array($year, $years, true)): ?>
                <option value="<?= (int) $year ?>" selected>
                    <?= (int) $year ?>
                </option>
            <?php endif; ?>
        </select>
    </label>

    <button type="submit" class="btn btn-primary btn-sm lms-ranking-filter__submit">
        <?= e(__t('ranking.filter.apply')) ?>
    </button>
</form>

<?php if ($rows === []): ?>
    <div class="lms-dashboard-empty">
        <p class="lms-dashboard-empty__title"><?= e(__t('ranking.empty')) ?></p>
        <p class="lms-dashboard-empty__hint"><?= e(__t('ranking.empty_hint')) ?></p>
    </div>
<?php else: ?>
    <div class="lms-ranking-card">
        <table class="lms-ranking-table">
            <thead>
                <tr>
                    <th class="lms-ranking-table__pos"><?= e(__t('ranking.col.position')) ?></th>
                    <th><?= e(__t('ranking.col.name')) ?></th>
                    <th class="d-none d-md-table-cell"><?= e(__t('ranking.col.groups')) ?></th>
                    <th class="lms-ranking-table__xp"><?= e(__t('ranking.col.xp')) ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                    <?php $isMe = (int) $row['student_id'] === $studentId; ?>
                    <tr class="<?= $isMe ? 'is-current' : '' ?>">
                        <td class="lms-ranking-table__pos">
                            <span class="lms-ranking-pos">#<?= (int) $row['position'] ?></span>
                        </td>
                        <td>
                            <span class="lms-ranking-name"><?= e($row['name']) ?></span>
                            <?php if ($isMe): ?>
                                <span class="lms-ranking-you"><?= e(__t('ranking.you')) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="d-none d-md-table-cell lms-ranking-groups">
                            <?= $row['group_names'] !== '' ? e($row['group_names']) : '<span class="lms-ranking-muted">—</span>' ?>
                        </td>
                        <td class="lms-ranking-table__xp">
                            <span class="lms-ranking-xp"><?= e(number_format((int) $row['xp'], 0, ',', '.')) ?></span>
                            <span class="lms-ranking-xp-unit">XP</span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($lastPage > 1): ?>
        <nav aria-label="<?= e(__t('ranking.pagination.aria')) ?>" class="mt-3">
            <ul class="pagination justify-content-center">
                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                    <a class="page-link" href="<?= e($qs(['page' => max(1, $page - 1)])) ?>">
                        <?= e(__t('ranking.pagination.prev')) ?>
                    </a>
                </li>
                <li class="page-item disabled">
                    <span class="page-link">
                        <?= e(__t('ranking.pagination.page_x_of_y', ['x' => (string) $page, 'y' => (string) $lastPage])) ?>
                    </span>
                </li>
                <li class="page-item <?= $page >= $lastPage ? 'disabled' : '' ?>">
                    <a class="page-link" href="<?= e($qs(['page' => min($lastPage, $page + 1)])) ?>">
                        <?= e(__t('ranking.pagination.next')) ?>
                    </a>
                </li>
            </ul>
        </nav>
    <?php endif; ?>
<?php endif; ?>
<?php
$page_content = ob_get_clean();
require LMS_ROOT . '/src/templates/layout.php';
