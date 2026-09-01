<?php
declare(strict_types=1);

/**
 * GET  /teacher/cu/{id}/lesson/new — form de nova licao (E36-03)
 * POST do mesmo caminho cria e volta pra CU.
 *
 * So faz sentido em curso V2 (trilha): em V1 a CU tem uma pagina unica de
 * conteudo e nao existe licao. O gate por `structure_version` eh server-side —
 * a UI ja nao mostra o botao, mas nada impede colar a URL.
 *
 * `html` passa por ContentSanitizer::purify() antes de gravar.
 */

// E32 (ADR-033): conteúdo via tenant do dono (dono ou colaborador).
$__cuId     = (int) ($_REQUEST['id'] ?? 0);
$__courseId = CompetenceUnit::courseIdOf($__cuId);
$tenantId   = $__courseId !== null ? effective_authoring_tenant($__courseId) : null;
if ($tenantId === null) {
    http_response_code(404);
    require LMS_ROOT . '/src/templates/errors/404.php';
    return;
}

$cuId = $__cuId;
$cu   = CompetenceUnit::findForTenant($cuId, $tenantId);
if ($cu === null) {
    http_response_code(404);
    require LMS_ROOT . '/src/templates/errors/404.php';
    return;
}
if ((int) $cu['course_archived'] === 1) {
    flash('danger', __t('courses.edit.archived_notice'));
    header('Location: /teacher/cu/' . $cuId);
    return;
}
if ((int) ($cu['course_structure_version'] ?? 1) !== 2) {
    flash('warning', __t('lessons.err.not_v2'));
    header('Location: /teacher/cu/' . $cuId, true, 303);
    return;
}

$old = [
    'title'     => '',
    'html'      => '',
    'xp_value'  => 0,
    'published' => false,
];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_verify();
    } catch (RuntimeException) {
        flash('danger', __t('auth.forbidden'));
        header('Location: /teacher/cu/' . $cuId . '/lesson/new');
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

        $result = Lesson::create($cuId, $tenantId, $data);
        if (is_int($result)) {
            course_audit((int) $__courseId, 'create', 'lesson', $result, $old['title']);
            flash('success', __t('lessons.flash.created'));
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

$courseId   = (int) $cu['course_id'];
$formAction = '/teacher/cu/' . $cuId . '/lesson/new';
$isEdit     = false;
$lessonId   = null;

$page_title = __t('lessons.new.title');

ob_start();
require LMS_ROOT . '/src/pages/teacher/lesson/_form.php';
$page_content = ob_get_clean();
require LMS_ROOT . '/src/templates/layout.php';
