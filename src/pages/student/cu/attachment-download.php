<?php
declare(strict_types=1);

/**
 * GET /student/cu/{id}/attachment/{aid} — download forçado do anexo para
 * aluno matriculado (E5-04). Mesmo handler de `/view` mas com
 * `Content-Disposition: attachment`.
 */

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    exit;
}

$user = current_user();
if ($user === null || ($user['role'] ?? '') !== 'student') {
    http_response_code(403);
    require LMS_ROOT . '/src/templates/errors/403.php';
    return;
}

$aid = (int) ($_REQUEST['aid'] ?? 0);
$att = ContentAttachment::findForStudent($aid, (int) $user['id']);
if ($att === null) {
    http_response_code(404);
    require LMS_ROOT . '/src/templates/errors/404.php';
    return;
}

AttachmentStorage::stream($att, 'attachment');
