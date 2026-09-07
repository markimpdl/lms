<?php
/**
 * Partial: timeline da trilha da CU na visao do aluno (E36-05).
 *
 * Usado na capa da unidade e na lateral da pagina da licao — o mesmo desenho
 * nos dois lugares, pra o aluno nao perder a referencia de onde esta.
 *
 * Espera no escopo do caller:
 *   $timelineItems   list<array{type,id,title,done,locked,href,xp_value}> — forStudentCu
 *   $timelineCurrent ?string  chave "tipo:id" do item aberto agora (opcional)
 *
 * Navegacao livre: uma vez que a CU esta desbloqueada, licao e exercicio sao
 * sempre acessiveis (decisao do PO) — o gate de acesso acontece no nivel da
 * CU, em course_progression_state().
 *
 * A UNICA excecao eh a avaliacao com `locked = true`: `eval_after_activities`
 * exige toda atividade da CU entregue, e como a navegacao eh livre o aluno
 * chega ao fim da trilha com exercicio pendente. Nesse caso o item vira texto,
 * nao link — clicar so renderia um 303 de volta com flash.
 */
$timelineCurrent = $timelineCurrent ?? null;
?>
<nav class="lms-track" aria-label="<?= e(__t('track.timeline.aria')) ?>">
    <ol class="lms-track__list">
        <?php foreach ($timelineItems as $i => $it):
            $key       = $it['type'] . ':' . $it['id'];
            $isCurrent = $timelineCurrent !== null && $key === $timelineCurrent;
            $isLocked  = ($it['locked'] ?? false) === true;
            $classes   = 'lms-track__item';
            if ($it['done'])  { $classes .= ' is-done'; }
            if ($isCurrent)   { $classes .= ' is-current'; }
            if ($isLocked)    { $classes .= ' is-locked'; }
            $typeLabel = match ($it['type']) {
                'lesson'   => __t('track.type.lesson'),
                'activity' => __t('track.type.activity'),
                default    => __t('track.type.evaluation'),
            };
            // Travado sai como <span>: sem href, sem foco, sem promessa falsa.
            $tag      = $isLocked ? 'span' : 'a';
            $tagAttrs = $isLocked
                ? ' aria-disabled="true" title="' . e(__t('progression.eval_locked')) . '"'
                : ' href="' . e((string) $it['href']) . '"'
                    . ($isCurrent ? ' aria-current="step"' : '');
        ?>
            <li class="<?= e($classes) ?>">
                <<?= $tag ?> class="lms-track__link"<?= $tagAttrs ?>>
                    <span class="lms-track__marker" aria-hidden="true">
                        <?php if ($isLocked): ?>
                            <i class="bi bi-lock-fill"></i>
                        <?php else: ?>
                            <?= $it['done'] ? '&check;' : (int) ($i + 1) ?>
                        <?php endif; ?>
                    </span>
                    <span class="lms-track__body">
                        <span class="lms-track__type"><?= e($typeLabel) ?></span>
                        <span class="lms-track__title"><?= e((string) $it['title']) ?></span>
                        <?php if ($isLocked): ?>
                            <span class="lms-track__hint"><?= e(__t('progression.eval_locked')) ?></span>
                        <?php endif; ?>
                    </span>
                    <?php if ((int) $it['xp_value'] > 0 && !$it['done'] && !$isLocked): ?>
                        <span class="lms-track__xp"><?= e(__t('track.badge.xp', ['xp' => (string) (int) $it['xp_value']])) ?></span>
                    <?php endif; ?>
                </<?= $tag ?>>
            </li>
        <?php endforeach; ?>
    </ol>
</nav>
