<?php
declare(strict_types=1);

/** POST /teacher/cu/{id}/copy — copia CU para uma CC de destino (E31-04, #467). */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}

try {
    csrf_verify();
} catch (RuntimeException) {
    flash('danger', __t('auth.forbidden'));
    header('Location: /teacher/courses');
    exit;
}

$cuId       = (int) ($_REQUEST['id']        ?? 0);
$targetCcId = (int) ($_POST['target_cc_id'] ?? 0);
TeacherCurriculumController::copyCu($cuId, $targetCcId);
