<?php
declare(strict_types=1);

/**
 * /student/ranking          — ranking do tenant inteiro (E9-03 + E9-04).
 * /student/course/{id}/ranking — ranking restrito ao curso (E9-06). Mesma
 *   página, com `course_id` capturado pela rota; valida matrícula ativa.
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

// Escopo de curso (vindo do route pattern /student/course/{id}/ranking).
// Quando presente, valida matrícula ativa e carrega o nome do curso pra
// exibir no header. 404 amigável se aluno não matriculado.
$courseId   = (int) ($_REQUEST['course_id'] ?? 0);
$courseName = null;
if ($courseId > 0) {
    if (!Enrollment::isEnrolled($studentId, $courseId)) {
        http_response_code(404);
        require LMS_ROOT . '/src/templates/errors/404.php';
        return;
    }
    $cnStmt = Database::pdo()->prepare('SELECT name FROM courses WHERE id = ? AND tenant_id = ? LIMIT 1');
    $cnStmt->execute([$courseId, $tenantId]);
    $cn = $cnStmt->fetchColumn();
    if ($cn === false) {
        http_response_code(404);
        require LMS_ROOT . '/src/templates/errors/404.php';
        return;
    }
    $courseName = (string) $cn;
}

// Janela: whitelist + fallback silencioso.
$window = (string) ($_GET['window'] ?? 'all');
if (!in_array($window, RankingService::WINDOWS, true)) {
    $window = 'all';
}

// Lista de grupos do tenant (pra dropdown e validação de input).
$groups = $tenantId > 0 ? Group::listForSelect($tenantId) : [];

// Lista de anos com matrículas no tenant (pra dropdown). Ordenada DESC.
// Mudou em 2026-04-27: antes lia `xp_events.created_at` (ano da última
// interação); agora lê `enrollments.enrolled_at` (ano de matrícula),
// alinhado com a nova semântica do filtro `year` no RankingService.
$years = [];
if ($tenantId > 0) {
    $stmt = Database::pdo()->prepare(
        'SELECT DISTINCT YEAR(e.enrolled_at) AS y
           FROM enrollments e
           INNER JOIN courses c ON c.id = e.course_id AND c.tenant_id = ?
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
if ($groupId !== null) { $filters['group_id']  = $groupId; }
if ($year    !== null) { $filters['year']      = $year; }
if ($courseId > 0)     { $filters['course_id'] = $courseId; }

$result = $tenantId > 0
    ? RankingService::compute($tenantId, $window, $filters, $page, $perPage)
    : ['rows' => [], 'total' => 0];
$rows     = $result['rows'];
$total    = $result['total'];
$lastPage = max(1, (int) ceil($total / $perPage));

// Indicador "online agora" — flip pra lookup O(1) por student_id.
$onlineSet = $tenantId > 0
    ? array_flip(StudentSession::onlineUserIds($tenantId))
    : [];

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

$page_title = $courseName !== null
    ? __t('ranking.title.course', ['name' => $courseName])
    : __t('ranking.title');

ob_start();
?>
<header class="lms-dashboard-header lms-ranking-header">
    <div>
        <span class="lms-dashboard-eyebrow">
            <?= e(__t($courseName !== null ? 'ranking.scope.course' : 'ranking.eyebrow')) ?>
        </span>
        <h1 class="lms-dashboard-title">
            <?= $courseName !== null ? e($courseName) : e(__t('ranking.title')) ?>
        </h1>
        <p class="lms-dashboard-subtitle">
            <?php if ($courseName !== null): ?>
                <a href="/student/course/<?= (int) $courseId ?>" class="lms-ranking-back">
                    <?= e(__t('ranking.back_to_course')) ?>
                </a>
            <?php else: ?>
                <?= e(__t('ranking.subtitle')) ?>
            <?php endif; ?>
        </p>
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
                    <th class="d-none d-md-table-cell"><?= e(__t('ranking.col.rank')) ?></th>
                    <th class="d-none d-md-table-cell"><?= e(__t('ranking.col.groups')) ?></th>
                    <th class="lms-ranking-table__hours"><?= e(__t('ranking.col.hours')) ?></th>
                    <th class="lms-ranking-table__xp"><?= e(__t('ranking.col.xp')) ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                    <?php
                    $isMe       = (int) $row['student_id'] === $studentId;
                    $rankName   = $row['rank_name'];
                    $rankColor  = is_string($row['rank_color_hex']) && preg_match('/^#[0-9A-Fa-f]{6}$/', $row['rank_color_hex'])
                        ? $row['rank_color_hex']
                        : '#6B7280';
                    ?>
                    <tr class="<?= $isMe ? 'is-current' : '' ?>">
                        <td class="lms-ranking-table__pos">
                            <span class="lms-ranking-pos">#<?= (int) $row['position'] ?></span>
                        </td>
                        <td>
                            <img src="<?= e(student_avatar_url((int) $row['student_id'])) ?>"
                                 class="lms-ranking-avatar d-none d-md-inline-block"
                                 alt="" width="32" height="32" loading="lazy">
                            <?php if (isset($onlineSet[(int) $row['student_id']])): ?>
                                <span class="lms-online-dot text-success me-1"
                                      title="<?= e(__t('ranking.online')) ?>"
                                      aria-label="<?= e(__t('ranking.online')) ?>">●</span>
                            <?php endif; ?>
                            <span class="lms-ranking-name" title="<?= e((string) $row['name']) ?>"><?= e(format_short_name((string) $row['name'])) ?></span>
                            <?php if ($rankName !== null): ?>
                                <span class="lms-rank-pill lms-rank-pill--inline d-md-none"
                                      style="background: <?= e($rankColor) ?>"><?= e($rankName) ?></span>
                            <?php endif; ?>
                            <?php if ($isMe): ?>
                                <span class="lms-ranking-you"><?= e(__t('ranking.you')) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="d-none d-md-table-cell">
                            <?php if ($rankName !== null): ?>
                                <span class="lms-rank-pill" style="background: <?= e($rankColor) ?>"><?= e($rankName) ?></span>
                            <?php else: ?>
                                <span class="lms-ranking-muted" title="<?= e(__t('ranking.no_rank')) ?>">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="d-none d-md-table-cell lms-ranking-groups">
                            <?= $row['group_names'] !== '' ? e($row['group_names']) : '<span class="lms-ranking-muted">—</span>' ?>
                        </td>
                        <td class="lms-ranking-table__hours">
                            <span class="lms-ranking-hours"><?= e(format_hours_compact((int) $row['online_seconds'])) ?></span>
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
