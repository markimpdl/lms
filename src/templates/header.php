<?php
declare(strict_types=1);

$user     = current_user();
$lang     = current_lang();
$otherLang = $lang === 'pt' ? 'en' : 'pt';

$unread     = 0;
$bellItems  = [];
if ($user !== null && ($user['role'] ?? null) !== 'super_admin') {
    $uid = (int) $user['id'];
    $unread    = Notification::countUnread($uid);
    $bellItems = Notification::findForUser($uid, 10);
}
?>
<header class="lms-navbar">
    <div class="lms-navbar__inner">
        <a class="lms-navbar__brand" href="/" aria-label="<?= e(__t('app.title')) ?>">
            <span class="lms-navbar__logo" aria-hidden="true">L</span>
            <span class="lms-navbar__wordmark">LMS</span>
        </a>

        <?php
        $userRole    = $user !== null ? ($user['role'] ?? null) : null;
        $rankingHref = $userRole === 'student' ? '/student/ranking'
                     : ($userRole === 'teacher' ? '/teacher/ranking' : null);
        $achievementsHref = $userRole === 'student' ? '/student/achievements' : null;
        ?>
        <?php if ($rankingHref !== null || $achievementsHref !== null): ?>
            <?php $currentPath = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH); ?>
            <nav class="lms-navbar__nav" aria-label="<?= e(__t('nav.primary_aria')) ?>">
                <?php if ($rankingHref !== null): ?>
                    <a class="lms-navbar__link <?= $currentPath === $rankingHref ? 'is-active' : '' ?>"
                       href="<?= e($rankingHref) ?>">
                        <?= e(__t('nav.ranking')) ?>
                    </a>
                <?php endif; ?>
                <?php if ($achievementsHref !== null): ?>
                    <a class="lms-navbar__link <?= $currentPath === $achievementsHref ? 'is-active' : '' ?>"
                       href="<?= e($achievementsHref) ?>">
                        <?= e(__t('nav.achievements')) ?>
                    </a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>

        <div class="lms-navbar__right">
            <?php if ($user !== null): ?>
                <form method="post" action="/settings/language" class="lms-navbar__lang-form m-0">
                    <?= csrf_field() ?>
                    <input type="hidden" name="lang" value="<?= e($otherLang) ?>">
                    <button type="submit" class="lms-navbar__lang" aria-label="<?= e(__t('nav.language.switch_to', ['target' => strtoupper($otherLang)])) ?>">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <circle cx="12" cy="12" r="10"></circle>
                            <path d="M2 12h20M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path>
                        </svg>
                        <span class="lms-navbar__lang-label">
                            <?= e(__t('common.language')) ?>:
                            <strong><?= e(strtoupper($lang)) ?></strong>
                        </span>
                    </button>
                </form>

                <?php if (($user['role'] ?? null) !== 'super_admin'): ?>
                    <?php require __DIR__ . '/partials/header_bell.php'; ?>
                <?php endif; ?>

                <span class="lms-navbar__divider" aria-hidden="true"></span>

                <div class="lms-navbar__user dropdown">
                    <button class="lms-navbar__user-btn dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <span class="lms-navbar__avatar" aria-hidden="true">
                            <?= e(mb_strtoupper(mb_substr((string) ($user['name'] ?? $user['email'] ?? '?'), 0, 1))) ?>
                        </span>
                        <span class="lms-navbar__user-name user-label">
                            <?= e($user['name'] ?? $user['email'] ?? '') ?>
                        </span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="/profile"><?= e(__t('nav.profile')) ?></a></li>
                        <?php if (($user['role'] ?? null) === 'teacher'): ?>
                            <li><a class="dropdown-item" href="/teacher/settings"><?= e(__t('nav.settings')) ?></a></li>
                        <?php endif; ?>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <form method="post" action="/logout" class="m-0">
                                <?= csrf_field() ?>
                                <button type="submit" class="dropdown-item"><?= e(__t('nav.logout')) ?></button>
                            </form>
                        </li>
                    </ul>
                </div>
            <?php else: ?>
                <a href="<?= e(lang_url($otherLang)) ?>" class="lms-navbar__lang"
                   aria-label="<?= e(__t('nav.language.switch_to', ['target' => strtoupper($otherLang)])) ?>">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <circle cx="12" cy="12" r="10"></circle>
                        <path d="M2 12h20M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path>
                    </svg>
                    <span class="lms-navbar__lang-label">
                        <?= e(__t('common.language')) ?>: <strong><?= e(strtoupper($lang)) ?></strong>
                    </span>
                </a>
                <a class="btn btn-primary btn-sm ms-2" href="/login"><?= e(__t('nav.login')) ?></a>
            <?php endif; ?>
        </div>
    </div>
</header>
