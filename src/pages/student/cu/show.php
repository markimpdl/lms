<?php
declare(strict_types=1);

/**
 * /student/cu/{id} — Unit page do aluno (E14-04, rewrite).
 *
 * Breadcrumb + unit header card (eyebrow + título + meta + ring) + section
 * tabs (âncoras) + 4 seções:
 *   1. Content — .unit-prose com HTML sanitizado (E5-01) + placeholder
 *   2. Activities — lista vertical de ActivityCards (6 estados)
 *   3. Assessment — 1 ActivityCard isAssessment=true (se existir avaliação)
 *   4. Attachments — AttachmentRows com FileTypeChip
 *
 * Valida matrícula via `CompetenceUnit::findForStudent`. Estados das
 * atividades/avaliação derivados server-side pra partial `activity_card`.
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

$courseId  = (int) $cu['course_id'];

// E19-03: config de progressão do curso (activity_mode + eval_after_activities).
// E19-04: tb cc_mode pro gate de URL direta abaixo.
$courseConfStmt = Database::pdo()->prepare(
    'SELECT cc_mode, activity_mode, eval_after_activities FROM courses WHERE id = ? LIMIT 1'
);
$courseConfStmt->execute([$courseId]);
$courseConf          = $courseConfStmt->fetch();
$ccMode              = (string) ($courseConf['cc_mode'] ?? 'sequential');
$activityMode        = (string) ($courseConf['activity_mode'] ?? 'sequential');
$evalAfterActivities = (int)    ($courseConf['eval_after_activities'] ?? 1);

// Gate de disponibilidade do curso (E17-03).
$availability = enrollment_access_status($studentId, $courseId);
if (!$availability['available']) {
    flash('warning', $availability['message'] ?? __t('enrollment.unavailable.generic'));
    header('Location: /student', true, 303);
    exit;
}

// E19-04: gate server-side anti-bypass via URL direta. Aluno digitando
// /student/cu/{id} de uma CU oculta/próxima é redirecionado pra page do
// curso com mensagem. Defesa em profundidade — UI já oculta/blureia, mas
// nada impede colar URL no browser. Skip quando cc_mode='free' (helper
// retorna 'free' pra todos status).
if ($ccMode === 'sequential') {
    $courseFull = StudentCurriculum::forStudentCourse($studentId, $courseId);
    if ($courseFull !== null) {
        $progressionGate = course_progression_state($courseFull, $studentId);
        $myCuStatus      = $progressionGate['cu_status'][$cuId] ?? 'free';
        if ($myCuStatus === 'hidden' || $myCuStatus === 'next') {
            flash('warning', __t('progression.cu_locked'));
            header('Location: /student/course/' . $courseId, true, 303);
            exit;
        }
    }
}
$cuName    = (string) $cu['name'];
$ccName    = (string) $cu['cc_name'];
$unitIndex = (int) ($cu['cu_index_in_cc'] ?? 1);
$workload  = (int) ($cu['workload_hours'] ?? 0);
[$gradStart, $gradEnd] = Course::coverGradient($courseId);

// Conteúdo HTML (sanitizado pelo professor em E5-01) — only if published.
$stmt = Database::pdo()->prepare(
    'SELECT id, html, published FROM contents WHERE competence_unit_id = ? LIMIT 1'
);
$stmt->execute([$cuId]);
$content = $stmt->fetch();
$hasPublishedContent = $content !== false && (int) $content['published'] === 1;
$html = $hasPublishedContent ? (string) $content['html'] : '';
if ($html !== '') {
    $html = str_replace('"/teacher/cu/', '"/student/cu/', $html);
}

// Atividades com estado.
$activitiesRaw = ActivitySubmission::listForStudentInCu($cuId, $studentId);

$activitiesCards = [];
$xpEarned = 0;
$xpTotal  = 0;
$activitiesDone = 0;
$activitiesCount = count($activitiesRaw);

foreach ($activitiesRaw as $a) {
    $open   = (int) $a['submission_open'] === 1;
    $hasSub = $a['submission_id'] !== null;
    $hasFb  = $hasSub && $a['feedback_at'] !== null;

    if ($hasFb) {
        $state = 'with-feedback';
        $progress = 100;
        $ctaKey = 'student.unit.cta.view_feedback';
        $ctaVariant = 'outline';
        $activitiesDone++;
    } elseif ($hasSub) {
        $state = 'pending';
        $progress = 50;
        $ctaKey = 'student.unit.cta.view_submission';
        $ctaVariant = 'outline';
    } elseif ($open) {
        $state = 'pending';
        $progress = 0;
        $ctaKey = 'student.unit.cta.open';
        $ctaVariant = 'gradient';
    } else {
        $state = 'unavailable';
        $progress = 0;
        $ctaKey = null;
        $ctaVariant = 'gradient';
    }

    $xpVal = (int) $a['xp_value'];
    $xpTotal += $xpVal;
    if ($hasFb) {
        $xpEarned += $xpVal;
    }

    $activitiesCards[] = [
        'url'             => '/student/activity/' . (int) $a['id'],
        'type_label'      => __t('activities.type.' . (string) $a['type']),
        'title'           => (string) $a['title'],
        'description'     => null,
        'xp_value'        => $xpVal,
        'state'           => $state,
        'progress'        => $progress,
        'grade'           => null,
        'cta_label'       => $ctaKey !== null ? __t($ctaKey) : null,
        'cta_variant'     => $ctaVariant,
        'is_assessment'   => false,
        'accent'          => null,
        'has_submission'  => $hasSub,
    ];
}

// E19-03: progressão sequencial nas atividades.
//   "Concluída" pra fins de progressão = TEM submissão (mesmo sem feedback).
//   Não bloqueia o aluno enquanto o professor demora pra corrigir.
$currentActivityName = null;
if ($activityMode === 'sequential') {
    $foundCurrentActivity   = false;
    $markNextActivityAsNext = false;
    foreach ($activitiesCards as $i => $card) {
        if ($markNextActivityAsNext) {
            $activitiesCards[$i]['progression_status'] = 'next';
            $markNextActivityAsNext = false;
            continue;
        }
        if ($foundCurrentActivity) {
            $activitiesCards[$i]['progression_status'] = 'hidden';
            continue;
        }
        if (!empty($card['has_submission'])) {
            $activitiesCards[$i]['progression_status'] = 'completed';
        } else {
            $activitiesCards[$i]['progression_status'] = 'current';
            $currentActivityName = (string) $card['title'];
            $foundCurrentActivity   = true;
            $markNextActivityAsNext = true;
        }
    }
} else {
    foreach ($activitiesCards as $i => $card) {
        $activitiesCards[$i]['progression_status'] = 'free';
    }
}

// Avaliação + estado.
$tenantId   = (int) ($user['tenant_id'] ?? 0);
$evaluation = $tenantId > 0 ? Evaluation::findByCu($cuId, $tenantId) : null;

$assessmentCard = null;
if ($evaluation !== null) {
    $evId = (int) $evaluation['id'];
    $ctx = EvaluationSubmission::findForStudentEvaluation($evId, $studentId);
    $current = $ctx['current'] ?? null;

    if ($current === null) {
        $state = 'pending'; $progress = 0; $grade = null;
        $ctaKey = 'student.unit.cta.open'; $ctaVariant = 'gradient';
    } elseif ($current['feedback_at'] === null) {
        $state = 'pending'; $progress = 50; $grade = null;
        $ctaKey = 'student.unit.cta.view_submission'; $ctaVariant = 'outline';
    } else {
        $grade = $current['grade'] !== null ? (float) $current['grade'] : null;
        if ($grade !== null && $grade >= 6.0) {
            $state = 'approved'; $progress = 100;
            $ctaKey = 'student.unit.cta.view_feedback'; $ctaVariant = 'outline';
        } elseif ((int) ($current['retry_allowed'] ?? 0) === 1) {
            $state = 'resubmit-pending'; $progress = 0;
            $ctaKey = 'student.unit.cta.resubmit'; $ctaVariant = 'gradient';
        } else {
            $state = 'failed'; $progress = 0;
            $ctaKey = 'student.unit.cta.view_feedback'; $ctaVariant = 'outline';
        }
    }

    $evXp = (int) $evaluation['xp_value'];
    $xpTotal += $evXp;
    if ($state === 'approved') {
        $xpEarned += $evXp;
    }

    $assessmentCard = [
        'url'           => '/student/evaluation/' . $evId,
        'type_label'    => __t('evaluations.student.card_section'),
        'title'         => (string) $evaluation['title'],
        'description'   => ($evaluation['instructions'] ?? null) !== null
            ? mb_substr(strip_tags((string) $evaluation['instructions']), 0, 220)
            : null,
        'xp_value'      => $evXp,
        'state'         => $state,
        'progress'      => $progress,
        'grade'         => $grade,
        'cta_label'     => $ctaKey !== null ? __t($ctaKey) : null,
        'cta_variant'   => $ctaVariant,
        'is_assessment' => true,
        'accent'        => 'linear-gradient(135deg, #F59E0B, #EF4444)',
    ];

    // E19-03: gate visual da avaliação quando eval_after_activities=1 e
    // tem qualquer atividade SEM submissão. Aluno tentando clicar leva 403
    // (gate server-side em /student/evaluation/{id}); aqui é UX preventiva.
    if ($evalAfterActivities === 1) {
        $hasPending = false;
        foreach ($activitiesCards as $card) {
            if (empty($card['has_submission'])) {
                $hasPending = true;
                break;
            }
        }
        if ($hasPending) {
            $assessmentCard['progression_status'] = 'eval_locked';
        }
    }
}

// Anexos.
$attachments = [];
if ($content !== false) {
    $stmt = Database::pdo()->prepare(
        // Imagens são anexadas só para uso inline no corpo do conteúdo (TinyMCE);
        // não devem aparecer na lista de materiais para download do aluno.
        'SELECT a.id, a.filename, a.mime, a.size_bytes
           FROM content_attachments a
          WHERE a.content_id = ?
            AND (a.mime IS NULL OR a.mime NOT LIKE \'image/%\')
          ORDER BY a.created_at DESC, a.id DESC'
    );
    $stmt->execute([(int) $content['id']]);
    $rows = $stmt->fetchAll();
    foreach ($rows as $r) {
        $attachments[] = [
            'filename'   => (string) $r['filename'],
            'mime'       => (string) $r['mime'],
            'size_bytes' => (int)    $r['size_bytes'],
            'url'        => '/student/cu/' . $cuId . '/attachment/' . (int) $r['id'],
        ];
    }
}
$attachmentsCount = count($attachments);

// Progresso global da CU (on-the-fly — mesma fórmula do StudentProgress)
$cuStatus = student_cu_status($cuId, $studentId);
$cuPercent = (int) $cuStatus['percent'];

// Section tabs.
$tabs = [
    ['anchor' => '#content',     'label' => __t('student.unit.tab.content')],
    ['anchor' => '#activities',  'label' => __t('student.unit.tab.activities'),  'count' => $activitiesCount],
];
if ($assessmentCard !== null) {
    $tabs[] = ['anchor' => '#assessment', 'label' => __t('student.unit.tab.assessment')];
}
if ($attachmentsCount > 0) {
    $tabs[] = ['anchor' => '#attachments', 'label' => __t('student.unit.tab.attachments'), 'count' => $attachmentsCount];
}

$page_title = $cuName;

ob_start();
?>
<?= breadcrumbs([
    ['label' => __t('dashboard.student.title'), 'url' => '/student'],
    ['label' => (string) $cu['course_name'],    'url' => '/student/course/' . $courseId],
    ['label' => $ccName],
    ['label' => $cuName],
]) ?>

<?php
    // Unit header — renderizado via partial.
    $percent = $cuPercent;
    require LMS_ROOT . '/src/templates/partials/unit_header.php';
?>

<?php require LMS_ROOT . '/src/templates/partials/section_tabs.php'; ?>

<!-- Section: Content -->
<section class="lms-unit-section" id="content">
    <?php
        $title = __t('student.unit.tab.content');
        $count = null;
        $accent = null;
        require LMS_ROOT . '/src/templates/partials/section_header.php';
    ?>
    <?php if ($hasPublishedContent): ?>
        <div class="lms-content-card"
             x-data="{ expanded: false, needsToggle: false }"
             x-init="$nextTick(() => { needsToggle = $refs.body.scrollHeight > 1500 })">
            <div x-ref="body" class="unit-prose lms-content-card__body" :class="{ 'is-collapsed': !expanded && needsToggle }">
                <?= $html /* HTML já sanitizado em E5-01 via ContentSanitizer */ ?>
            </div>
            <div class="lms-content-card__toggle" x-show="needsToggle" x-cloak>
                <button type="button" class="lms-content-card__toggle-btn"
                        @click="expanded = !expanded">
                    <span x-text="expanded ? '<?= e(__t('content.show_less')) ?>' : '<?= e(__t('content.show_more')) ?>'"></span>
                </button>
            </div>
        </div>
    <?php else: ?>
        <div class="lms-content-card lms-content-card--empty">
            <p class="lms-content-card__empty-title"><?= e(__t('student.content.not_available')) ?></p>
            <p class="lms-content-card__empty-hint"><?= e(__t('student.content.not_available_hint')) ?></p>
        </div>
    <?php endif; ?>
</section>

<!-- Section: Activities -->
<?php if ($activitiesCount > 0): ?>
<section class="lms-unit-section" id="activities">
    <?php
        $title = __t('student.unit.tab.activities');
        $count = $activitiesCount;
        $accent = null;
        require LMS_ROOT . '/src/templates/partials/section_header.php';
    ?>
    <div class="lms-activity-list">
        <?php foreach ($activitiesCards as $card): ?>
            <?php
                // E19-03: filtra hidden + propaga locked-by name pro partial.
                $progressionStatus    = $card['progression_status'] ?? 'free';
                if ($progressionStatus === 'hidden') {
                    continue;
                }
                $activityLockedByName = $currentActivityName;
                require LMS_ROOT . '/src/templates/partials/activity_card.php';
            ?>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<!-- Section: Assessment -->
<?php if ($assessmentCard !== null): ?>
<section class="lms-unit-section" id="assessment">
    <?php
        $title = __t('student.unit.tab.assessment');
        $count = null;
        $accent = 'linear-gradient(135deg, #F59E0B, #EF4444)';
        require LMS_ROOT . '/src/templates/partials/section_header.php';
    ?>
    <?php
        $card                 = $assessmentCard;
        $progressionStatus    = $card['progression_status'] ?? 'free';
        $activityLockedByName = null; // overlay da avaliação usa msg própria
        require LMS_ROOT . '/src/templates/partials/activity_card.php';
    ?>
</section>
<?php endif; ?>

<!-- Section: Attachments -->
<?php if ($attachmentsCount > 0): ?>
<section class="lms-unit-section" id="attachments">
    <?php
        $title = __t('student.unit.tab.attachments');
        $count = $attachmentsCount;
        $accent = null;
        require LMS_ROOT . '/src/templates/partials/section_header.php';
    ?>
    <div class="lms-attachment-list">
        <?php foreach ($attachments as $attachment): ?>
            <?php require LMS_ROOT . '/src/templates/partials/attachment_row.php'; ?>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>
<?php
$page_content = ob_get_clean();
require LMS_ROOT . '/src/templates/layout.php';
