<?php
declare(strict_types=1);

/**
 * Impressão do ranking (professor e aluno). Incluído dentro do conteúdo das
 * duas telas de ranking; usa as variáveis delas: $window, $groupId, $groups,
 * $year, $school, $trainer, $page, $lastPage, $courseId, $courseShared.
 *
 * - O botão "Imprimir" (window.print) fica em cada tela, com d-print-none.
 * - O CSS de impressão fica inline aqui de propósito: o CSS estático não tem
 *   ?v= e ficaria em cache após o deploy.
 * - Linha "só no papel" diz qual recorte foi impresso (janela, filtros, data).
 */

// Curso compartilhado (computeForCourse) ignora grupo/ano/escola/trainer:
// a legenda não pode citar filtro que não foi aplicado.
$printShared = $courseId > 0 && $courseShared;
$printNow    = date('Y-m-d H:i:s');

$printParts = [__t('ranking.window.' . $window)];
if (!$printShared && $groupId !== null) {
    foreach ($groups as $g) {
        if ((int) $g['id'] === $groupId) {
            $printParts[] = __t('ranking.filter.group') . ': ' . (string) $g['name'];
            break;
        }
    }
}
if (!$printShared) {
    $printParts[] = __t('ranking.filter.year') . ': ' . ($year !== null ? (string) $year : __t('ranking.filter.all_years'));
}
if ($school !== null) {
    $printParts[] = __t('ranking.filter.school') . ': ' . $school;
}
if ($trainer !== null) {
    $printParts[] = __t('ranking.filter.trainer.' . $trainer);
}
if ($lastPage > 1) {
    $printParts[] = __t('ranking.pagination.page_x_of_y', ['x' => (string) $page, 'y' => (string) $lastPage]);
}
?>
<style>
@media print {
    .lms-navbar, .lms-bottom-nav, .lms-student-sidebar, body > footer,
    .lms-ranking-avatar, .lms-online-dot { display: none !important; }
    /* Patente e selo são texto claro sobre fundo colorido; sem isso o navegador
       descarta o fundo (padrão "sem gráficos de fundo") e o texto some. */
    .lms-rank-pill, .badge { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .lms-student-grid { display: block !important; }
    .lms-student-main { width: 100% !important; max-width: none !important; }
    body { background: #fff !important; }
    main { padding: 0 !important; }
    .table-responsive { overflow: visible !important; }
    .lms-ranking-card, .card { border: 0 !important; box-shadow: none !important; }
    tr { break-inside: avoid; }
    a[href]::after { content: none !important; }
}
</style>
<p class="d-none d-print-block small text-muted mb-2">
    <?= e(implode(' · ', $printParts)) ?> · <?= e(__t('ranking.print.generated', ['date' => format_short_date($printNow) . ' ' . date('H:i', strtotime($printNow))])) ?>
</p>
