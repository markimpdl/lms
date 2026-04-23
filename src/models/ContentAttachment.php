<?php
declare(strict_types=1);

/**
 * Model de anexo de conteúdo da CU (E5-03).
 *
 * 1:N com `contents` via `content_id`. Como `contents.competence_unit_id`
 * tem UK (1:1 com CU), anexos pertencem efetivamente a uma CU.
 * Isolamento por tenant via JOIN em `courses` — caller nunca consegue
 * listar/apagar anexo cross-tenant.
 *
 * Arquivo físico vive em `storage/uploads/tenant_<tid>/content/<cu_id>/<uuid>.<ext>`
 * (ver `AttachmentStorage`). Este model só cuida da tabela; o service cuida
 * do filesystem.
 */
final class ContentAttachment
{
    /**
     * Cria o registro do anexo após o service ter salvo o arquivo físico.
     * Caller garante que todos os parâmetros são válidos (mime real, tamanho
     * dentro do limite, stored_path sanitizado).
     */
    public static function create(
        int $contentId,
        string $filename,
        string $storedPath,
        string $mime,
        int $sizeBytes
    ): int {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO content_attachments
                (content_id, filename, stored_path, mime, size_bytes)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$contentId, $filename, $storedPath, $mime, $sizeBytes]);
        return (int) Database::pdo()->lastInsertId();
    }

    /**
     * Lista anexos da CU, ordenados pelo mais recente primeiro. Vazio se a
     * CU não é do tenant ou não tem conteúdo ainda.
     *
     * @return list<array<string,mixed>>
     */
    public static function listByCu(int $cuId, int $tenantId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT a.id, a.content_id, a.filename, a.stored_path,
                    a.mime, a.size_bytes, a.created_at
               FROM content_attachments a
               JOIN contents co           ON co.id = a.content_id
               JOIN competence_units cu   ON cu.id = co.competence_unit_id
               JOIN core_competencies cc  ON cc.id = cu.core_competency_id
               JOIN courses c             ON c.id  = cc.course_id AND c.tenant_id = ?
              WHERE cu.id = ?
              ORDER BY a.created_at DESC, a.id DESC'
        );
        $stmt->execute([$tenantId, $cuId]);
        return $stmt->fetchAll();
    }

    /**
     * Retorna o anexo (id, stored_path, filename, mime) se existir e pertencer
     * ao tenant; null caso contrário. Usado pelo delete e pelo download (E5-04).
     *
     * @return array<string,mixed>|null
     */
    public static function findForTenant(int $aid, int $tenantId): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT a.id, a.content_id, a.filename, a.stored_path,
                    a.mime, a.size_bytes, co.competence_unit_id
               FROM content_attachments a
               JOIN contents co           ON co.id = a.content_id
               JOIN competence_units cu   ON cu.id = co.competence_unit_id
               JOIN core_competencies cc  ON cc.id = cu.core_competency_id
               JOIN courses c             ON c.id  = cc.course_id AND c.tenant_id = ?
              WHERE a.id = ?
              LIMIT 1'
        );
        $stmt->execute([$tenantId, $aid]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Apaga o registro. Caller (AttachmentStorage::delete) é responsável por
     * apagar o arquivo físico DEPOIS de confirmar que o DELETE ocorreu.
     */
    public static function delete(int $aid): void
    {
        Database::pdo()
            ->prepare('DELETE FROM content_attachments WHERE id = ?')
            ->execute([$aid]);
    }
}
