<?php
/**
 * Partial: timeline da trilha da CU na visao do aluno (E36-05).
 *
 * Usado na capa da unidade e na lateral da pagina da licao — o mesmo desenho
 * nos dois lugares, pra o aluno nao perder a referencia de onde esta.
 *
 * Espera no escopo do caller:
 *   $timelineItems   list<array{type,id,title,done,href,xp_value}> — forStudentCu
 *   $timelineCurrent ?string  chave "tipo:id" do item aberto agora (opcional)
 *
 * Navegacao livre: uma vez que a CU esta desbloqueada, todo item da trilha eh
 * acessivel (decisao do PO). Nao ha cadeado por item aqui — o gate de acesso
 * acontece no nivel da CU, em course_progression_state().
 */
$timelineCurrent = $timelineCurrent ?? null;
?>
<nav class="lms-track" aria-label="<?= e(__t('track.timeline.aria')) ?>">
    <ol class="lms-track__list">
        <?php foreach ($timelineItems as $i => $it):
            $key       = $it['type'] . ':' . $it['id'];
            $isCurrent = $timelineCurrent !== null && $key === $timelineCurrent;
            $classes   = 'lms-track__item';
            if ($it['done'])  { $classes .= ' is-done'; }
            if ($isCurrent)   { $classes .= ' is-current'; }
            $typeLabel = match ($it['type']) {
                'lesson'   => __t('track.type.lesson'),
                'activity' => __t('track.type.activity'),
                default    => __t('track.type.evaluation'),
            };
        ?>
            <li class="<?= e($classes) ?>">
                <a href="<?= e((string) $it['href']) ?>" class="lms-track__link"
                   <?= $isCurrent ? 'aria-current="step"' : '' ?>>
                    <span class="lms-track__marker" aria-hidden="true">
                        <?= $it['done'] ? '&check;' : (int) ($i + 1) ?>
                    </span>
                    <span class="lms-track__body">
                        <span class="lms-track__type"><?= e($typeLabel) ?></span>
                        <span class="lms-track__title"><?= e((string) $it['title']) ?></span>
                    </span>
                    <?php if ((int) $it['xp_value'] > 0 && !$it['done']): ?>
                        <span class="lms-track__xp"><?= e(__t('track.badge.xp', ['xp' => (string) (int) $it['xp_value']])) ?></span>
                    <?php endif; ?>
                </a>
            </li>
        <?php endforeach; ?>
    </ol>
</nav>
