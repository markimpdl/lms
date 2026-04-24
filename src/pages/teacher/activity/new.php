<?php
declare(strict_types=1);

/**
 * GET /teacher/cu/{id}/activity/new — form de nova atividade (E6-01)
 * POST do mesmo caminho cria a atividade e redireciona pra tela de edição.
 *
 * Instrução passa pelo ContentSanitizer antes de gravar. XP ≥ 0.
 * Tipo restrito ao ENUM `Activity::TYPES`.
 */

$tenantId = current_tenant_id();
if ($tenantId === null) {
    http_response_code(403);
    require LMS_ROOT . '/src/templates/errors/403.php';
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

    $old = [
        'title'                 => trim((string) ($_POST['title']       ?? '')),
        'instruction'           => (string)       ($_POST['instruction'] ?? ''),
        'type'                  => (string)       ($_POST['type']        ?? ''),
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
    if ($old['xp_value'] < 0 || $old['xp_value'] > 9999) {
        $errors['xp_value'] = 'activities.form.err.xp';
    }
    // code_run só vale se type=codigo
    if ($old['allow_online_code_run'] && $old['type'] !== 'codigo') {
        $old['allow_online_code_run'] = false;
    }

    if ($errors === []) {
        $clean = ContentSanitizer::purify($old['instruction']);
        $result = Activity::create($cuId, $tenantId, [
            'title'                 => $old['title'],
            'instruction'           => $clean,
            'type'                  => $old['type'],
            'xp_value'              => $old['xp_value'],
            'submission_open'       => $old['submission_open'],
            'allow_online_code_run' => $old['allow_online_code_run'],
        ]);

        if (is_int($result)) {
            // Fanout `activity_new` (E10-05) — só sino, sem email (decisão do
            // PO 2026-04-24: email de atividade nova gera ruído). Dispara só
            // quando a entrega já nasce aberta; em submission_open=0 a atividade
            // é draft e não há ação pro aluno ainda.
            if ($old['submission_open']) {
                $courseId   = (int) $cu['course_id'];
                $studentIds = Enrollment::activeStudentIdsForCourse($courseId, $tenantId);
                if ($studentIds !== []) {
                    NotificationService::fanout(
                        'activity_new',
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
            header('Location: /teacher/activity/' . $result . '/edit', true, 303);
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

$mode         = 'new';
$formAction   = '/teacher/cu/' . $cuId . '/activity/new';
$submissions  = 0;
$activityId   = null;
$activityName = (string) $cu['name'];

$page_title = __t('activities.new.title');
ob_start();
require LMS_ROOT . '/src/pages/teacher/activity/_form.php';
$page_content = ob_get_clean();
require LMS_ROOT . '/src/templates/layout.php';
