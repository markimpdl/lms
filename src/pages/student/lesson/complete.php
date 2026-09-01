<?php
declare(strict_types=1);

/**
 * POST /student/lesson/{id}/complete — marca ou desmarca a licao como
 * concluida (E36-05) e leva o aluno pro proximo item da trilha.
 *
 * `action=complete`   -> grava a conclusao, credita XP, redireciona pro proximo
 * `action=uncomplete` -> desfaz a conclusao e revoga o XP, fica na licao
 *
 * As duas pontas sao idempotentes (PK composta em lesson_completions, UK
 * composite em xp_events), entao duplo clique ou refresh do POST nao duplica
 * XP nem quebra.
 */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}

$user = current_user();
if ($user === null || ($user['role'] ?? '') !== 'student') {
    http_response_code(403);
    require LMS_ROOT . '/src/templates/errors/403.php';
    return;
}

$studentId = (int) $user['id'];
$lessonId  = (int) ($_REQUEST['id'] ?? 0);

// findForStudent ja valida matricula E published=1 — aluno nao marca licao de
// curso em que nao esta, nem rascunho do professor.
$lesson = Lesson::findForStudent($lessonId, $studentId);
if ($lesson === null) {
    http_response_code(404);
    require LMS_ROOT . '/src/templates/errors/404.php';
    return;
}

$cuId     = (int) $lesson['competence_unit_id'];
$courseId = (int) $lesson['course_id'];
$backUrl  = '/student/lesson/' . $lessonId;

try {
    csrf_verify();
} catch (RuntimeException) {
    flash('danger', __t('auth.forbidden'));
    header('Location: ' . $backUrl, true, 303);
    exit;
}

// Mesmo gate de disponibilidade da tela — curso fora da janela nao aceita
// escrita, senao o aluno registraria progresso em curso encerrado.
$availability = enrollment_access_status($studentId, $courseId);
if (!$availability['available']) {
    flash('warning', $availability['message'] ?? __t('enrollment.unavailable.generic'));
    header('Location: /student', true, 303);
    exit;
}

$action = (string) ($_POST['action'] ?? 'complete');

if ($action === 'uncomplete') {
    LessonCompletion::uncomplete($lessonId, $studentId);
    XpEvents::revokeLesson($studentId, $lessonId);
    flash('info', __t('track.flash.uncompleted'));
    header('Location: ' . $backUrl, true, 303);
    exit;
}

LessonCompletion::complete($lessonId, $studentId);

$awarded = XpEvents::awardLesson($studentId, $lessonId);
$xp      = (int) $lesson['xp_value'];

// Conquistas podem depender da CU ter fechado — best-effort, igual ao resto.
$tenantId = (int) ($user['tenant_id'] ?? 0);
if ($tenantId > 0) {
    student_progression_check($studentId, $tenantId, $cuId);
}

flash(
    'success',
    $awarded && $xp > 0
        ? __t('track.flash.completed_xp', ['xp' => (string) $xp])
        : __t('track.flash.completed')
);

// Segue pro proximo item da trilha. Sem proximo, volta pra capa da unidade —
// eh o fim do percurso, e a capa mostra o progresso fechado.
$next = UnitTrackService::neighbors($cuId, 'lesson', $lessonId, true)['next'];
if ($next === null) {
    header('Location: /student/cu/' . $cuId, true, 303);
    exit;
}

$target = match ($next['type']) {
    'lesson'   => '/student/lesson/' . $next['id'],
    'activity' => '/student/activity/' . $next['id'],
    default    => '/student/evaluation/' . $next['id'],
};
header('Location: ' . $target, true, 303);
