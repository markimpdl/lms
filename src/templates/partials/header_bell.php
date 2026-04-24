<?php
declare(strict_types=1);

/**
 * Sino de notificações no header (E10-01).
 *
 * Espera no escopo:
 *   $unread    int                         — contador pra badge
 *   $bellItems list<array<string,mixed>>   — 10 últimas pro dropdown
 *
 * Dropdown é aberto via Alpine.js `x-data` + `x-show`. Os dados são
 * renderizados server-side no layout, sem AJAX — se mudar mid-sessão,
 * atualiza no próximo reload (doc/09: "sino atualiza no próximo
 * carregamento de página").
 */
?>
<div class="lms-bell" x-data="{ open: false }" @keydown.escape="open = false" @click.outside="open = false">
    <button type="button"
            class="lms-bell__btn"
            @click="open = !open"
            :aria-expanded="open.toString()"
            aria-label="<?= e(__t('notifications.bell.aria')) ?>">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
            <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
        </svg>
        <?php if ($unread > 0): ?>
            <span class="lms-bell__badge" aria-label="<?= e(__t('notifications.bell.unread_count', ['n' => (string) $unread])) ?>">
                <?= $unread > 9 ? '9+' : (int) $unread ?>
            </span>
        <?php endif; ?>
    </button>

    <div class="lms-bell__dropdown"
         x-show="open"
         x-transition
         x-cloak
         role="menu">
        <div class="lms-bell__dropdown-header">
            <span class="lms-bell__dropdown-title"><?= e(__t('notifications.bell.title')) ?></span>
            <?php if ($unread > 0): ?>
                <form method="post" action="/notifications/mark-all-read" class="m-0">
                    <?= csrf_field() ?>
                    <button type="submit" class="lms-bell__mark-all"><?= e(__t('notifications.bell.mark_all_read')) ?></button>
                </form>
            <?php endif; ?>
        </div>

        <?php if ($bellItems === []): ?>
            <div class="lms-bell__empty"><?= e(__t('notifications.bell.empty')) ?></div>
        <?php else: ?>
            <ul class="lms-bell__list">
                <?php foreach ($bellItems as $n): ?>
                    <?php
                        $isUnread = ($n['read_at'] ?? null) === null;
                        $link     = (string) ($n['link'] ?? '/notifications');
                    ?>
                    <li class="lms-bell__item <?= $isUnread ? 'lms-bell__item--unread' : '' ?>">
                        <form method="post" action="/notifications/mark-read" class="m-0">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= (int) $n['id'] ?>">
                            <button type="submit" class="lms-bell__item-btn">
                                <?php if ($isUnread): ?>
                                    <span class="lms-bell__dot" aria-hidden="true"></span>
                                <?php endif; ?>
                                <span class="lms-bell__item-body">
                                    <span class="lms-bell__item-title"><?= e((string) $n['title']) ?></span>
                                    <?php if (($n['body'] ?? '') !== ''): ?>
                                        <span class="lms-bell__item-text"><?= e((string) $n['body']) ?></span>
                                    <?php endif; ?>
                                    <span class="lms-bell__item-time"><?= e(format_short_datetime((string) $n['created_at'])) ?></span>
                                </span>
                            </button>
                        </form>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <div class="lms-bell__dropdown-footer">
            <a href="/notifications" class="lms-bell__view-all"><?= e(__t('notifications.bell.view_all')) ?></a>
        </div>
    </div>
</div>
