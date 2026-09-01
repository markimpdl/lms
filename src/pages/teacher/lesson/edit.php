<?php
declare(strict_types=1);

/**
 * GET  /teacher/lesson/{id}/edit — form de edicao da licao (E36-03)
 * POST do mesmo caminho salva e volta pra CU.
 *
 * `html` passa por ContentSanitizer::purify() antes de gravar.
 * `position` nao eh editavel aqui — a ordem eh do UnitTrackService::reorder.
 */

$lessonId   = (int) ($_REQUEST['id'] ?? 0);
$__courseId = Lesson::courseIdOf($lessonId);
$tenantId   = $__courseId !== null ? effective_authoring_tenant($__courseId) : null;
if ($tenantId === null) {
    http_response_code(404);
    require LMS_ROOT . '/src/templates/errors/404.php';
    return;
}

$lesson = Lesson::findForTenant($lessonId, $tenantId);
if ($lesson === null) {
    http_response_code(404);
    require LMS_ROOT . '/src/templates/errors/404.php';
    return;
}

$cuId = (int) $lesson['competence_unit_id'];
$cu   = CompetenceUnit::findForTenant($cuId, $tenantId);
if ($cu === null) {
    http_response_code(404);
    require LMS_ROOT . '/src/templates/errors/404.php';
    return;
}
if ((int) $lesson['course_archived'] === 1) {
    flash('danger', __t('courses.edit.archived_notice'));
    header('Location: /teacher/cu/' . $cuId);
    return;
}

$old = [
    'title'     => (string) $lesson['title'],
    'html'      => (string) $lesson['html'],
    'xp_value'  => (int)    $lesson['xp_value'],
    'published' => (int)    $lesson['published'] === 1,
];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_verify();
    } catch (RuntimeException) {
        flash('danger', __t('auth.forbidden'));
        header('Location: /teacher/lesson/' . $lessonId . '/edit');
        return;
    }

    $old = [
        'title'     => trim((string) ($_POST['title'] ?? '')),
        'html'      => (string)       ($_POST['html']  ?? ''),
        'xp_value'  => (int)          ($_POST['xp_value'] ?? 0),
        'published' => isset($_POST['published']),
    ];

    $errors = lesson_validate($old);

    if ($errors === []) {
        $data = $old;
        $data['html'] = ContentSanitizer::purify($old['html']);

        $result = Lesson::update($lessonId, $tenantId, $data);
        if ($result === 'ok') {
            course_audit((int) $__courseId, 'update', 'lesson', $lessonId, $old['title']);
            flash('success', __t('lessons.flash.saved'));
            header('Location: /teacher/cu/' . $cuId, true, 303);
            return;
        }
        if ($result === 'course_archived') {
            flash('danger', __t('courses.edit.archived_notice'));
            header('Location: /teacher/cu/' . $cuId);
            return;
        }
        http_response_code(404);
        require LMS_ROOT . '/src/templates/errors/404.php';
        return;
    }
}

$imagePickerOptions = lesson_image_picker_options($cuId, $tenantId);

// Aviso do modal de exclusao: quantos alunos ja concluiram esta licao. Apagar
// remove as conclusoes (CASCADE) e o XP creditado — o professor tem de ver o
// tamanho do estrago antes de confirmar.
$completions  = Lesson::countCompletions($lessonId);
$deleteCounts = $completions > 0
    ? [__t('lessons.delete.count_completions', ['count' => (string) $completions])]
    : [];

$courseId   = (int) $lesson['course_id'];
$formAction = '/teacher/lesson/' . $lessonId . '/edit';
$isEdit     = true;

$page_title = __t('lessons.edit.title');

ob_start();
require LMS_ROOT . '/src/pages/teacher/lesson/_form.php';
$page_content = ob_get_clean();
require LMS_ROOT . '/src/templates/layout.php';
