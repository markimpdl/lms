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
    abort_subresource(403);
}

$aid = (int) ($_REQUEST['aid'] ?? 0);
$att = ContentAttachment::findForStudent($aid, (int) $user['id']);
if ($att === null) {
    abort_subresource(404);
}

// Unidade em rascunho: o anexo eh material do professor, e a aba de anexos
// ja sumiu da capa da CU. Aqui fecha o link que o aluno tenha salvo antes
// de o conteudo ser despublicado.
if (cu_is_draft_for_student((int) $att['competence_unit_id'])) {
    abort_subresource(404);
}

AttachmentStorage::stream($att, 'attachment');
