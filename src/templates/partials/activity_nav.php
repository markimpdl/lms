<?php
/**
 * Partial: barra Voltar/Avancar da tela de atividade do aluno.
 *
 * Usada em /student/activity/{id} nos dois fluxos (entrega de arquivo/codigo
 * e quiz), pra o aluno nunca ficar sem saida na folha da arvore — o
 * breadcrumb sozinho nao basta.
 *
 * Espera no escopo do caller:
 *   $activityNavBack  string   URL do Voltar — sempre existe
 *   $activityNavNext  ?string  URL do Avancar; null esconde o botao. So tem
 *                              valor quando a atividade ja foi entregue.
 */
?>
<div class="card shadow-sm mt-3">
    <div class="card-body d-flex align-items-center gap-2 flex-wrap">
        <a href="<?= e((string) $activityNavBack) ?>" class="btn btn-outline-secondary btn-lg">
            &larr; <?= e(__t('submissions.nav.back')) ?>
        </a>
        <?php if ($activityNavNext !== null): ?>
            <a href="<?= e((string) $activityNavNext) ?>" class="btn btn-success btn-lg ms-auto">
                <?= e(__t('submissions.nav.forward')) ?> &rarr;
            </a>
        <?php endif; ?>
    </div>
</div>
