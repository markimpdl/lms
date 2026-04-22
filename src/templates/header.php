<?php
declare(strict_types=1);

$user = current_user();
$lang = current_lang();
?>
<nav class="navbar navbar-expand-sm navbar-light bg-light border-bottom">
    <div class="container">
        <a class="navbar-brand fw-semibold" href="/"><?= e(__t('app.title')) ?></a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav"
                aria-controls="mainNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="mainNav">
            <ul class="navbar-nav ms-auto align-items-sm-center">
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <?= e(__t('common.language')) ?>: <?= e(strtoupper($lang)) ?>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item<?= $lang === 'pt' ? ' active' : '' ?>" href="?lang=pt">PT</a></li>
                        <li><a class="dropdown-item<?= $lang === 'en' ? ' active' : '' ?>" href="?lang=en">EN</a></li>
                    </ul>
                </li>
                <?php if ($user !== null): ?>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle user-label" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <?= e($user['name'] ?? $user['email'] ?? '') ?>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="/profile"><?= e(__t('nav.profile')) ?></a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <form method="post" action="/logout" class="m-0">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="dropdown-item"><?= e(__t('nav.logout')) ?></button>
                                </form>
                            </li>
                        </ul>
                    </li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</nav>
