<?php
declare(strict_types=1);

/**
 * Debug temporário do gate de avaliação (v0.30.0).
 *
 * Uso: logado como aluno, acessar
 *   https://lms.rumo.info/_debug_eval_gate.php?id=1
 * substituindo `1` pelo evaluation_id que está bypassando o gate.
 *
 * Mostra exatamente o que o show.php vê e por que NÃO bloqueou.
 *
 * **APAGAR este arquivo do servidor após debug** (FileZilla).
 */

require dirname(__DIR__) . '/src/bootstrap.php';
require_auth();

header('Content-Type: text/plain; charset=utf-8');

$user         = current_user();
$studentId    = (int) ($user['id'] ?? 0);
$evaluationId = (int) ($_GET['id'] ?? 0);

echo "=== DEBUG eval gate ===\n";
echo "user: id={$studentId} role={$user['role']} name={$user['name']}\n";
echo "evaluation_id: {$evaluationId}\n\n";

if ($evaluationId <= 0) {
    echo "ERRO: passe ?id=N na URL\n";
    exit;
}

$ctx = EvaluationSubmission::findForStudentEvaluation($evaluationId, $studentId);
if ($ctx === null) {
    echo "ctx=null — aluno NÃO tem matrícula no curso, ou avaliação nao existe.\n";
    echo "Logo o show.php devolveria 404 — gate nao seria nem testado.\n";
    exit;
}

$evaluation = $ctx['evaluation'];
$courseId   = (int) $evaluation['course_id'];
$cuId       = (int) $evaluation['competence_unit_id'];

echo "evaluation: id={$evaluation['id']} title={$evaluation['title']} type={$evaluation['type']}\n";
echo "course_id: {$courseId}\n";
echo "cu_id: {$cuId}\n\n";

$availability = enrollment_access_status($studentId, $courseId);
echo "availability: " . json_encode($availability, JSON_UNESCAPED_UNICODE) . "\n";
if (!$availability['available']) {
    echo "→ BLOQUEARIA por disponibilidade. Mas PO disse que abre, entao isso esta available=true.\n";
}
echo "\n";

$pdo = Database::pdo();
$stmt = $pdo->prepare(
    'SELECT cc_mode, activity_mode, eval_after_activities FROM courses WHERE id = ? LIMIT 1'
);
$stmt->execute([$courseId]);
$conf = $stmt->fetch();
echo "course config: " . json_encode($conf, JSON_UNESCAPED_UNICODE) . "\n";
$ccMode    = (string) ($conf['cc_mode']                ?? 'sequential');
$evalAfter = (int)    ($conf['eval_after_activities']  ?? 1);

echo "\n--- gate 1: cc_mode=sequential ---\n";
echo "ccMode = '{$ccMode}'\n";
if ($ccMode !== 'sequential') {
    echo "→ NÃO RODA (cc_mode != sequential). Curso é livre por CC.\n";
} else {
    $courseFull = StudentCurriculum::forStudentCourse($studentId, $courseId);
    if ($courseFull === null) {
        echo "courseFull = null. Aluno sem matricula completa? gate NÃO bloqueia.\n";
    } else {
        $progGate = course_progression_state($courseFull, $studentId);
        $cuStatus = $progGate['cu_status'][$cuId] ?? 'free';
        echo "cu_status[{$cuId}] = '{$cuStatus}'\n";
        echo "current_cc_name: " . ($progGate['current_cc_name'] ?? 'null') . "\n";
        echo "current_cu_name: " . ($progGate['current_cu_name'] ?? 'null') . "\n";
        if ($cuStatus === 'hidden' || $cuStatus === 'next') {
            echo "→ BLOQUEARIA (cu_status hidden/next). Se PO ve abrir, OPcache servindo codigo antigo.\n";
        } else {
            echo "→ NÃO BLOQUEIA (cu_status free/current/completed). Esse gate nao cobre o caso.\n";
        }
    }
}

echo "\n--- gate 2: eval_after_activities ---\n";
echo "evalAfter = {$evalAfter}\n";
if ($evalAfter !== 1) {
    echo "→ NÃO RODA (eval_after_activities = 0).\n";
} else {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM activities WHERE competence_unit_id = ?');
    $stmt->execute([$cuId]);
    $total = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare(
        'SELECT COUNT(DISTINCT a.id)
           FROM activities a
           JOIN activity_submissions s ON s.activity_id = a.id AND s.student_user_id = ?
          WHERE a.competence_unit_id = ?'
    );
    $stmt->execute([$studentId, $cuId]);
    $submitted = (int) $stmt->fetchColumn();

    echo "atividades da CU {$cuId}: total={$total}, submetidas={$submitted}\n";
    if ($total > 0 && $submitted < $total) {
        echo "→ BLOQUEARIA. Se PO ve abrir, OPcache servindo codigo antigo.\n";
    } else {
        echo "→ NÃO BLOQUEIA (todas submetidas, ou CU sem atividades).\n";
    }
}

echo "\n--- atividades da CU {$cuId} (todas) ---\n";
$stmt = $pdo->prepare(
    'SELECT a.id, a.title, a.position,
            (SELECT COUNT(*) FROM activity_submissions s
              WHERE s.activity_id = a.id AND s.student_user_id = ?) AS submitted
       FROM activities a
      WHERE a.competence_unit_id = ?
      ORDER BY a.position ASC, a.id ASC'
);
$stmt->execute([$studentId, $cuId]);
foreach ($stmt->fetchAll() as $a) {
    echo "  [{$a['position']}] id={$a['id']} title={$a['title']} submitted={$a['submitted']}\n";
}

echo "\n--- FIM ---\n";
echo "Apague public/_debug_eval_gate.php do servidor apos uso.\n";
