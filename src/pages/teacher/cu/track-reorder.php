<?php
declare(strict_types=1);

/**
 * POST /teacher/cu/{id}/track/reorder — reordena a trilha da CU (E36-04).
 *
 * Aceita DUAS formas de pedido, pelo mesmo endpoint:
 *
 *  1. `order[]` — a trilha inteira na ordem nova, cada item no formato
 *     "tipo:id" (ex.: "lesson:12"). Eh o que o arrastar envia.
 *  2. `move` no formato "tipo:id:direcao" — sobe ou desce UM item. Vem no
 *     `value` do proprio <button type=submit>, que o navegador envia sozinho:
 *     nao depende de JavaScript nenhum. O servidor calcula a ordem resultante
 *     e cai no mesmo caminho do item 1.
 *
 * As duas terminam em `UnitTrackService::reorder`, que valida pertencimento a
 * CU e cobertura total da trilha antes de gravar qualquer coisa.
 */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}

// E32 (ADR-033): conteúdo via tenant do dono (dono ou colaborador).
$cuId       = (int) ($_REQUEST['id'] ?? 0);
$__courseId = CompetenceUnit::courseIdOf($cuId);
$tenantId   = $__courseId !== null ? effective_authoring_tenant($__courseId) : null;
if ($tenantId === null) {
    http_response_code(404);
    require LMS_ROOT . '/src/templates/errors/404.php';
    return;
}

try {
    csrf_verify();
} catch (RuntimeException) {
    flash('danger', __t('auth.forbidden'));
    header('Location: /teacher/cu/' . $cuId, true, 303);
    exit;
}

$cu = CompetenceUnit::findForTenant($cuId, $tenantId);
if ($cu === null) {
    http_response_code(404);
    require LMS_ROOT . '/src/templates/errors/404.php';
    return;
}

$backUrl = '/teacher/cu/' . $cuId;

if ((int) ($cu['course_structure_version'] ?? 1) !== 2) {
    flash('warning', __t('lessons.err.not_v2'));
    header('Location: ' . $backUrl, true, 303);
    exit;
}

/**
 * Converte "tipo:id" no par validado. Retorna null pra qualquer coisa fora do
 * formato — o `reorder` recusa a lista inteira se algo nao bater.
 *
 * @return array{type:string,id:int}|null
 */
$parseToken = static function (string $token): ?array {
    $parts = explode(':', $token, 2);
    if (count($parts) !== 2) {
        return null;
    }
    [$type, $rawId] = $parts;
    if ($type !== 'lesson' && $type !== 'activity') {
        return null;
    }
    if (!ctype_digit($rawId)) {
        return null;
    }
    return ['type' => $type, 'id' => (int) $rawId];
};

$ordered = [];

// "tipo:id:direcao" — o value do botao de seta.
$moveType = '';
$moveId   = 0;
$moveDir  = '';
$moveRaw  = (string) ($_POST['move'] ?? '');
if ($moveRaw !== '') {
    $parts = explode(':', $moveRaw, 3);
    if (count($parts) === 3 && ctype_digit($parts[1])) {
        [$moveType, $rawMoveId, $moveDir] = $parts;
        $moveId = (int) $rawMoveId;
    }
    if ($moveType !== 'lesson' && $moveType !== 'activity') {
        flash('danger', __t('track.err.invalid'));
        header('Location: ' . $backUrl, true, 303);
        exit;
    }
}

if ($moveType !== '' && $moveId > 0) {
    // ---- Fallback sem JS: sobe/desce um item ----------------------------
    // Parte da trilha atual (sem a avaliacao, que nao tem position) e troca o
    // item de lugar com o vizinho. Se ja esta na ponta, nao ha o que fazer.
    if ($moveDir !== 'up' && $moveDir !== 'down') {
        flash('danger', __t('track.err.invalid'));
        header('Location: ' . $backUrl, true, 303);
        exit;
    }

    $current = [];
    foreach (UnitTrackService::forCu($cuId) as $item) {
        if ($item['type'] === 'evaluation') {
            continue;
        }
        $current[] = ['type' => $item['type'], 'id' => $item['id']];
    }

    $idx = null;
    foreach ($current as $i => $item) {
        if ($item['type'] === $moveType && $item['id'] === $moveId) {
            $idx = $i;
            break;
        }
    }
    if ($idx === null) {
        flash('danger', __t('track.err.invalid'));
        header('Location: ' . $backUrl, true, 303);
        exit;
    }

    $swap = $moveDir === 'up' ? $idx - 1 : $idx + 1;
    if ($swap < 0 || $swap >= count($current)) {
        // Ja esta na ponta — silencioso, nao eh erro do professor.
        header('Location: ' . $backUrl, true, 303);
        exit;
    }

    [$current[$idx], $current[$swap]] = [$current[$swap], $current[$idx]];
    $ordered = $current;
} else {
    // ---- Arrastar: a lista inteira vem no POST --------------------------
    $raw = $_POST['order'] ?? [];
    if (!is_array($raw) || $raw === []) {
        flash('danger', __t('track.err.invalid'));
        header('Location: ' . $backUrl, true, 303);
        exit;
    }
    foreach ($raw as $token) {
        $parsed = $parseToken((string) $token);
        if ($parsed === null) {
            flash('danger', __t('track.err.invalid'));
            header('Location: ' . $backUrl, true, 303);
            exit;
        }
        $ordered[] = $parsed;
    }
}

$result = UnitTrackService::reorder($cuId, $tenantId, $ordered);

if ($result === 'ok') {
    course_audit((int) $__courseId, 'update', 'competence_unit', $cuId, (string) $cu['name']);
    flash('success', __t('track.flash.reordered'));
    header('Location: ' . $backUrl, true, 303);
    exit;
}
if ($result === 'course_archived') {
    flash('danger', __t('courses.edit.archived_notice'));
    header('Location: ' . $backUrl, true, 303);
    exit;
}
if ($result === 'invalid') {
    // Tipicamente: dois professores reordenando a mesma CU, e a lista enviada
    // nao cobre mais a trilha real. Recarregar resolve.
    flash('warning', __t('track.err.stale'));
    header('Location: ' . $backUrl, true, 303);
    exit;
}

http_response_code(404);
require LMS_ROOT . '/src/templates/errors/404.php';
