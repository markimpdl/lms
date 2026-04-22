<?php
declare(strict_types=1);

/**
 * POST /teacher/cc/{id}/move-up|move-down — reordenação (E3-02).
 * A direção vem do path (capturada pelo router via regex) e é passada aqui
 * via $_REQUEST['direction'] — setada pelo front controller ao matchear
 * o pattern correspondente.
 */

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

$ccId      = (int)    ($_REQUEST['id']        ?? 0);
$direction = (string) ($_REQUEST['direction'] ?? '');
if ($direction !== 'up' && $direction !== 'down') {
    http_response_code(400);
    exit;
}

TeacherCurriculumController::moveCc($ccId, $direction);
