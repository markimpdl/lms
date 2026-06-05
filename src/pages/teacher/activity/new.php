<?php
declare(strict_types=1);

/**
 * GET /teacher/cu/{id}/activity/new — form de nova atividade (E6-01)
 * POST do mesmo caminho cria a atividade e redireciona pra tela de edição.
 *
 * Instrução passa pelo ContentSanitizer antes de gravar. XP ≥ 0.
 * Tipo restrito ao ENUM `Activity::TYPES`.
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

$cuId = (int) ($_REQUEST['id'] ?? 0);
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

$old = [
    'title'                 => '',
    'instruction'           => '',
    'type'                  => 'projeto',
    'code_language'         => null,
    'xp_value'              => 0,
    'submission_open'       => true,
    'allow_online_code_run' => false,
];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_verify();
    } catch (RuntimeException) {
        flash('danger', __t('auth.forbidden'));
        header('Location: /teacher/cu/' . $cuId . '/activity/new');
        return;
    }

    $rawLang = trim((string) ($_POST['code_language'] ?? ''));
    $old = [
        'title'                 => trim((string) ($_POST['title']       ?? '')),
        'instruction'           => (string)       ($_POST['instruction'] ?? ''),
        'type'                  => (string)       ($_POST['type']        ?? ''),
        'code_language'         => $rawLang !== '' ? $rawLang : null,
        'xp_value'              => (int)          ($_POST['xp_value']    ?? 0),
        'submission_open'       => isset($_POST['submission_open']),
        'allow_online_code_run' => isset($_POST['allow_online_code_run']),
    ];

    if (mb_strlen($old['title']) < 3 || mb_strlen($old['title']) > 200) {
        $errors['title'] = 'activities.form.err.title';
    }
    if (!in_array($old['type'], Activity::TYPES, true)) {
        $errors['type'] = 'activities.form.err.type';
    }
    if ($old['code_language'] !== null && !in_array($old['code_language'], Activity::CODE_LANGUAGES, true)) {
        $errors['code_language'] = 'activities.form.err.code_language';
    }
    if ($old['xp_value'] < 0 || $old['xp_value'] > 9999) {
        $errors['xp_value'] = 'activities.form.err.xp';
    }
    // code_run e code_language só valem se type=codigo
    if ($old['allow_online_code_run'] && $old['type'] !== 'codigo') {
        $old['allow_online_code_run'] = false;
    }
    if ($old['type'] !== 'codigo') {
        $old['code_language'] = null;
    }

    // Brief PDF/ZIP — opcional, só pra type=projeto (v0.30.0)
    $fileField = $_FILES['pdf'] ?? null;
    $hasUpload = is_array($fileField) && (int) ($fileField['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

    if ($errors === []) {
        $clean = ContentSanitizer::purify($old['instruction']);
        $result = Activity::create($cuId, $tenantId, [
            'title'                 => $old['title'],
            'instruction'           => $clean,
            'type'                  => $old['type'],
            'code_language'         => $old['code_language'],
            'pdf_path'              => null,
            'xp_value'              => $old['xp_value'],
            'submission_open'       => $old['submission_open'],
            'allow_online_code_run' => $old['allow_online_code_run'],
        ]);

        if (is_int($result)) {
            // Brief upload: rola depois do INSERT pra ter o id no path.
            // Se falhar, ROLLBACK manual via Activity::delete (atividade
            // sem dados úteis ainda — sem submissões nem XP).
            if ($old['type'] === 'projeto' && $hasUpload) {
                $upload = ActivityBriefStorage::store($fileField, $result, $tenantId);
                if ($upload['status'] !== 'ok') {
                    Activity::delete($result, $tenantId, $old['title']);
                    $errors['pdf'] = $upload['error_key'] ?? 'activities.err.brief_generic';
                    // Cai pro re-render do form com $errors preenchido
                    goto renderForm;
                }
                Database::pdo()
                    ->prepare('UPDATE activities SET pdf_path = ? WHERE id = ?')
                    ->execute([$upload['stored_path'], $result]);
            }

            // E20-07: type=quiz redireciona pro form do quiz e NÃO dispara
            // fanout activity_new aqui — atividade-quiz sem questões é
            // inutilizável pro aluno; fanout fica diferido (não automatizado
            // ainda) até o professor configurar o quiz.
            if ($old['type'] === 'quiz') {
                flash('success', __t('activities.quiz_created', ['name' => $old['title']]));
                header('Location: /teacher/activity/' . $result . '/quiz', true, 303);
                return;
            }

            // Fanout `activity_new` (E10-05) — só sino, sem email (decisão do
            // PO 2026-04-24: email de atividade nova gera ruído). Dispara só
            // quando a entrega já nasce aberta; em submission_open=0 a atividade
            // é draft e não há ação pro aluno ainda.
            if ($old['submission_open']) {
                $courseId   = (int) $cu['course_id'];
                $studentIds = Enrollment::activeStudentIdsForCourse($courseId, $tenantId);
                if ($studentIds !== []) {
                    NotificationService::fanout(
                        NotificationService::EVENT_ACTIVITY_NEW,
                        $studentIds,
                        $old['title'],
                        null,
                        '/student/activity/' . $result,
                        $courseId,
                        false
                    );
                }
            }

            flash('success', __t('activities.created', ['name' => $old['title']]));
            // E23-02: redireciona pra CU (era /edit; agora pro ponto de retorno natural).
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

renderForm:
$mode           = 'new';
$formAction     = '/teacher/cu/' . $cuId . '/activity/new';
$submissions    = 0;
$activityId     = null;
$activityName   = (string) $cu['name'];
$currentPdfPath = null;
$maxMb          = (int) ((ActivityBriefStorage::maxBytes()) / (1024 * 1024));

$page_title = __t('activities.new.title');
ob_start();
require LMS_ROOT . '/src/pages/teacher/activity/_form.php';
$page_content = ob_get_clean();
require LMS_ROOT . '/src/templates/layout.php';
