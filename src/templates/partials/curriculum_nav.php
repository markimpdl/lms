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
 */

$courseId      = (int) $tree['id'];
$hasActiveCc   = ($activeCcId ?? 0) > 0;
$courseActive  = !$hasActiveCc && ($activeCuId ?? 0) === 0;
?>
<nav aria-label="<?= e(__t('nav.curriculum.title')) ?>">
    <div class="list-group list-group-flush small">
        <a href="/teacher/courses/<?= $courseId ?>"
           class="list-group-item list-group-item-action fw-semibold<?= $courseActive ? ' active' : '' ?>">
            <?= e($tree['name']) ?>
        </a>
        <?php foreach ($tree['ccs'] as $cc): ?>
            <?php $ccId = (int) $cc['id']; $ccActive = $ccId === ($activeCcId ?? 0); ?>
            <a href="/teacher/courses/<?= $courseId ?>/cc/<?= $ccId ?>"
               class="list-group-item list-group-item-action ps-3<?= $ccActive && ($activeCuId ?? 0) === 0 ? ' active' : '' ?>">
                <?= e((string) $cc['name']) ?>
            </a>
            <?php if ($ccActive && !empty($cc['cus'])): ?>
                <?php foreach ($cc['cus'] as $cu): ?>
                    <?php $cuActive = (int) $cu['id'] === ($activeCuId ?? 0); ?>
                    <span class="list-group-item ps-5 text-muted<?= $cuActive ? ' active text-white' : '' ?>">
                        · <?= e((string) $cu['name']) ?>
                    </span>
                <?php endforeach; ?>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
</nav>
