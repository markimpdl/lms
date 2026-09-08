<?php
declare(strict_types=1);

/**
 * Partial: card "Marcar como concluida" da unidade (v0.31.0).
 *
 * Alternativa a avaliacao pra CU que fecha por decisao do aluno. Extraido da
 * tela do aluno porque agora renderiza nos DOIS formatos de curso: estava
 * escrito dentro do bloco V1, entao em curso V2 o botao simplesmente nao
 * existia — e como `StudentProgress::cuPercent` conta o slot de conclusao
 * manual no denominador, a unidade ficava presa abaixo de 100% sem o aluno
 * ter como fecha-la.
 *
 * Espera no escopo:
 *   $cuId              int
 *   $manualCompleted   bool    — ja fechou (linha em cu_manual_completions)
 *   $manualCompletedAt string  — quando (so usado se $manualCompleted)
 *   $manualXpValue     int
 *   $manualPending     bool    — ainda falta coisa pra poder fechar; em V1 eh
 *                                "tem atividade sem entrega", em V2 eh "a
 *                                trilha nao acabou" (a trilha inclui licao,
 *                                que o criterio de atividades nao enxerga)
 */
?>
<div class="lms-manual-completion-card<?= $manualCompleted ? ' is-completed' : '' ?>">
    <?php if ($manualCompleted): ?>
        <div class="lms-manual-completion-card__icon" aria-hidden="true">
            <i class="bi bi-check-circle-fill"></i>
        </div>
        <div class="lms-manual-completion-card__body">
            <h3 class="lms-manual-completion-card__title">
                <?= e(__t('manual_completion.student.completed_title')) ?>
            </h3>
            <p class="lms-manual-completion-card__hint">
                <?= e(__t('manual_completion.student.completed_at', [
                    'when' => format_short_datetime((string) $manualCompletedAt),
                ])) ?>
                <?php if ($manualXpValue > 0): ?>
                    · <?= (int) $manualXpValue ?> XP
                <?php endif; ?>
            </p>
        </div>
    <?php else: ?>
        <div class="lms-manual-completion-card__body">
            <h3 class="lms-manual-completion-card__title">
                <?= e(__t('manual_completion.student.title')) ?>
            </h3>
            <p class="lms-manual-completion-card__hint">
                <?php if ($manualPending): ?>
                    <?= e(__t('manual_completion.student.activities_pending')) ?>
                <?php else: ?>
                    <?= e(__t('manual_completion.student.ready')) ?>
                    <?php if ($manualXpValue > 0): ?>
                        · <?= (int) $manualXpValue ?> XP
                    <?php endif; ?>
                <?php endif; ?>
            </p>
        </div>
        <form method="POST" action="/student/cu/<?= $cuId ?>/mark-complete" class="lms-manual-completion-card__form">
            <?= csrf_field() ?>
            <button type="submit"
                    class="btn btn-success btn-lg lms-manual-completion-card__btn"
                    <?= $manualPending ? 'disabled' : '' ?>>
                <i class="bi bi-check-circle me-1" aria-hidden="true"></i>
                <?= e(__t('manual_completion.student.cta')) ?>
            </button>
        </form>
    <?php endif; ?>
</div>
