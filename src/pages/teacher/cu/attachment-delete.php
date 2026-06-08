<?php
declare(strict_types=1);

/**
 * POST /teacher/cu/{id}/attachment/{aid}/delete — remove anexo (E5-03).
 * Sempre POST + CSRF; GET → 405. Valida tenant via AttachmentStorage::delete.
 */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}

// E32 (ADR-033): conteúdo via tenant do dono (dono ou colaborador).
$__cuId     = (int) ($_REQUEST['id'] ?? 0);
$__courseId = CompetenceUnit::courseIdOf($__cuId);
$tenantId   = $__courseId !== null ? effective_authoring_tenant($__courseId) : null;
if ($tenantId === null) {
    http_response_code(404);
    require LMS_ROOT . '/src/templates/errors/404.php';
    return;
}

try {
    csrf_verify();
} catch (RuntimeException) {
    flash('danger', __t('auth.forbidden'));
    header('Location: /teacher/cu/' . (int) ($_REQUEST['id'] ?? 0) . '/content/edit');
    exit;
}

$cuId = (int) ($_REQUEST['id'] ?? 0);
$aid  = (int) ($_REQUEST['aid'] ?? 0);

// E33 (F24/ADR-035): captura o nome do anexo ANTES do delete (snapshot).
$attToDelete = ContentAttachment::findForTenant($aid, $tenantId);

$deleted = AttachmentStorage::delete($aid, $tenantId);

if ($deleted) {
    course_audit(
        (int) $__courseId,
        'update',
        'content',
        $cuId,
        $attToDelete !== null ? (string) $attToDelete['filename'] : ('anexo #' . $aid)
    );
    flash('success', __t('attachments.deleted'));
} else {
    // Anexo não pertence ao tenant ou não existe — 404 genérico pra não vazar.
    flash('danger', __t('attachments.err.not_found'));
}

header('Location: /teacher/cu/' . $cuId . '/content/edit', true, 303);
