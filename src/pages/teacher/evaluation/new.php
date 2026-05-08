<?php
declare(strict_types=1);

/**
 * GET /teacher/cu/{id}/evaluation/new — form de nova avaliação (E7-01).
 * POST cria a avaliação e redireciona pra tela de edição.
 *
 * PDF é opcional (paridade com Activity::create — brief pode ficar só no
 * TinyMCE). Upload, quando houver, roda depois do INSERT pra ter o
 * evaluation_id no path; se o upload falhar, o INSERT é desfeito.
 * instructions passa pelo ContentSanitizer antes de gravar.
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

$existing = Evaluation::findByCu($cuId, $tenantId);
if ($existing !== null) {
    flash('warning', __t('evaluations.already_exists'));
    header('Location: /teacher/evaluation/' . (int) $existing['id'] . '/edit', true, 303);
    return;
}

if ((int) ($cu['manual_completion_enabled'] ?? 0) === 1) {
    flash('danger', __t('evaluations.err.manual_completion_active'));
    header('Location: /teacher/cu/' . $cuId, true, 303);
    return;
}

// E25-04: curso em LO mode esconde quiz no select (auto-corrigido vs LO
// exige feedback manual). Pre-loaded via CompetenceUnit::findForTenant
// (audit em #318).
$isLoMode = (string) ($cu['course_grading_mode'] ?? 'grade') === 'learning_outcomes';

$old = [
    'title'           => '',
    'instructions'    => '',
    'type'            => 'projeto',
    'xp_value'        => 0,
    'submission_open' => true,
];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_verify();
    } catch (RuntimeException) {
        flash('danger', __t('auth.forbidden'));
        header('Location: /teacher/cu/' . $cuId . '/evaluation/new');
        return;
    }

    $old = [
        'title'           => trim((string) ($_POST['title']        ?? '')),
        'instructions'    => (string)       ($_POST['instructions'] ?? ''),
        'type'            => (string)       ($_POST['type']         ?? 'projeto'),
        'xp_value'        => (int)          ($_POST['xp_value']    ?? 0),
        'submission_open' => isset($_POST['submission_open']),
    ];

    if (mb_strlen($old['title']) < 3 || mb_strlen($old['title']) > 200) {
        $errors['title'] = 'evaluations.form.err.title';
    }
    if (!in_array($old['type'], Evaluation::TYPES, true)) {
        $errors['type'] = 'evaluations.form.err.type';
    }
    // E25-04: defesa server-side — bloqueia type=quiz quando curso em LO mode
    // mesmo se POST manipulado bypassa o esconder do UI.
    if ($isLoMode && $old['type'] === 'quiz') {
        $errors['type'] = 'evaluations.err.quiz_not_in_lo_mode';
    }
    if ($old['xp_value'] < 0 || $old['xp_value'] > 9999) {
        $errors['xp_value'] = 'evaluations.form.err.xp';
    }

    // PDF é opcional: só processa upload se o professor anexou arquivo.
    // Quiz não usa upload.
    $fileField = $_FILES['pdf'] ?? null;
    $hasUpload = is_array($fileField) && (int) ($fileField['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

    if ($errors === []) {
        $clean  = ContentSanitizer::purify($old['instructions']);
        $create = Evaluation::create($cuId, $tenantId, [
            'title'           => $old['title'],
            'instructions'    => $clean,
            'type'            => $old['type'],
            'pdf_path'        => null,
            'xp_value'        => $old['xp_value'],
            'submission_open' => $old['submission_open'],
        ]);

        if ($create === 'course_archived') {
            flash('danger', __t('courses.edit.archived_notice'));
            header('Location: /teacher/cu/' . $cuId);
            return;
        }
        if ($create === 'already_exists') {
            flash('warning', __t('evaluations.already_exists'));
            $again = Evaluation::findByCu($cuId, $tenantId);
            $redir = $again !== null ? '/teacher/evaluation/' . (int) $again['id'] . '/edit' : '/teacher/cu/' . $cuId;
            header('Location: ' . $redir, true, 303);
            return;
        }
        if (!is_int($create)) {
            http_response_code(404);
            require LMS_ROOT . '/src/templates/errors/404.php';
            return;
        }

        $evaluationId = $create;

        // Type=quiz: sem upload, redireciona pro form do quiz pra adicionar
        // questões. Fanout new_evaluation só dispara após o quiz ser
        // configurado (avaliação sem questão é inutilizável pro aluno).
        if ($old['type'] === 'quiz') {
            flash('success', __t('evaluations.quiz_created', ['name' => $old['title']]));
            header('Location: /teacher/evaluation/' . $evaluationId . '/quiz', true, 303);
            return;
        }

        if ($hasUpload) {
            $upload = EvaluationBriefStorage::store($fileField, $evaluationId, $tenantId);
            if ($upload['status'] !== 'ok') {
                // Rollback do INSERT — PDF foi anexado mas falhou o storage.
                Evaluation::delete($evaluationId, $tenantId, $old['title']);
                $errors['pdf'] = $upload['error_key'] ?? 'evaluations.err.brief_generic';
                goto renderForm;
            }
            Database::pdo()
                ->prepare('UPDATE evaluations SET pdf_path = ? WHERE id = ?')
                ->execute([$upload['stored_path'], $evaluationId]);
        }

        // Fanout `new_evaluation` (E10-04) — notifica alunos matriculados
        // no curso. Idioma segue `courses.language` via courseId.
        $courseId  = (int) $cu['course_id'];
        $studentIds = Enrollment::activeStudentIdsForCourse($courseId, $tenantId);
        if ($studentIds !== []) {
            NotificationService::fanout(
                NotificationService::EVENT_NEW_EVALUATION,
                $studentIds,
                $old['title'],
                null,
                '/student/evaluation/' . $evaluationId,
                $courseId
            );
        }

        flash('success', __t('evaluations.created', ['name' => $old['title']]));
        // E23-02: redireciona pra CU (era /edit; agora pro ponto de retorno natural).
        header('Location: /teacher/cu/' . $cuId, true, 303);
        return;
    }
}

renderForm:
$mode           = 'new';
$formAction     = '/teacher/cu/' . $cuId . '/evaluation/new';
$submissions    = 0;
$evaluationId   = null;
$evaluationName = (string) $cu['name'];
$currentPdfPath = null;

$page_title = __t('evaluations.new.title');
ob_start();
require LMS_ROOT . '/src/pages/teacher/evaluation/_form.php';
$page_content = ob_get_clean();
require LMS_ROOT . '/src/templates/layout.php';
