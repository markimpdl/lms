<?php
declare(strict_types=1);

/**
 * Bottom tab bar — primary nav do aluno em mobile (< 576px).
 *
 * Renderizado em `layout.php` apenas quando `$isStudentArea` é true.
 * Visível apenas em mobile (CSS `@media (max-width: 575.98px)`); em
 * desktop fica `display: none`. Bell e avatar continuam no top.
 *
 * 4 tabs determinísticos: Início, Ranking, Conquistas, Perfil.
 *
 * Active state: match exato do path; `/profile` casa também quando aluno
 * está em `/profile/theme` etc. (startsWith). Sem state pra rotas que
 * não estão no bar (ex.: dentro de um curso) — fallback é "nenhum ativo".
 */

$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

$tabs = [
    ['href' => '/student',              'icon' => 'bi-house',         'label' => __t('nav.home')],
    ['href' => '/student/ranking',      'icon' => 'bi-trophy',        'label' => __t('nav.ranking')],
    ['href' => '/student/achievements', 'icon' => 'bi-award',         'label' => __t('nav.achievements')],
    ['href' => '/profile',              'icon' => 'bi-person-circle', 'label' => __t('nav.profile')],
];

$isActive = static function (string $href) use ($path): bool {
    if ($path === $href) { return true; }
    return $href !== '/' && str_starts_with($path, $href . '/');
};
?>
<nav class="lms-bottom-nav" aria-label="<?= e(__t('nav.primary_aria')) ?>">
    <?php foreach ($tabs as $tab): ?>
        <?php $active = $isActive($tab['href']); ?>
        <a class="lms-bottom-nav__item <?= $active ? 'is-active' : '' ?>"
           href="<?= e($tab['href']) ?>"
           <?= $active ? 'aria-current="page"' : '' ?>>
            <i class="bi <?= e($tab['icon']) ?> lms-bottom-nav__icon" aria-hidden="true"></i>
            <span class="lms-bottom-nav__label"><?= e($tab['label']) ?></span>
        </a>
    <?php endforeach; ?>
</nav>
