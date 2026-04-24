<?php
declare(strict_types=1);

/**
 * /student/activity/{id} — aluno vê instrução da atividade e sua
 * submissão atual (E6-03). POST do mesmo caminho grava/atualiza a
 * entrega.
 *
 * Regras:
 *  - Exige matrícula ativa (validada por `ActivitySubmission::findForStudentActivity`)
 *  - `activity.submission_open = 0` bloqueia novas submissões
 *  - ADR-027: enquanto `feedback_at IS NULL`, aluno pode editar/remover
 *  - ADR-002: ao gravar a 1ª submissão, credita XP via `XpEvents::awardActivity`
 */

$user = current_user();
if ($user === null || ($user['role'] ?? '') !== 'student') {
    http_response_code(403);
    require LMS_ROOT . '/src/templates/errors/403.php';
    return;
}

$studentId  = (int) $user['id'];
$tenantId   = (int) ($user['tenant_id'] ?? 0);
$activityId = (int) ($_REQUEST['id'] ?? 0);

$ctx = ActivitySubmission::findForStudentActivity($activityId, $studentId);
if ($ctx === null) {
    http_response_code(404);
    require LMS_ROOT . '/src/templates/errors/404.php';
    return;
}

$activity   = $ctx['activity'];
$submission = $ctx['submission'];
$mutable    = ActivitySubmission::isMutable($submission);
$isOpen     = (int) $activity['submission_open'] === 1;
$isCode     = $activity['type'] === 'codigo';
$codeLang   = $activity['code_language'] ?? null;
$useEditor  = $isCode && (int) ($activity['allow_online_code_run'] ?? 0) === 1 && $codeLang !== null;
$errors     = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_verify();
    } catch (RuntimeException) {
        flash('danger', __t('auth.forbidden'));
        header('Location: /student/activity/' . $activityId);
        return;
    }

    if (!$mutable) {
        flash('danger', __t('submissions.err.after_feedback'));
        header('Location: /student/activity/' . $activityId);
        return;
    }
    if (!$isOpen && $submission === null) {
        flash('danger', __t('submissions.err.closed'));
        header('Location: /student/activity/' . $activityId);
        return;
    }

    $codeText  = $isCode ? trim((string) ($_POST['code_text'] ?? '')) : null;
    $hasFile   = isset($_FILES['file']) && is_array($_FILES['file'])
              && (int) ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;
    $filename  = $submission['filename']    ?? null;
    $storedPath = $submission['stored_path'] ?? null;

    if ($hasFile) {
        $result = SubmissionStorage::store($_FILES['file'], $activityId, $studentId, $tenantId);
        if ($result['status'] !== 'ok') {
            $errors['file'] = $result['error_key'] ?? 'submissions.err.generic';
        } else {
            $filename   = $result['filename'];
            $storedPath = $result['stored_path'];
        }
    }

    // Exige ao menos arquivo ou código (pra codigo). Pra outros tipos, arquivo obrigatório.
    $hasAny = $filename !== null || ($isCode && $codeText !== null && $codeText !== '');
    if ($errors === [] && !$hasAny && $submission === null) {
        $errors['file'] = 'submissions.err.empty';
    }

    if ($errors === []) {
        $action = ActivitySubmission::upsert(
            $activityId, $studentId,
            $filename,
            $storedPath,
            ($isCode && $codeText !== '') ? $codeText : null
        );
        if ($action === 'created') {
            XpEvents::awardActivity($studentId, $activityId);
        }
        flash('success', __t($action === 'created' ? 'submissions.submitted' : 'submissions.updated'));
        header('Location: /student/activity/' . $activityId, true, 303);
        return;
    }
}

$page_title = (string) $activity['title'];

ob_start();
?>
<?= breadcrumbs([
    ['label' => __t('dashboard.student.title'), 'url' => '/student'],
    ['label' => (string) $activity['course_name'], 'url' => '/student/course/' . (int) $activity['course_id']],
    ['label' => (string) $activity['cu_name'],     'url' => '/student/cu/' . (int) $activity['cu_id']],
    ['label' => (string) $activity['title']],
]) ?>

<div class="row justify-content-center">
    <div class="col-12 col-lg-10">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
            <div>
                <h1 class="h4 mb-1"><?= e((string) $activity['title']) ?></h1>
                <small class="text-muted">
                    <?= e(__t('activities.type.' . $activity['type'])) ?> ·
                    <?= (int) $activity['xp_value'] ?> XP
                </small>
            </div>
            <?php if ($submission !== null): ?>
                <?php if ($submission['feedback_at'] !== null): ?>
                    <span class="badge text-bg-success"><?= e(__t('submissions.status.with_feedback')) ?></span>
                <?php else: ?>
                    <span class="badge text-bg-warning"><?= e(__t('submissions.status.submitted')) ?></span>
                <?php endif; ?>
            <?php else: ?>
                <span class="badge text-bg-secondary"><?= e(__t('submissions.status.not_submitted')) ?></span>
            <?php endif; ?>
        </div>

        <!-- Instrução -->
        <div class="card shadow-sm mb-3">
            <div class="card-body content-render">
                <?= (string) $activity['instruction'] ?>
            </div>
        </div>

        <!-- Feedback do professor, quando já entregue -->
        <?php if ($submission !== null && $submission['feedback_at'] !== null): ?>
            <div class="card shadow-sm mb-3 border-success">
                <div class="card-header bg-success-subtle">
                    <h2 class="h6 mb-0"><?= e(__t('submissions.feedback_title')) ?></h2>
                </div>
                <div class="card-body">
                    <p class="mb-0" style="white-space: pre-wrap;"><?= e((string) $submission['feedback']) ?></p>
                    <small class="text-muted d-block mt-2">
                        <?= e(__t('submissions.feedback_at', ['date' => substr((string) $submission['feedback_at'], 0, 16)])) ?>
                    </small>
                </div>
            </div>
        <?php endif; ?>

        <!-- Form de submissão -->
        <div class="card shadow-sm">
            <div class="card-header">
                <h2 class="h6 mb-0"><?= e(__t('submissions.section.title')) ?></h2>
            </div>
            <div class="card-body">
                <?php if (!$isOpen && $submission === null): ?>
                    <div class="alert alert-warning mb-0" role="alert">
                        <?= e(__t('submissions.closed_notice')) ?>
                    </div>
                <?php elseif (!$mutable): ?>
                    <p class="small text-muted mb-3">
                        <?= e(__t('submissions.readonly_notice')) ?>
                    </p>
                    <dl class="row mb-0 small">
                        <?php if ($submission['filename'] !== null): ?>
                            <dt class="col-4 col-md-3"><?= e(__t('submissions.form.file')) ?></dt>
                            <dd class="col-8 col-md-9">
                                <a href="/student/activity/<?= $activityId ?>/file">
                                    <?= e((string) $submission['filename']) ?>
                                </a>
                            </dd>
                        <?php endif; ?>
                        <?php if ($submission['code_text'] !== null): ?>
                            <dt class="col-4 col-md-3"><?= e(__t('submissions.form.code')) ?></dt>
                            <dd class="col-8 col-md-9"><pre class="mb-0"><?= e((string) $submission['code_text']) ?></pre></dd>
                        <?php endif; ?>
                    </dl>
                <?php else: ?>
                    <?php if ($errors !== []): ?>
                        <div class="alert alert-danger" role="alert">
                            <?php foreach ($errors as $key): ?>
                                <div><?= e(__t($key)) ?></div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="/student/activity/<?= $activityId ?>"
                          enctype="multipart/form-data" class="mb-0" novalidate>
                        <?= csrf_field() ?>

                        <div class="mb-3">
                            <label for="f-file" class="form-label">
                                <?= e(__t('submissions.form.file')) ?>
                                <?php if ($submission !== null && $submission['filename'] !== null): ?>
                                    <small class="text-muted">(<?= e(__t('submissions.form.current_file')) ?>: <?= e((string) $submission['filename']) ?>)</small>
                                <?php endif; ?>
                            </label>
                            <input type="file" name="file" id="f-file"
                                   class="form-control<?= isset($errors['file']) ? ' is-invalid' : '' ?>"
                                   accept=".pdf,.zip,.txt">
                            <div class="form-text"><?= e(__t('submissions.form.file_hint')) ?></div>
                        </div>

                        <?php if ($isCode): ?>
                            <div class="mb-3">
                                <label for="f-code" class="form-label">
                                    <?= e(__t('submissions.form.code')) ?>
                                    <?php if ($useEditor): ?>
                                        <span class="badge text-bg-primary ms-1"><?= e(__t('activities.code_language.' . $codeLang)) ?></span>
                                    <?php endif; ?>
                                </label>
                                <?php if ($useEditor): ?>
                                    <div id="f-code-editor" class="lms-code-editor" data-code-language="<?= e((string) $codeLang) ?>"></div>
                                <?php endif; ?>
                                <textarea id="f-code" name="code_text" rows="10"
                                          class="form-control font-monospace<?= $useEditor ? ' d-none' : '' ?>"
                                          placeholder="<?= e(__t('submissions.form.code_placeholder')) ?>"><?= e((string) ($submission['code_text'] ?? '')) ?></textarea>
                                <div class="form-text"><?= e(__t('submissions.form.code_hint')) ?></div>
                            </div>
                        <?php endif; ?>

                        <div class="d-flex gap-2 flex-wrap">
                            <button type="submit" class="btn btn-primary btn-lg">
                                <?= e(__t($submission === null ? 'submissions.form.submit' : 'submissions.form.update')) ?>
                            </button>
                            <?php if ($submission !== null): ?>
                                <form method="POST" action="/student/activity/<?= $activityId ?>/delete"
                                      class="d-inline m-0"
                                      onsubmit="return confirm(<?= e(json_encode(__t('submissions.delete_confirm'), JSON_UNESCAPED_UNICODE)) ?>);">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="btn btn-outline-danger btn-lg">
                                        <?= e(__t('submissions.form.delete')) ?>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php if ($useEditor): ?>
<script type="module">
// CodeMirror 6 via esm.sh — carrega extensão da linguagem dinamicamente.
// Editor sincroniza conteúdo com o textarea `#f-code` ao submeter o form
// (textarea é quem vai no POST como `code_text`).
import { EditorView, basicSetup } from "https://esm.sh/codemirror@6.0.1";

const container = document.getElementById('f-code-editor');
const textarea  = document.getElementById('f-code');
if (container && textarea) {
    const lang = container.dataset.codeLanguage;
    let langExt;
    if (lang === 'python') {
        const m = await import("https://esm.sh/@codemirror/lang-python@6.1.6");
        langExt = m.python();
    } else if (lang === 'javascript') {
        const m = await import("https://esm.sh/@codemirror/lang-javascript@6.2.2");
        langExt = m.javascript();
    } else if (lang === 'html') {
        const m = await import("https://esm.sh/@codemirror/lang-html@6.4.9");
        langExt = m.html();
    }
    // C# fica sem highlight (CM6 não tem pacote oficial); texto plain roda.

    const view = new EditorView({
        doc: textarea.value,
        parent: container,
        extensions: [basicSetup, langExt].filter(Boolean),
    });

    const form = textarea.closest('form');
    if (form) {
        form.addEventListener('submit', () => {
            textarea.value = view.state.doc.toString();
        });
    }
}
</script>
<?php endif; ?>
<?php
$page_content = ob_get_clean();
require LMS_ROOT . '/src/templates/layout.php';
