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

// Gate de disponibilidade do curso (E17-03).
$availability = enrollment_access_status($studentId, (int) $activity['course_id']);
if (!$availability['available']) {
    flash('warning', $availability['message'] ?? __t('enrollment.unavailable.generic'));
    header('Location: /student', true, 303);
    exit;
}

// E19-04: gate server-side anti-bypass via URL direta. Verifica
// (a) acesso à CU dada o cc_mode, e (b) acesso à atividade dada o
// activity_mode. Skip ambos quando o respectivo modo é 'free'.
$courseId = (int) $activity['course_id'];
$cuId     = (int) $activity['cu_id'];
$progGateStmt = Database::pdo()->prepare(
    'SELECT cc_mode, activity_mode FROM courses WHERE id = ? LIMIT 1'
);
$progGateStmt->execute([$courseId]);
$progConf = $progGateStmt->fetch();
$ccMode       = (string) ($progConf['cc_mode'] ?? 'sequential');
$activityMode = (string) ($progConf['activity_mode'] ?? 'sequential');

if ($ccMode === 'sequential') {
    $courseFull = StudentCurriculum::forStudentCourse($studentId, $courseId);
    if ($courseFull !== null) {
        $progGate   = course_progression_state($courseFull, $studentId);
        $cuStatus   = $progGate['cu_status'][$cuId] ?? 'free';
        if ($cuStatus === 'hidden' || $cuStatus === 'next') {
            flash('warning', __t('progression.cu_locked'));
            header('Location: /student/course/' . $courseId, true, 303);
            exit;
        }
    }
}

if ($activityMode === 'sequential') {
    // Walk atividades da CU em ordem; identifica status desta atividade.
    $listForGate = ActivitySubmission::listForStudentInCu($cuId, $studentId);
    $foundCurrentAct = false;
    $markNextAct     = false;
    $myActStatus     = 'free';
    foreach ($listForGate as $a) {
        $aId    = (int) $a['id'];
        $hasSub = $a['submission_id'] !== null;

        if ($markNextAct) {
            if ($aId === $activityId) {
                $myActStatus = 'next';
                break;
            }
            $markNextAct = false;
            continue;
        }
        if ($foundCurrentAct) {
            if ($aId === $activityId) {
                $myActStatus = 'hidden';
                break;
            }
            continue;
        }
        if ($hasSub) {
            if ($aId === $activityId) {
                $myActStatus = 'completed';
                break;
            }
        } else {
            if ($aId === $activityId) {
                $myActStatus = 'current';
                break;
            }
            $foundCurrentAct = true;
            $markNextAct     = true;
        }
    }
    if ($myActStatus === 'hidden' || $myActStatus === 'next') {
        flash('warning', __t('progression.activity_locked'));
        header('Location: /student/cu/' . $cuId, true, 303);
        exit;
    }
}

// E20-03: atividade tipo Quiz tem fluxo próprio (sem upload + grade
// automática informativa + XP por entregar). Delega pro handler.
if ((string) ($activity['type'] ?? '') === 'quiz') {
    require LMS_ROOT . '/src/pages/student/activity/quiz_handler.php';
    return;
}

$submission = $ctx['submission'];
$mutable    = ActivitySubmission::isMutable($submission);
$isOpen     = (int) $activity['submission_open'] === 1;
$isCode     = $activity['type'] === 'codigo';
$codeLang   = $activity['code_language'] ?? null;
$useEditor  = $isCode && (int) ($activity['allow_online_code_run'] ?? 0) === 1 && $codeLang !== null;
$errors     = [];

// Aviso sobre stdin: Judge0 roda sem entrada interativa, então input()/
// Console.ReadLine()/prompt() travam. HTML usa iframe (sem stdin) — não
// se aplica. Mostra só quando o "Executar" está disponível.
$stdinFnByLang = [
    'python'     => 'input()',
    'csharp'     => 'Console.ReadLine()',
    'javascript' => 'prompt()',
];
$stdinFn = $useEditor ? ($stdinFnByLang[$codeLang] ?? null) : null;

// Allowlist de extensões pro input file. Atividade tipo código com
// linguagem definida estende com a extensão da linguagem (.py/.cs/.js/.html);
// .zip e .txt continuam universais (zip pra projetos com vários arquivos).
$codeLangExt = [
    'python'     => 'py',
    'csharp'     => 'cs',
    'javascript' => 'js',
    'html'       => 'html',
];
$acceptExts = ['.pdf', '.zip', '.txt'];
if ($isCode && $codeLang !== null && isset($codeLangExt[$codeLang])) {
    array_unshift($acceptExts, '.' . $codeLangExt[$codeLang]);
    $acceptExts = array_values(array_unique($acceptExts));
}
$acceptAttr = implode(',', $acceptExts);
$acceptHint = strtoupper(implode(', ', array_map(static fn ($x) => ltrim($x, '.'), $acceptExts)));

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
        $result = SubmissionStorage::store(
            $_FILES['file'], $activityId, $studentId, $tenantId,
            ['code_language' => $codeLang]
        );
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

            // Conquistas (E18-04). Best-effort — falha aqui não derruba a entrega.
            try {
                AchievementsService::evaluateForEvent($studentId, $tenantId, 'activity_submitted');
                AchievementsService::evaluateForEvent($studentId, $tenantId, 'rank_first_promotion');
                student_progression_check($studentId, $tenantId, (int) $activity['cu_id']);
            } catch (\Throwable) {
                // swallow
            }
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

<div class="row">
    <div class="col-12">
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

        <!-- Brief PDF/ZIP do professor (v0.30.0) — só pra atividade tipo projeto. -->
        <?php if (($activity['pdf_path'] ?? null) !== null): ?>
            <div class="card shadow-sm mb-3">
                <div class="card-body d-flex align-items-center gap-3 flex-wrap">
                    <div class="flex-grow-1">
                        <strong><?= e(__t('activities.student.brief_title')) ?></strong>
                        <div class="small text-muted"><?= e(__t('activities.student.brief_hint')) ?></div>
                    </div>
                    <a href="/student/activity/<?= $activityId ?>/brief"
                       class="btn btn-outline-primary">
                        <?= e(__t('activities.student.brief_download')) ?>
                    </a>
                </div>
            </div>
        <?php endif; ?>

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
                                   accept="<?= e($acceptAttr) ?>">
                            <div class="form-text"><?= e(__t('submissions.form.file_hint_dynamic', ['exts' => $acceptHint])) ?></div>
                        </div>

                        <?php if ($isCode): ?>
                            <div class="mb-3">
                                <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
                                    <label for="f-code" class="form-label mb-0">
                                        <?= e(__t('submissions.form.code')) ?>
                                        <?php if ($useEditor): ?>
                                            <span class="badge text-bg-primary ms-1"><?= e(__t('activities.code_language.' . $codeLang)) ?></span>
                                        <?php endif; ?>
                                    </label>
                                    <?php if ($useEditor): ?>
                                        <button type="button" id="btn-run" class="btn btn-sm btn-outline-primary">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                                <polygon points="5 3 19 12 5 21 5 3"></polygon>
                                            </svg>
                                            <?= e(__t('submissions.form.run')) ?>
                                        </button>
                                    <?php endif; ?>
                                </div>
                                <?php if ($stdinFn !== null): ?>
                                    <div class="alert alert-info small py-2 px-3 mb-2" role="alert">
                                        <?= __t('submissions.run.no_stdin_hint', ['fn' => '<code>' . e($stdinFn) . '</code>']) ?>
                                    </div>
                                <?php endif; ?>
                                <?php if ($useEditor): ?>
                                    <div id="f-code-editor" class="lms-code-editor" data-code-language="<?= e((string) $codeLang) ?>"></div>
                                <?php endif; ?>
                                <textarea id="f-code" name="code_text" rows="10"
                                          class="form-control font-monospace<?= $useEditor ? ' d-none' : '' ?>"
                                          placeholder="<?= e(__t('submissions.form.code_placeholder')) ?>"><?= e((string) ($submission['code_text'] ?? '')) ?></textarea>
                                <div class="form-text"><?= e(__t('submissions.form.code_hint')) ?></div>

                                <?php if ($useEditor): ?>
                                    <div id="run-result-panel" class="lms-run-panel" hidden>
                                        <div class="lms-run-panel__header">
                                            <h3 class="lms-run-panel__title"><?= e(__t('submissions.run.result_title')) ?></h3>
                                            <button type="button" id="btn-clear" class="lms-run-panel__close" aria-label="<?= e(__t('submissions.run.clear')) ?>">
                                                <?= e(__t('submissions.run.clear')) ?>
                                            </button>
                                        </div>

                                        <!-- Loading state -->
                                        <div id="run-loading" class="lms-run-panel__loading" hidden>
                                            <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
                                            <span><?= e(__t('submissions.run.loading')) ?></span>
                                        </div>

                                        <!-- HTML sandbox (E8-03) -->
                                        <iframe id="run-iframe"
                                                class="lms-run-panel__frame"
                                                sandbox="allow-scripts"
                                                title="<?= e(__t('submissions.run.sandbox_title')) ?>"
                                                hidden></iframe>

                                        <!-- Judge0 tabs (E8-02) -->
                                        <div id="run-tabs" class="lms-run-panel__tabs-wrap" hidden>
                                            <div class="lms-run-panel__tabs" role="tablist">
                                                <button type="button" data-tab="stdout" class="lms-run-panel__tab is-active" role="tab"><?= e(__t('submissions.run.tab.stdout')) ?></button>
                                                <button type="button" data-tab="stderr" class="lms-run-panel__tab" role="tab"><?= e(__t('submissions.run.tab.stderr')) ?></button>
                                                <button type="button" data-tab="info"   class="lms-run-panel__tab" role="tab"><?= e(__t('submissions.run.tab.info')) ?></button>
                                            </div>
                                            <pre id="run-pane-stdout" class="lms-run-panel__pane" data-pane="stdout"></pre>
                                            <pre id="run-pane-stderr" class="lms-run-panel__pane" data-pane="stderr" hidden></pre>
                                            <div id="run-pane-info" class="lms-run-panel__pane lms-run-panel__pane--info" data-pane="info" hidden>
                                                <dl class="mb-0">
                                                    <dt><?= e(__t('submissions.run.info.time')) ?></dt>
                                                    <dd id="run-info-time">—</dd>
                                                    <dt><?= e(__t('submissions.run.info.status')) ?></dt>
                                                    <dd id="run-info-status">—</dd>
                                                </dl>
                                            </div>
                                        </div>

                                        <!-- Error state -->
                                        <div id="run-error" class="lms-run-panel__error" hidden>
                                            <p id="run-error-msg" class="mb-0"></p>
                                        </div>
                                    </div>
                                <?php endif; ?>
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
    } else if (lang === 'csharp') {
        const m = await import("https://esm.sh/@codemirror/lang-csharp@6.1.1");
        langExt = m.csharp();
    }

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

    // Painel "Executar" (E8-02) — estados: loading / iframe (HTML) /
    // tabs (Judge0 stdout/stderr/info) / error. Também Ctrl+Enter /
    // Cmd+Enter como atalho pra executar.
    const btnRun    = document.getElementById('btn-run');
    const btnClear  = document.getElementById('btn-clear');
    const panel     = document.getElementById('run-result-panel');
    const iframe    = document.getElementById('run-iframe');
    const loading   = document.getElementById('run-loading');
    const tabsWrap  = document.getElementById('run-tabs');
    const errBlock  = document.getElementById('run-error');
    const errMsg    = document.getElementById('run-error-msg');
    const paneOut   = document.getElementById('run-pane-stdout');
    const paneErr   = document.getElementById('run-pane-stderr');
    const paneInfo  = document.getElementById('run-pane-info');
    const infoTime  = document.getElementById('run-info-time');
    const infoStat  = document.getElementById('run-info-status');

    const errorMessages = <?= json_encode([
        'code_run.err.method'       => __t('code_run.err.method'),
        'code_run.err.forbidden'    => __t('code_run.err.forbidden'),
        'code_run.err.csrf'         => __t('code_run.err.csrf'),
        'code_run.err.bad_request'  => __t('code_run.err.bad_request'),
        'code_run.err.not_found'    => __t('code_run.err.not_found'),
        'code_run.err.not_code'     => __t('code_run.err.not_code'),
        'code_run.err.disabled'     => __t('code_run.err.disabled'),
        'code_run.err.bad_language' => __t('code_run.err.bad_language'),
        'code_run.err.too_large'    => __t('code_run.err.too_large'),
        'code_run.err.unavailable'  => __t('code_run.err.unavailable'),
        'code_run.err.quota'        => __t('code_run.err.quota'),
        'code_run.err.timeout'      => __t('code_run.err.timeout'),
        'code_run.err.remote'       => __t('code_run.err.remote'),
        'network'                   => __t('code_run.err.remote'),
    ], JSON_UNESCAPED_UNICODE) ?>;

    const statusLabels = <?= json_encode([
        'accepted'    => __t('submissions.run.status.accepted'),
        'time_limit'  => __t('submissions.run.status.time_limit'),
        'compile'     => __t('submissions.run.status.compile_error'),
        'runtime'     => __t('submissions.run.status.runtime_error'),
        'unknown'     => __t('submissions.run.status.unknown'),
    ], JSON_UNESCAPED_UNICODE) ?>;

    const csrfToken  = <?= json_encode(csrf_token()) ?>;
    const activityId = <?= (int) $activityId ?>;
    const emptyHint  = <?= json_encode(__t('submissions.run.empty_output')) ?>;

    function hideAllStates() {
        loading.hidden  = true;
        iframe.hidden   = true;
        iframe.srcdoc   = '';
        tabsWrap.hidden = true;
        errBlock.hidden = true;
    }
    function clearPanel() {
        panel.hidden = true;
        hideAllStates();
    }
    function showLoading() {
        panel.hidden    = false;
        hideAllStates();
        loading.hidden  = false;
    }
    function showIframe(code) {
        panel.hidden    = false;
        hideAllStates();
        iframe.hidden   = false;
        iframe.srcdoc   = code;
    }
    function showError(key) {
        panel.hidden    = false;
        hideAllStates();
        errMsg.textContent = errorMessages[key] || errorMessages['network'];
        errBlock.hidden = false;
    }
    function statusIdToLabel(id) {
        if (id === 3) return statusLabels.accepted;
        if (id === 5) return statusLabels.time_limit;
        if (id === 6) return statusLabels.compile;
        if (id >= 7 && id <= 12) return statusLabels.runtime;
        return statusLabels.unknown;
    }
    function showTabs(data) {
        panel.hidden    = false;
        hideAllStates();
        paneOut.textContent  = data.stdout && data.stdout !== '' ? data.stdout : emptyHint;
        paneErr.textContent  = data.stderr && data.stderr !== '' ? data.stderr : emptyHint;
        infoTime.textContent = data.time !== null && data.time !== undefined ? (data.time + ' s') : '—';
        infoStat.textContent = statusIdToLabel(data.status_id);
        activateTab('stdout');
        tabsWrap.hidden = false;
    }
    function activateTab(name) {
        tabsWrap.querySelectorAll('.lms-run-panel__tab').forEach((b) => {
            b.classList.toggle('is-active', b.dataset.tab === name);
        });
        tabsWrap.querySelectorAll('.lms-run-panel__pane').forEach((p) => {
            p.hidden = p.dataset.pane !== name;
        });
    }

    async function runCode() {
        if (btnRun.disabled) return;  // evita double-click durante loading
        const code = view.state.doc.toString();
        if (lang === 'html') {
            showIframe(code);
            panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            return;
        }

        btnRun.disabled = true;
        showLoading();
        panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });

        try {
            const body = new URLSearchParams();
            body.set('_csrf', csrfToken);
            body.set('activity_id', String(activityId));
            body.set('code', code);

            const resp = await fetch('/api/code/run', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString(),
            });

            let data;
            try { data = await resp.json(); } catch (_) { data = null; }

            if (data && data.ok === true) {
                showTabs(data);
            } else {
                showError((data && data.error_key) ? data.error_key : 'network');
            }
        } catch (_) {
            showError('network');
        } finally {
            btnRun.disabled = false;
        }
    }

    if (btnRun)   btnRun.addEventListener('click', runCode);
    if (btnClear) btnClear.addEventListener('click', clearPanel);

    tabsWrap.querySelectorAll('.lms-run-panel__tab').forEach((b) => {
        b.addEventListener('click', () => activateTab(b.dataset.tab));
    });

    // Ctrl+Enter / Cmd+Enter como atalho dentro do editor.
    container.addEventListener('keydown', (ev) => {
        if ((ev.ctrlKey || ev.metaKey) && ev.key === 'Enter') {
            ev.preventDefault();
            runCode();
        }
    });
}
</script>
<?php endif; ?>
<?php
$page_content = ob_get_clean();
require LMS_ROOT . '/src/templates/layout.php';
