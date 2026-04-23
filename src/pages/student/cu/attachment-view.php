<?php
declare(strict_types=1);

/**
 * GET /student/cu/{id}/attachment/{aid}/view — serve anexo inline para
 * aluno matriculado no curso que contém a CU (E5-04). Usado por tags
 * `<img src="...">` que o professor inseriu no conteúdo publicado.
 *
 * Validação: `ContentAttachment::findForStudent` faz JOIN com
 * `enrollments` — retorna null se o aluno não tem matrícula ativa no
 * curso, resultando em 404 amigável. Nunca vaza presença de anexo
 * cross-tenant ou cross-curso.
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

AttachmentStorage::stream($att, 'inline');
