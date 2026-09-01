<?php
/**
 * Partial: botao de desbloqueio manual da CU pra UM aluno (E36-02).
 *
 * Usado nas duas renderizacoes do roster em /teacher/cu/{id} — a tabela
 * (>= md) e os cards (< md) — pra nao duplicar a marcacao do form.
 *
 * Espera no escopo do caller:
 *   $cuId              int
 *   $u_studentId       int    aluno da linha
 *   $u_isUnlocked      bool   ja tem desbloqueio manual
 *   $u_canManage       bool   professor pode agir nesta linha (curso sequencial,
 *                             nao arquivado, aluno do proprio tenant)
 *
 * Prefixo `u_` nas variaveis porque o partial roda dentro do loop do roster,
 * onde `$r`, `$act` e afins ja estao ocupados — evita colisao silenciosa.
 */
$u_studentId  = (int)  ($u_studentId  ?? 0);
$u_isUnlocked = (bool) ($u_isUnlocked ?? false);
$u_canManage  = (bool) ($u_canManage  ?? false);
?>
<?php if (!$u_canManage): ?>
    <?php if ($u_isUnlocked): ?>
        <span class="badge text-bg-info-subtle text-info-emphasis"
              title="<?= e(__t('cu_unlock.badge.unlocked_title')) ?>">
            <?= e(__t('cu_unlock.badge.unlocked')) ?>
        </span>
    <?php else: ?>
        <span class="text-muted">—</span>
    <?php endif; ?>
<?php else: ?>
    <form method="POST" action="/teacher/cu/<?= $cuId ?>/unlock" class="d-inline">
        <?= csrf_field() ?>
        <input type="hidden" name="student_user_id" value="<?= $u_studentId ?>">
        <input type="hidden" name="action" value="<?= $u_isUnlocked ? 'lock' : 'unlock' ?>">
        <button type="submit"
                class="btn btn-sm <?= $u_isUnlocked ? 'btn-info' : 'btn-outline-secondary' ?> py-0 px-2"
                title="<?= e(__t($u_isUnlocked ? 'cu_unlock.btn.lock_title' : 'cu_unlock.btn.unlock_title')) ?>">
            <?= $u_isUnlocked ? '🔓' : '🔒' ?>
        </button>
    </form>
<?php endif; ?>
