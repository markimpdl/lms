<?php
declare(strict_types=1);

/**
 * Partial: Section tabs (E14-04) — pill group com âncoras pra cada seção.
 *
 * Tabs são <a href="#anchor"> — scroll nativo, sem JS. O estado "active" é
 * visual (classe no tab clicado), gerenciado via Alpine.js pra manter em
 * sincronia com o scroll do usuário; sem Alpine, o primeiro tab é o
 * padrão e clicar só navega.
 *
 * Espera no escopo:
 *   $tabs list<array{anchor:string, label:string, count?:int}>
 */
?>
<nav class="lms-section-tabs"
     x-data="{ active: <?= e(json_encode($tabs[0]['anchor'] ?? '#content')) ?> }"
     role="tablist">
    <?php foreach ($tabs as $tab): ?>
        <?php
            $anchor = (string) $tab['anchor'];
            $label  = (string) $tab['label'];
            $count  = $tab['count'] ?? null;
        ?>
        <a href="<?= e($anchor) ?>"
           class="lms-section-tab"
           :class="{ 'is-active': active === '<?= e($anchor) ?>' }"
           @click="active = '<?= e($anchor) ?>'"
           role="tab">
            <?= e($label) ?>
            <?php if ($count !== null): ?>
                <span class="lms-section-tab__count"><?= (int) $count ?></span>
            <?php endif; ?>
        </a>
    <?php endforeach; ?>
</nav>
