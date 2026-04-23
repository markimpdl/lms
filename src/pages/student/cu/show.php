<?php
declare(strict_types=1);

/**
 * /student/cu/{id} — aluno lê o conteúdo da CU (E5-05).
 *
 * Regras:
 *  - Exige matrícula ativa no curso que contém a CU (via
 *    CompetenceUnit::findForStudent). 404 amigável caso contrário.
 *  - Filtra `contents.published = 1`. Conteúdo despublicado (ou
 *    inexistente) mostra placeholder "ainda não disponível" em vez de
 *    erro — permite que o aluno chegue na CU mesmo antes do professor
 *    publicar (útil pro E6 quando atividades estiverem prontas).
 *  - Anexos aparecem como links autenticados para /student/cu/{id}/
 *    attachment/{aid} (download forçado).
 */

$user = current_user();
if ($user === null || ($user['role'] ?? '') !== 'student') {
    http_response_code(403);
    require LMS_ROOT . '/src/templates/errors/403.php';
    return;
}

$studentId = (int) $user['id'];
$cuId      = (int) ($_REQUEST['id'] ?? 0);

$cu = CompetenceUnit::findForStudent($cuId, $studentId);
if ($cu === null) {
    http_response_code(404);
    require LMS_ROOT . '/src/templates/errors/404.php';
    return;
}

// Conteúdo: só se existir E publicado. Para o aluno, tenantId não se
// aplica ao JOIN — já validamos matrícula acima; buscamos direto pela CU.
$stmt = Database::pdo()->prepare(
    'SELECT html, published FROM contents WHERE competence_unit_id = ? LIMIT 1'
);
$stmt->execute([$cuId]);
$content = $stmt->fetch();

$hasPublishedContent = $content !== false && (int) $content['published'] === 1;
$html                = $hasPublishedContent ? (string) $content['html'] : '';

// Anexos (imagens inline, iframes) foram gravados pelo professor com URLs
// apontando pra `/teacher/cu/{id}/attachment/.../view` — rotas só do
// teacher, role=teacher. Pro aluno, reescrevemos o prefixo. O authorization
// real continua no handler da rota (`ContentAttachment::findForStudent`
// valida matrícula).
if ($html !== '') {
    $html = str_replace('"/teacher/cu/', '"/student/cu/', $html);
}

// Atividades da CU com status da entrega do aluno (E6-06).
$activities = ActivitySubmission::listForStudentInCu($cuId, $studentId);

// Anexos: só se o aluno tem matrícula (já validado). Listamos via o
// contentId pra evitar nova validação N vezes. Se não há content, não há
// anexos mesmo assim.
$attachments = [];
if ($content !== false) {
    $stmt = Database::pdo()->prepare(
        'SELECT a.id, a.filename, a.mime, a.size_bytes
           FROM content_attachments a
          WHERE a.content_id = (SELECT id FROM contents WHERE competence_unit_id = ?)
          ORDER BY a.created_at DESC, a.id DESC'
    );
    $stmt->execute([$cuId]);
    $attachments = $stmt->fetchAll();
}

$page_title = (string) $cu['name'];

ob_start();
?>
<?= breadcrumbs([
    ['label' => __t('dashboard.student.title'), 'url' => '/student'],
    ['label' => (string) $cu['course_name'],    'url' => '/student/course/' . (int) $cu['course_id']],
    ['label' => (string) $cu['cc_name']],
    ['label' => (string) $cu['name']],
]) ?>

<div class="row justify-content-center">
    <div class="col-12 col-lg-10">
        <h1 class="h4 mb-3"><?= e((string) $cu['name']) ?></h1>

        <?php if ($hasPublishedContent): ?>
            <article class="card shadow-sm mb-3">
                <div class="card-body content-render">
                    <?= $html ?>
                </div>
            </article>
        <?php else: ?>
            <div class="card card-body shadow-sm text-center text-muted py-5 mb-3">
                <p class="lead mb-0"><?= e(__t('student.content.not_available')) ?></p>
                <p class="small mb-0"><?= e(__t('student.content.not_available_hint')) ?></p>
            </div>
        <?php endif; ?>

        <?php if ($activities !== []): ?>
            <section class="mb-3">
                <h2 class="h6 mb-2"><?= e(__t('student.activities.section_title')) ?></h2>
                <div class="d-flex flex-column gap-2">
                    <?php foreach ($activities as $act): ?>
                        <?php
                            $aid      = (int) $act['id'];
                            $open     = (int) $act['submission_open'] === 1;
                            $hasSub   = $act['submission_id'] !== null;
                            $hasFb    = $hasSub && $act['feedback_at'] !== null;
                            if ($hasFb) {
                                $status = 'completed';
                                $percent = 100;
                            } elseif ($hasSub) {
                                $status = 'in_progress';
                                $percent = 50;
                            } else {
                                $status = 'not_started';
                                $percent = 0;
                            }
                            $statusKey = 'student.activities.status.' . ($hasFb ? 'with_feedback' : ($hasSub ? 'submitted' : ($open ? 'not_submitted' : 'closed')));
                        ?>
                        <a href="/student/activity/<?= $aid ?>"
                           class="lms-card lms-card--<?= e(str_replace('_', '-', $status)) ?>">
                            <div class="lms-card__body">
                                <div class="lms-card__title"><?= e((string) $act['title']) ?></div>
                                <div class="lms-card__meta">
                                    <?= e(__t('activities.type.' . $act['type'])) ?> ·
                                    <?= (int) $act['xp_value'] ?> XP
                                </div>
                            </div>
                            <span class="badge text-bg-<?= $hasFb ? 'success' : ($hasSub ? 'warning' : ($open ? 'secondary' : 'dark')) ?>">
                                <?= e(__t($statusKey)) ?>
                            </span>
                            <div class="lms-progress-ring lms-progress-ring--<?= e(str_replace('_', '-', $status)) ?>"
                                 style="--pct: <?= $percent ?>;"
                                 role="progressbar"
                                 aria-label="<?= e(__t('student.course.progress_aria', ['percent' => (string) $percent])) ?>"
                                 aria-valuenow="<?= $percent ?>" aria-valuemin="0" aria-valuemax="100">
                                <span class="lms-progress-ring__label"><?= $percent ?>%</span>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($attachments !== []): ?>
            <div class="card shadow-sm">
                <div class="card-header">
                    <h2 class="h6 mb-0"><?= e(__t('student.content.attachments_title')) ?></h2>
                </div>
                <ul class="list-group list-group-flush">
                    <?php foreach ($attachments as $att): ?>
                        <?php $aid = (int) $att['id']; ?>
                        <li class="list-group-item d-flex align-items-center gap-2 flex-wrap">
                            <div class="flex-grow-1">
                                <a href="/student/cu/<?= $cuId ?>/attachment/<?= $aid ?>"
                                   class="fw-semibold text-decoration-none">
                                    <?= e((string) $att['filename']) ?>
                                </a>
                                <small class="text-muted ms-2">
                                    <?= e((string) $att['mime']) ?> ·
                                    <?= e(number_format((int) $att['size_bytes'] / 1024, 1, ',', '.')) ?> KB
                                </small>
                            </div>
                            <a href="/student/cu/<?= $cuId ?>/attachment/<?= $aid ?>"
                               class="btn btn-sm btn-outline-primary">
                                <?= e(__t('student.content.download')) ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
    </div>
</div>

<style>
.content-render img          { max-width: 100%; height: auto; }
.content-render table        { width: 100%; }
.content-render iframe       { width: 100%; aspect-ratio: 16 / 9; border: 0; }
.content-render pre          { background: #f6f8fa; padding: .75rem; border-radius: .25rem; overflow-x: auto; }
.content-render blockquote   { border-left: 3px solid #dee2e6; padding-left: 1rem; color: #6c757d; }
</style>
<?php
$page_content = ob_get_clean();
require LMS_ROOT . '/src/templates/layout.php';
