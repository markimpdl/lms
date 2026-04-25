<?php
declare(strict_types=1);

/**
 * /student/achievements — grid de conquistas alcançáveis pelo tenant (E18-05).
 *
 * Mostra apenas as conquistas alcançáveis dado o estado atual do tenant
 * (`AchievementsService::availableForTenant`); desbloqueadas em destaque
 * com data, bloqueadas em cinza. Antes de renderizar, roda
 * `AchievementsService::evaluateAll` como rede de segurança contra hooks
 * (E18-04) que possam ter falhado silenciosamente.
 *
 * Auth/role garantidos pelo front controller; tenant_id e student_id
 * vêm da sessão.
 */

$user      = current_user();
$studentId = (int) ($user['id'] ?? 0);
$tenantId  = (int) ($user['tenant_id'] ?? 0);
$lang      = current_lang();

// Rede de segurança: recompute defensivo. Best-effort — uma falha aqui
// não deve quebrar a tela.
try {
    AchievementsService::evaluateAll($studentId, $tenantId);
} catch (\Throwable) {
    // swallow
}

$catalog  = $tenantId > 0 ? AchievementsService::availableForTenant($tenantId) : [];
$unlocked = ($studentId > 0 && $tenantId > 0)
    ? AchievementsService::unlockedForStudent($studentId, $tenantId)
    : [];

// Junta catálogo + estado de desbloqueio.
$items = [];
foreach ($catalog as $row) {
    $aid        = (int) $row['id'];
    $isUnlocked = isset($unlocked[$aid]);
    $items[] = [
        'id'          => $aid,
        'family'      => (string) $row['family'],
        'threshold'   => $row['threshold'] !== null ? (int) $row['threshold'] : null,
        'icon_key'    => (string) $row['icon_key'],
        'name'        => (string) ($lang === 'pt' ? $row['name_pt'] : $row['name_en']),
        'sort_order'  => (int) $row['sort_order'],
        'unlocked'    => $isUnlocked,
        'unlocked_at' => $isUnlocked ? (string) $unlocked[$aid] : null,
    ];
}

// Ordena: desbloqueadas (mais recente primeiro) → bloqueadas (sort_order ASC).
usort($items, static function (array $a, array $b): int {
    if ($a['unlocked'] !== $b['unlocked']) {
        return $a['unlocked'] ? -1 : 1;
    }
    if ($a['unlocked']) {
        // mais recente primeiro
        return strcmp((string) $b['unlocked_at'], (string) $a['unlocked_at']);
    }
    return $a['sort_order'] <=> $b['sort_order'];
});

$totalCount    = count($items);
$unlockedCount = 0;
foreach ($items as $it) {
    if ($it['unlocked']) {
        $unlockedCount++;
    }
}

$page_title = __t('achievements.title');

ob_start();
?>
<header class="lms-dashboard-header lms-achievements-header">
    <div>
        <span class="lms-dashboard-eyebrow"><?= e(__t('achievements.eyebrow')) ?></span>
        <h1 class="lms-dashboard-title"><?= e(__t('achievements.title')) ?></h1>
        <p class="lms-dashboard-subtitle"><?= e(__t('achievements.subtitle')) ?></p>
    </div>
    <div class="lms-achievements-counter" aria-live="polite">
        <span class="lms-achievements-counter__numbers">
            <strong><?= (int) $unlockedCount ?></strong> / <?= (int) $totalCount ?>
        </span>
        <span class="lms-achievements-counter__label">
            <?= e(__t('achievements.unlocked_label')) ?>
        </span>
    </div>
</header>

<?php if ($items === []): ?>
    <div class="lms-dashboard-empty">
        <p class="lms-dashboard-empty__title"><?= e(__t('achievements.empty')) ?></p>
        <p class="lms-dashboard-empty__hint"><?= e(__t('achievements.empty_hint')) ?></p>
    </div>
<?php else: ?>
    <div class="lms-achievements-grid">
        <?php foreach ($items as $it): ?>
            <article class="lms-achievement-card lms-achievement-card--<?= e($it['family']) ?> <?= $it['unlocked'] ? 'is-unlocked' : 'is-locked' ?>">
                <div class="lms-achievement-card__icon" aria-hidden="true">
                    <i class="bi bi-<?= e($it['icon_key']) ?>"></i>
                    <?php if ($it['threshold'] !== null): ?>
                        <span class="lms-achievement-card__badge"><?= (int) $it['threshold'] ?></span>
                    <?php elseif ($it['unlocked']): ?>
                        <span class="lms-achievement-card__badge"><i class="bi bi-check-lg"></i></span>
                    <?php endif; ?>
                </div>
                <h2 class="lms-achievement-card__name"><?= e($it['name']) ?></h2>
                <?php if ($it['unlocked'] && $it['unlocked_at'] !== null): ?>
                    <p class="lms-achievement-card__date">
                        <?= e(__t('achievements.unlocked_at', [
                            'date' => format_short_date($it['unlocked_at']),
                        ])) ?>
                    </p>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php
$page_content = ob_get_clean();
require LMS_ROOT . '/src/templates/layout.php';
