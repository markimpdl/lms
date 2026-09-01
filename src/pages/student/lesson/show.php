<?php
declare(strict_types=1);

/**
 * /student/lesson/{id} — uma tela da trilha (E36-05).
 *
 * Conteudo da licao + timeline lateral + Anterior/Proximo + o botao
 * "Concluir e continuar".
 *
 * Gate de acesso: a navegacao dentro da CU eh LIVRE (decisao do PO), entao
 * aqui nao ha trava item a item. O que se valida eh o acesso a UNIDADE, com
 * exatamente as mesmas checagens de /student/cu/{id}:
 *   1. matricula (Lesson::findForStudent faz o JOIN em enrollments)
 *   2. janela de acesso do curso (enrollment_access_status)
 *   3. progressao sequencial entre CCs/CUs (course_progression_state), que ja
 *      inclui o desbloqueio manual do professor (E36-02)
 *
 * `html` foi sanitizado por ContentSanitizer na gravacao — renderiza sem e().
 */

$user = current_user();
if ($user === null || ($user['role'] ?? '') !== 'student') {
    http_response_code(403);
    require LMS_ROOT . '/src/templates/errors/403.php';
    return;
}

$studentId = (int) $user['id'];
$lessonId  = (int) ($_REQUEST['id'] ?? 0);

$lesson = Lesson::findForStudent($lessonId, $studentId);
if ($lesson === null) {
    http_response_code(404);
    require LMS_ROOT . '/src/templates/errors/404.php';
    return;
}

$cuId     = (int) $lesson['competence_unit_id'];
$courseId = (int) $lesson['course_id'];

// Gate de disponibilidade do curso (E17-03).
$availability = enrollment_access_status($studentId, $courseId);
if (!$availability['available']) {
    flash('warning', $availability['message'] ?? __t('enrollment.unavailable.generic'));
    header('Location: /student', true, 303);
    exit;
}

// Gate de progressao entre CCs/CUs — mesmo teste de /student/cu/{id}. Sem
// isso, colar a URL de uma licao daria acesso a uma unidade ainda travada.
$courseConfStmt = Database::pdo()->prepare('SELECT cc_mode FROM courses WHERE id = ? LIMIT 1');
$courseConfStmt->execute([$courseId]);
$ccMode = (string) ($courseConfStmt->fetchColumn() ?: 'sequential');

if ($ccMode === 'sequential') {
    $courseFull = StudentCurriculum::forStudentCourse($studentId, $courseId);
    if ($courseFull !== null) {
        $gate     = course_progression_state($courseFull, $studentId);
        $myStatus = $gate['cu_status'][$cuId] ?? 'free';
        if ($myStatus === 'hidden' || $myStatus === 'next') {
            flash('warning', __t('progression.cu_locked'));
            header('Location: /student/course/' . $courseId, true, 303);
            exit;
        }
    }
}

$timelineItems   = UnitTrackService::forStudentCu($cuId, $studentId);
$timelineCurrent = 'lesson:' . $lessonId;

$neighbors = UnitTrackService::neighbors($cuId, 'lesson', $lessonId, true);
$prev      = $neighbors['prev'];
$next      = $neighbors['next'];

$hrefFor = static fn (array $it): string => match ($it['type']) {
    'lesson'   => '/student/lesson/' . $it['id'],
    'activity' => '/student/activity/' . $it['id'],
    default    => '/student/evaluation/' . $it['id'],
};

$isDone = LessonCompletion::isComplete($lessonId, $studentId);
$xp     = (int) $lesson['xp_value'];

// E35 (F26): expande [[widget:ID]] no render, igual ao conteudo da CU.
$html = expand_widgets((string) $lesson['html']);

// O picker de imagem do TinyMCE grava a URL do PROFESSOR
// (/teacher/cu/{id}/attachment/{aid}/view), que exige papel de professor —
// pro aluno isso e 403 e a imagem quebra. A pagina da CU ja fazia essa
// reescrita; a da licao nao fazia, e toda imagem em licao quebrava.
//
// Seguro mesmo quando o HTML foi colado de outra CU: a rota do aluno valida
// por ContentAttachment::findForStudent, que confere matricula, nao o id da
// CU que aparece na URL.
if ($html !== '') {
    $html = str_replace('"/teacher/cu/', '"/student/cu/', $html);
}

$page_title = (string) $lesson['title'];

ob_start();
?>
<?= breadcrumbs([
    ['label' => __t('dashboard.student.title'), 'url' => '/student'],
    ['label' => (string) $lesson['course_name'], 'url' => '/student/course/' . $courseId],
    ['label' => (string) $lesson['cu_name'],     'url' => '/student/cu/' . $cuId],
    ['label' => (string) $lesson['title']],
]) ?>

<div class="row g-3">
    <!-- Timeline: lateral no desktop, acima do conteudo no mobile -->
    <div class="col-12 col-lg-4 order-lg-2">
        <div class="card shadow-sm">
            <div class="card-header">
                <h2 class="h6 mb-0"><?= e((string) $lesson['cu_name']) ?></h2>
            </div>
            <div class="card-body p-2">
                <?php require LMS_ROOT . '/src/templates/partials/track_timeline.php'; ?>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-8 order-lg-1">
        <div class="card shadow-sm mb-3">
            <div class="card-body">
                <h1 class="h4 mb-3"><?= e((string) $lesson['title']) ?></h1>
                <div class="unit-prose content-render">
                    <?= $html /* sanitizado por ContentSanitizer na gravacao */ ?>
                </div>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-body d-flex align-items-center gap-2 flex-wrap">
                <?php if ($prev !== null): ?>
                    <a href="<?= e($hrefFor($prev)) ?>" class="btn btn-outline-secondary">
                        &larr; <?= e(__t('track.nav.previous')) ?>
                    </a>
                <?php endif; ?>

                <div class="ms-auto d-flex align-items-center gap-2 flex-wrap">
                    <?php if ($isDone): ?>
                        <span class="badge text-bg-success-subtle text-success-emphasis">
                            <?= e(__t('track.lesson.done')) ?>
                        </span>
                        <form method="POST" action="/student/lesson/<?= $lessonId ?>/complete" class="d-inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="uncomplete">
                            <button type="submit" class="btn btn-link btn-sm text-muted">
                                <?= e(__t('track.lesson.undo')) ?>
                            </button>
                        </form>
                        <?php if ($next !== null): ?>
                            <a href="<?= e($hrefFor($next)) ?>" class="btn btn-primary">
                                <?= e(__t('track.nav.next')) ?> &rarr;
                            </a>
                        <?php endif; ?>
                    <?php else: ?>
                        <form method="POST" action="/student/lesson/<?= $lessonId ?>/complete" class="d-inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="complete">
                            <button type="submit" class="btn btn-primary">
                                <?= e($next !== null
                                    ? __t('track.lesson.complete_and_continue')
                                    : __t('track.lesson.complete')) ?>
                                <?php if ($xp > 0): ?>
                                    <span class="badge text-bg-light text-dark ms-1">
                                        +<?= (int) $xp ?> XP
                                    </span>
                                <?php endif; ?>
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
<?php
$page_content = ob_get_clean();
require LMS_ROOT . '/src/templates/layout.php';
