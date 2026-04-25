<?php
declare(strict_types=1);

/**
 * /student/ranking — ranking do tenant pro aluno (E9-03).
 *
 * 3 janelas (Geral / 7d / 30d) selecionáveis via `?window=`. Linha do aluno
 * logado destacada. Paginação 50/página via `?page=`. Filtros de grupo/ano
 * são acrescentados em E9-04. Spec: `doc/08-gamificacao-e-ranking.md`.
 *
 * Auth + papel garantidos pelo front controller; tenant_id vem do user da
 * sessão e o `RankingService` faz o filtro multi-tenant.
 */

$user      = current_user();
$studentId = (int) ($user['id'] ?? 0);
$tenantId  = (int) ($user['tenant_id'] ?? 0);

$window = (string) ($_GET['window'] ?? 'all');
if (!in_array($window, RankingService::WINDOWS, true)) {
    $window = 'all';
}
$page    = max(1, (int) ($_GET['page'] ?? 1));
$perPage = RankingService::DEFAULT_PER_PAGE;

$result   = $tenantId > 0
    ? RankingService::compute($tenantId, $window, [], $page, $perPage)
    : ['rows' => [], 'total' => 0];
$rows     = $result['rows'];
$total    = $result['total'];
$lastPage = max(1, (int) ceil($total / $perPage));

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
               href="?window=<?= e($w) ?>"
               role="tab"
               aria-selected="<?= $w === $window ? 'true' : 'false' ?>">
                <?= e(__t('ranking.window.' . $w)) ?>
            </a>
        <?php endforeach; ?>
    </div>
</header>

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
        <?php $qs = static fn (int $p): string => '?window=' . urlencode($window) . '&page=' . $p; ?>
        <nav aria-label="<?= e(__t('ranking.pagination.aria')) ?>" class="mt-3">
            <ul class="pagination justify-content-center">
                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                    <a class="page-link" href="<?= e($qs(max(1, $page - 1))) ?>">
                        <?= e(__t('ranking.pagination.prev')) ?>
                    </a>
                </li>
                <li class="page-item disabled">
                    <span class="page-link">
                        <?= e(__t('ranking.pagination.page_x_of_y', ['x' => (string) $page, 'y' => (string) $lastPage])) ?>
                    </span>
                </li>
                <li class="page-item <?= $page >= $lastPage ? 'disabled' : '' ?>">
                    <a class="page-link" href="<?= e($qs(min($lastPage, $page + 1))) ?>">
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
