<?php
declare(strict_types=1);

/**
 * Model do conteúdo HTML de uma CU (E5-01).
 *
 * Regra: 1 conteúdo por CU (UK `uk_contents_cu` em `contents.competence_unit_id`).
 * Toda query valida que a CU pertence ao tenant via JOIN em `core_competencies`
 * e `courses` — caller nunca consegue ler/gravar conteúdo cross-tenant.
 *
 * `published` controla visibilidade pro aluno (E5-05). Default 0.
 */
final class Content
{
    /**
     * Retorna o conteúdo da CU (html + published + timestamps) se existir e a
     * CU pertencer ao tenant; null caso contrário.
     *
     * @return array<string,mixed>|null
     */
    public static function findForCu(int $cuId, int $tenantId): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT co.id, co.competence_unit_id, co.html, co.published,
                    co.created_at, co.updated_at
               FROM contents co
               JOIN competence_units cu ON cu.id = co.competence_unit_id
               JOIN core_competencies cc ON cc.id = cu.core_competency_id
               JOIN courses c ON c.id = cc.course_id AND c.tenant_id = ?
              WHERE co.competence_unit_id = ?
              LIMIT 1'
        );
        $stmt->execute([$tenantId, $cuId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * UPSERT idempotente de conteúdo por CU. Valida que a CU pertence ao
     * tenant e que o curso não está arquivado antes de gravar.
     *
     * @param string $html HTML já sanitizado pelo caller (ContentSanitizer::purify).
     * @return string 'ok' | 'not_found' | 'course_archived'
     */
    public static function upsertForCu(int $cuId, int $tenantId, string $html, bool $published): string
    {
        $pdo = Database::pdo();

        $stmt = $pdo->prepare(
            'SELECT c.archived
               FROM competence_units cu
               JOIN core_competencies cc ON cc.id = cu.core_competency_id
               JOIN courses c ON c.id = cc.course_id AND c.tenant_id = ?
              WHERE cu.id = ?
              LIMIT 1'
        );
        $stmt->execute([$tenantId, $cuId]);
        $row = $stmt->fetch();
        if ($row === false) {
            return 'not_found';
        }
        if ((int) $row['archived'] === 1) {
            return 'course_archived';
        }

        $pdo->prepare(
            'INSERT INTO contents (competence_unit_id, html, published)
                  VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE
                 html      = VALUES(html),
                 published = VALUES(published)'
        )->execute([$cuId, $html, $published ? 1 : 0]);

        // E5-06: fanout de notifications quando o conteúdo está publicado.
        // Uma linha por aluno matriculado no curso que contém a CU. E10
        // (entrega real por email + inbox na UI) normaliza/deduplica — aqui
        // o contrato é só "infra registrada". Saves com published=0 não
        // disparam nada.
        if ($published) {
            self::fanoutPublishedNotifications($cuId);
        }

        return 'ok';
    }

    /**
     * Fanout de `content_published` pros alunos matriculados no curso que
     * contém a CU, via `NotificationService` (E10-00). `title` = nome da CU,
     * `body` = nome do curso, `link` = rota do aluno.
     *
     * Caller deve ter validado acesso antes — esta função não verifica
     * tenant novamente (é chamada pelo upsertForCu que já passou pela
     * validação de tenant).
     */
    private static function fanoutPublishedNotifications(int $cuId): void
    {
        $stmt = Database::pdo()->prepare(
            'SELECT e.student_user_id, cu.name AS cu_name,
                    c.name AS course_name, c.id AS course_id
               FROM enrollments e
               JOIN courses c            ON c.id  = e.course_id
               JOIN core_competencies cc ON cc.course_id = c.id
               JOIN competence_units cu  ON cu.core_competency_id = cc.id
              WHERE cu.id = ?'
        );
        $stmt->execute([$cuId]);
        $rows = $stmt->fetchAll();

        if ($rows === []) {
            return;
        }

        $userIds = array_map(static fn ($r) => (int) $r['student_user_id'], $rows);

        NotificationService::fanout(
            NotificationService::EVENT_CONTENT_PUBLISHED,
            $userIds,
            (string) $rows[0]['cu_name'],
            (string) $rows[0]['course_name'],
            '/student/cu/' . $cuId,
            (int) $rows[0]['course_id']
        );
    }

    /**
     * Garante a existência de um `contents` para a CU (cria vazio se não
     * existir ainda). Valida que a CU pertence ao tenant. Usado pelo fluxo
     * de anexos (E5-03) pra ter um `content_id` válido mesmo antes do
     * professor salvar HTML pela primeira vez.
     *
     * @return int|string content_id em sucesso; 'not_found' se CU alheia.
     */
    public static function ensureForCu(int $cuId, int $tenantId): int|string
    {
        $pdo = Database::pdo();

        $stmt = $pdo->prepare(
            'SELECT cu.id
               FROM competence_units cu
               JOIN core_competencies cc ON cc.id = cu.core_competency_id
               JOIN courses c ON c.id = cc.course_id AND c.tenant_id = ?
              WHERE cu.id = ?
              LIMIT 1'
        );
        $stmt->execute([$tenantId, $cuId]);
        if ($stmt->fetchColumn() === false) {
            return 'not_found';
        }

        // INSERT IGNORE aproveita UK em competence_unit_id: se já existir,
        // não cria duplicado; se não, cria com html='' + published=0.
        $pdo->prepare(
            'INSERT IGNORE INTO contents (competence_unit_id, html, published)
                  VALUES (?, \'\', 0)'
        )->execute([$cuId]);

        $stmt = $pdo->prepare('SELECT id FROM contents WHERE competence_unit_id = ? LIMIT 1');
        $stmt->execute([$cuId]);
        return (int) $stmt->fetchColumn();
    }
}
