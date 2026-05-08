<?php
declare(strict_types=1);

/**
 * ProfileSidebar — card lateral 300px exibido no layout do aluno (E14-01).
 *
 * Renderizado pelo layout.php quando `body.lms-student-area` está ativo.
 * Espera que `current_user()` retorne o aluno logado. Quando faltar qualquer
 * peça (sem patente, sem XP, sem curso recente), o partial degrada
 * graciosamente — não quebra nem mostra placeholder feio.
 *
 * Fontes de dados:
 *  - `student_total_xp`            — soma de xp_events.value
 *  - `student_current_rank`        — Rank::findCurrentByXp
 *  - `student_next_rank`           — Rank::findNextByXp
 *  - `student_recent_course_name`  — curso com last_access_at mais recente
 */

$user = current_user();
if ($user === null || ($user['role'] ?? '') !== 'student') {
    return; // sidebar só existe pro aluno
}

$studentId = (int) ($user['id'] ?? 0);
$tenantId  = (int) ($user['tenant_id'] ?? 0);
$name      = (string) ($user['name'] ?? '');

$totalXp    = student_total_xp($studentId);
$rank       = $tenantId > 0 ? student_current_rank($studentId, $tenantId)  : null;
$nextRank   = $tenantId > 0 ? student_next_rank($studentId, $tenantId)     : null;
// Aluno zero XP entra no ranking "geral" como último colocado (LEFT JOIN
// + sem HAVING). Pra UX, o AC pede que isso vire "sem ranking ainda" no
// header — então só calcula a posição quando há XP de fato.
$position   = ($tenantId > 0 && $totalXp > 0)
    ? student_ranking_position($studentId, $tenantId) : null;
$recentCrs  = student_recent_course_name($studentId);

$rankColor  = $rank !== null ? (string) $rank['color_hex'] : '#94A3B8';
$rankName   = $rank !== null ? (string) $rank['name']      : null;

// Gradient do rank pill + barra: se rank tem color_hex, usa ele → indigo.
// Senão fica muted. Cor do border do avatar segue a mesma lógica.
$rankGradient = $rank !== null
    ? sprintf('linear-gradient(135deg, %s, #EC4899)', $rankColor)
    : 'var(--lms-muted)';

// Percentual de progresso dentro da faixa atual. Se aluno sem patente ou
// com xp_max NULL (topo), barra mostra 0% ou 100% respectivamente.
$xpPct = 0;
if ($rank !== null) {
    $xpMin = (int) $rank['xp_min'];
    $xpMax = $rank['xp_max'] === null ? null : (int) $rank['xp_max'];
    if ($xpMax === null) {
        $xpPct = 100;
    } elseif ($xpMax > $xpMin) {
        $delta = max(0, $totalXp - $xpMin);
        $span  = $xpMax - $xpMin;
        $xpPct = (int) min(100, round(($delta / $span) * 100));
    }
}

$xpTarget = null;
if ($nextRank !== null) {
    $xpTarget = (int) $nextRank['xp_min'] - $totalXp;
    if ($xpTarget < 0) {
        $xpTarget = 0;
    }
}

// Conquistas recentes (E18-06): até 3 miniaturas das últimas desbloqueadas.
$recentAchievements = $tenantId > 0
    ? student_recent_achievements($studentId, $tenantId, 3)
    : [];

$branding     = $tenantId > 0 ? tenant_branding($tenantId) : null;
$whatsappHref = null;
if ($branding !== null && $branding['whatsapp_number'] !== null) {
    $courseName = student_primary_course_name($studentId);
    $msgKey     = $courseName !== null
        ? 'sidebar.whatsapp_message'
        : 'sidebar.whatsapp_message_no_course';
    $msg = __t($msgKey, [
        'name'     => $name,
        'course'   => (string) $courseName,
        'platform' => $branding['name'],
    ]);
    $whatsappHref = 'https://wa.me/' . $branding['whatsapp_number'] . '?text=' . rawurlencode($msg);
}
?>
<aside class="lms-student-sidebar">
    <div class="lms-sidebar-card"
         style="--lms-avatar-color: <?= e($rankColor) ?>; --lms-rank-gradient: <?= e($rankGradient) ?>; --lms-xp-pct: <?= (int) $xpPct ?>%;">

        <div class="lms-sidebar-profile">
            <div class="lms-sidebar-avatar-wrap">
                <img class="lms-sidebar-avatar" src="<?= e(student_avatar_url($studentId)) ?>"
                     alt="" loading="lazy" width="104" height="104">
                <?php if ($rankName !== null): ?>
                    <span class="lms-sidebar-rank-pill"><?= e($rankName) ?></span>
                <?php else: ?>
                    <span class="lms-sidebar-rank-pill lms-sidebar-rank-pill--empty"
                          title="<?= e(__t('sidebar.no_rank')) ?>">—</span>
                <?php endif; ?>
            </div>
            <div class="lms-sidebar-name" title="<?= e($name) ?>"><?= e(format_short_name($name)) ?></div>
            <?php if ($recentCrs !== null): ?>
                <div class="lms-sidebar-subtitle"><?= e($recentCrs) ?></div>
            <?php endif; ?>
        </div>

        <div class="lms-xp-block">
            <div class="lms-xp-header">
                <div class="lms-xp-header__left">
                    <svg class="lms-xp-star" width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                        <path d="M12 2l2.95 6.9L22 9.6l-5.5 4.8L18.2 22 12 18.3 5.8 22l1.7-7.6L2 9.6l7.05-.7L12 2z"/>
                    </svg>
                    <span class="lms-xp-eyebrow"><?= e(__t('sidebar.total_xp')) ?></span>
                </div>
                <span class="lms-xp-total"><?= number_format($totalXp, 0, ',', '.') ?></span>
            </div>

            <?php if ($rank !== null): ?>
                <div class="lms-xp-bar" aria-hidden="true">
                    <div class="lms-xp-bar__fill"></div>
                </div>

                <?php if ($nextRank !== null && $xpTarget !== null): ?>
                    <div class="lms-xp-footer">
                        <span><?= e(number_format($totalXp, 0, ',', '.')) ?> XP</span>
                        <span>
                            <?= e(__t('sidebar.next_rank_xp', ['xp' => number_format($xpTarget, 0, ',', '.')])) ?>
                            <?= e(__t('sidebar.next_rank_to')) ?>
                            <strong><?= e((string) $nextRank['name']) ?></strong>
                        </span>
                    </div>
                <?php else: ?>
                    <div class="lms-xp-footer lms-xp-footer--max">
                        <?= e(__t('sidebar.max_rank')) ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <div class="lms-achievements-block">
            <div class="lms-achievements-block__header">
                <span class="lms-achievements-block__eyebrow"><?= e(__t('sidebar.achievements')) ?></span>
            </div>
            <div class="lms-achievements-block__items" aria-hidden="true">
                <?php for ($i = 0; $i < 3; $i++): ?>
                    <?php $a = $recentAchievements[$i] ?? null; ?>
                    <?php if ($a !== null): ?>
                        <span class="lms-achievements-block__item is-unlocked"
                              title="<?= e($a['name']) ?>">
                            <i class="bi bi-<?= e($a['icon_key']) ?>"></i>
                        </span>
                    <?php else: ?>
                        <span class="lms-achievements-block__item is-placeholder">
                            <i class="bi bi-circle"></i>
                        </span>
                    <?php endif; ?>
                <?php endfor; ?>
            </div>
            <?php if ($recentAchievements === []): ?>
                <p class="lms-achievements-block__empty"><?= e(__t('sidebar.no_achievements')) ?></p>
            <?php endif; ?>
            <a href="/student/achievements" class="lms-ranking-block__cta">
                <?= e(__t('sidebar.see_achievements')) ?>
            </a>
        </div>

        <div class="lms-ranking-block">
            <div class="lms-ranking-block__header">
                <span class="lms-ranking-block__eyebrow"><?= e(__t('sidebar.position')) ?></span>
                <?php if ($position !== null): ?>
                    <span class="lms-ranking-block__value">#<?= (int) $position ?></span>
                <?php else: ?>
                    <span class="lms-ranking-block__value lms-ranking-block__value--empty"
                          title="<?= e(__t('sidebar.no_position')) ?>">—</span>
                <?php endif; ?>
            </div>
            <a href="/student/ranking" class="lms-ranking-block__cta">
                <?= e(__t('sidebar.see_ranking')) ?>
            </a>
        </div>

        <?php if ($whatsappHref !== null): ?>
            <a href="<?= e($whatsappHref) ?>" target="_blank" rel="noopener noreferrer"
               class="lms-sidebar-whatsapp">
                <i class="bi bi-whatsapp" aria-hidden="true"></i>
                <span><?= e(__t('sidebar.talk_to_teacher')) ?></span>
            </a>
        <?php endif; ?>
    </div>
</aside>
