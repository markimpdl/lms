<?php
declare(strict_types=1);

/**
 * Árvore Curso → CCs → CUs (E3-04).
 * Variáveis esperadas:
 *   $tree           estrutura retornada por curriculum_tree()
 *   $activeCcId     int (0 se não aplicável)
 *   $activeCuId     int (0 se não aplicável)
 *
 * O partial é pure-render — o mesmo markup é reutilizado dentro da coluna
 * (desktop ≥md) e do offcanvas (mobile <md). Todas as CCs aparecem; CUs
 * aparecem só dentro da CC ativa para não pesar listas longas. Quando não
 * há CC ativa (página do próprio curso), o "curso" fica marcado ativo.
 *
 * Todas as variáveis internas usam prefixo `$nav` para não vazarem via
 * escopo global do `require` e poluírem a página hospedeira (PHP não tem
 * escopo em include/require — `foreach ... as $cc` vazaria o último item
 * pra chamadores que também usam `$cc`/`$ccId`, como `/teacher/cc/show.php`).
 */

$navCourseId     = (int) $tree['id'];
$navActiveCcId   = (int) ($activeCcId ?? 0);
$navActiveCuId   = (int) ($activeCuId ?? 0);
$navHasActiveCc  = $navActiveCcId > 0;
$navCourseActive = !$navHasActiveCc && $navActiveCuId === 0;
?>
<nav aria-label="<?= e(__t('nav.curriculum.title')) ?>">
    <div class="list-group list-group-flush small">
        <a href="/teacher/courses/<?= $navCourseId ?>"
           class="list-group-item list-group-item-action fw-semibold<?= $navCourseActive ? ' active' : '' ?>">
            <?= e($tree['name']) ?>
        </a>
        <?php foreach ($tree['ccs'] as $navCc): ?>
            <?php
                $navCcId     = (int) $navCc['id'];
                $navCcActive = $navCcId === $navActiveCcId;
            ?>
            <a href="/teacher/courses/<?= $navCourseId ?>/cc/<?= $navCcId ?>"
               class="list-group-item list-group-item-action ps-3<?= $navCcActive && $navActiveCuId === 0 ? ' active' : '' ?>">
                <?= e((string) $navCc['name']) ?>
            </a>
            <?php if ($navCcActive && !empty($navCc['cus'])): ?>
                <?php foreach ($navCc['cus'] as $navCu): ?>
                    <?php $navCuActive = (int) $navCu['id'] === $navActiveCuId; ?>
                    <span class="list-group-item ps-5 text-muted<?= $navCuActive ? ' active text-white' : '' ?>">
                        · <?= e((string) $navCu['name']) ?>
                    </span>
                <?php endforeach; ?>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
</nav>
