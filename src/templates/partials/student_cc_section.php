<?php
declare(strict_types=1);

/**
 * Partial: Section header de uma Core Competence no painel do aluno (E14-03).
 *
 * Renderiza o card de cabeçalho (ícone gradient + eyebrow "CORE COMPETENCE N"
 * + H2 + barra linear de progresso + ProgressRing à direita) seguido da grid
 * de UnitCards. CCs sem CUs mostram mensagem.
 *
 * Espera no escopo:
 *   $cc              array com id, name, cus (lista enriquecida)
 *   $ccIndex         int — numeração 1..N na ordem
 *   $ccPercent       int
 *   $ccUnitsDone     int
 *   $ccUnitsTot      int
 *   $gradStart       string (hex) — gradient usado pra cor da CC (igual ao cover)
 *   $gradEnd         string (hex)
 *   $studentId       int — pra cada UnitCard calcular status
 *   $ccStatus        string (E19-02) — 'current'|'next'|'completed'|'free'
 *                    ('hidden' já foi filtrado pelo caller)
 *   $ccLockedByName  ?string (E19-02) — nome da CC atual; usado no overlay quando $ccStatus='next'
 *   $cuStatusMap     array<int,string> (E19-02) — mapa cu_id => status
 *   $cuLockedByName  ?string (E19-02) — nome da CU atual; passado pro unit_card
 *   $draftCusForCc   array<int,true> — cu_ids em rascunho (ver UnitDraftGate)
 */

$gradient = sprintf('linear-gradient(135deg, %s, %s)', $gradStart, $gradEnd);
$ccStatus = (string) ($ccStatus ?? 'free');
$ccLockedByName = $ccLockedByName ?? null;
$ccSectionClass = 'lms-cc-section';
if ($ccStatus === 'next') {
    $ccSectionClass .= ' lms-cc-section--locked';
} elseif ($ccStatus === 'completed') {
    $ccSectionClass .= ' lms-cc-section--completed';
}
?>
<section class="<?= e($ccSectionClass) ?>">
    <header class="lms-cc-header">
        <div class="lms-cc-header__icon" style="background: <?= e($gradient) ?>;" aria-hidden="true">
            <?= (int) $ccIndex ?>
        </div>
        <div class="lms-cc-header__body">
            <span class="lms-cc-header__eyebrow" style="color: <?= e($gradStart) ?>;">
                <?= e(__t('student.course_page.cc_n', ['n' => (string) $ccIndex])) ?>
            </span>
            <h2 class="lms-cc-header__title"><?= e((string) $cc['name']) ?></h2>
            <div class="lms-cc-header__summary">
                <?= e(__t('student.course_page.cc_units_summary', [
                    'done'  => (string) $ccUnitsDone,
                    'total' => (string) $ccUnitsTot,
                ])) ?>
            </div>
            <div class="lms-cc-header__bar" aria-hidden="true">
                <div class="lms-cc-header__bar-fill"
                     style="width: <?= (int) $ccPercent ?>%; background: <?= e($gradient) ?>;"></div>
            </div>
        </div>
        <div class="lms-cc-header__ring">
            <div class="lms-progress-ring"
                 style="--pct: <?= (int) $ccPercent ?>;"
                 role="progressbar"
                 aria-valuenow="<?= (int) $ccPercent ?>" aria-valuemin="0" aria-valuemax="100">
                <span class="lms-progress-ring__label"><?= (int) $ccPercent ?>%</span>
            </div>
            <span class="lms-cc-header__ring-label"><?= e(__t('student.course_page.overall')) ?></span>
        </div>
    </header>

    <?php
        // Monta a lista renderizavel ANTES do loop, porque "some da tela" e
        // "some da numeracao" nao sao a mesma coisa:
        //
        //  - RASCUNHO nao existe pro aluno (a progressao o pula, o % o ignora),
        //    entao tambem nao ocupa numero — com o indice cru, um rascunho no
        //    meio produzia "Unidade 1, Unidade 3".
        //  - 'hidden' por progressao eh unidade que existe e ainda vai chegar:
        //    ela MANTEM o numero real. Renumerar sobre a lista visivel quebrava
        //    o unlock manual (E36-02), onde a CU 5 liberada sozinha numa CC
        //    travada aparecia rotulada "Unidade 1" — e mudava de rotulo a cada
        //    nova unidade liberada.
        //
        // O filtro tambem responde se a CC ficou sem nada pra mostrar, caso em
        // que o caller nem chega aqui (ver student/course/show.php).
        $visibleUnits = [];
        $unitLabelNum = 0;
        foreach ($cc['cus'] as $unit) {
            $unitId = (int) $unit['id'];
            if (isset($draftCusForCc[$unitId])) {
                continue;
            }
            $unitLabelNum++;
            $st = $cuStatusMap[$unitId] ?? 'free';
            if ($st === 'hidden') {
                continue;
            }
            $visibleUnits[] = ['unit' => $unit, 'status' => $st, 'index' => $unitLabelNum];
        }
    ?>
    <?php if ($ccStatus === 'next'): ?>
        <div class="lms-cc-section__lock-overlay">
            <?= e(__t('progression.next_locked', ['name' => (string) ($ccLockedByName ?? '')])) ?>
        </div>
    <?php elseif ($visibleUnits === []): ?>
        <p class="lms-cc-section__empty"><?= e(__t('dashboard.student.cc_empty')) ?></p>
    <?php else: ?>
        <div class="lms-unit-grid">
            <?php foreach ($visibleUnits as $visible): ?>
                <?php
                    $unit      = $visible['unit'];
                    $cuStatus  = $visible['status'];
                    $unitIndex = $visible['index'];
                    require LMS_ROOT . '/src/templates/partials/unit_card.php';
                ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
