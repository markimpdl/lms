<?php
declare(strict_types=1);

/**
 * POST /teacher/lesson/{id}/delete — exclusao permanente da licao com
 * confirmacao por digitacao do titulo (E36-03, padrao E3-05/E6-05).
 *
 * `lesson_completions` cai por CASCADE. Os `xp_events` da licao sao removidos
 * dentro de `Lesson::delete` — nao ha FK pra eles (source_type/source_id sao
 * polimorficos, ADR-020), entao o XP sobreviveria a licao apagada.
 *
 * A licao nao tem arquivo proprio no storage (imagens vem dos anexos da CU,
 * que continuam existindo), entao nao ha limpeza de disco aqui.
 */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}

// E32 (ADR-033): conteúdo via tenant do dono (dono ou colaborador).
$lessonId   = (int) ($_REQUEST['id'] ?? 0);
$__courseId = Lesson::courseIdOf($lessonId);
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
    header('Location: /teacher');
    exit;
}

$lesson = Lesson::findForTenant($lessonId, $tenantId);
if ($lesson === null) {
    http_response_code(404);
    require LMS_ROOT . '/src/templates/errors/404.php';
    return;
}

$cuId         = (int)    $lesson['competence_unit_id'];
$title        = (string) $lesson['title'];
$expectedName = (string) ($_POST['expected_name'] ?? '');

$result = Lesson::delete($lessonId, $tenantId, $expectedName);

if ($result['status'] === 'name_mismatch') {
    flash('danger', __t('delete.err.name_mismatch'));
    header('Location: /teacher/lesson/' . $lessonId . '/edit', true, 303);
    return;
}
if ($result['status'] === 'course_archived') {
    flash('danger', __t('courses.edit.archived_notice'));
    header('Location: /teacher/cu/' . $cuId, true, 303);
    return;
}
if ($result['status'] !== 'ok') {
    http_response_code(404);
    require LMS_ROOT . '/src/templates/errors/404.php';
    return;
}

course_audit((int) $__courseId, 'delete', 'lesson', $lessonId, $title);

// A trilha fica com um buraco na numeracao (ex.: 1, 2, 4). Isso NAO quebra
// nada — a ordem eh relativa, e o proximo reorder redensifica. Nao vale uma
// transacao extra so pra fechar o buraco.
flash('success', __t('lessons.flash.deleted', ['title' => $title]));
header('Location: /teacher/cu/' . $cuId, true, 303);
