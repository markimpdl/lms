<?php
declare(strict_types=1);

/**
 * Model de Avaliação (E7-01).
 *
 * Avaliação pertence a uma CU (1:1 via UNIQUE — ADR-007). A tabela
 * tem `tenant_id` direto (adicionado em E7-00) pra simplificar o
 * filtro multi-tenant sem JOIN até courses. O JOIN em courses
 * continua necessário pra ler `course_archived` nas flags de UI.
 *
 * Nota dá XP apenas com >= 8 (ADR-002); >= 6 aprova sem reenvio.
 * Essas regras vivem em E7-03 — aqui só mexemos no CRUD + cascade.
 */
final class Evaluation
{
    /**
     * Retorna avaliação + contexto (cu_id, cc_id, course_id, course_archived).
     * null se não pertence ao tenant.
     *
     * @return array<string,mixed>|null
     */
    public static function findForTenant(int $id, int $tenantId): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT e.id, e.tenant_id, e.competence_unit_id, e.title, e.instructions,
                    e.pdf_path, e.xp_value, e.submission_open,
                    e.created_at, e.updated_at,
                    cc.id AS cc_id, c.id AS course_id, c.archived AS course_archived
               FROM evaluations e
               JOIN competence_units cu   ON cu.id = e.competence_unit_id
               JOIN core_competencies cc  ON cc.id = cu.core_competency_id
               JOIN courses c             ON c.id  = cc.course_id
              WHERE e.id = ? AND e.tenant_id = ?
              LIMIT 1'
        );
        $stmt->execute([$id, $tenantId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Retorna a avaliação da CU (se existir) validando tenant. Usado no
     * show.php da CU pra decidir entre "criar avaliação" e "editar".
     *
     * @return array<string,mixed>|null
     */
    public static function findByCu(int $cuId, int $tenantId): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT e.id, e.title, e.pdf_path, e.xp_value, e.submission_open
               FROM evaluations e
               JOIN competence_units cu   ON cu.id = e.competence_unit_id
               JOIN core_competencies cc  ON cc.id = cu.core_competency_id
               JOIN courses c             ON c.id  = cc.course_id
              WHERE e.competence_unit_id = ? AND e.tenant_id = ?
              LIMIT 1'
        );
        $stmt->execute([$cuId, $tenantId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Cria avaliação. Valida CU/tenant + curso não arquivado + ausência de
     * avaliação prévia (ADR-007). Retorna id novo ou 'not_found' /
     * 'course_archived' / 'already_exists'.
     *
     * @param array{title:string, instructions:string, xp_value:int, submission_open:bool, pdf_path:?string} $data
     * @return int|string
     */
    public static function create(int $cuId, int $tenantId, array $data): int|string
    {
        return Database::tx(static function (PDO $pdo) use ($cuId, $tenantId, $data): int|string {
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

            $stmt = $pdo->prepare(
                'SELECT 1 FROM evaluations WHERE competence_unit_id = ? LIMIT 1'
            );
            $stmt->execute([$cuId]);
            if ($stmt->fetchColumn() !== false) {
                return 'already_exists';
            }

            $ins = $pdo->prepare(
                'INSERT INTO evaluations
                    (tenant_id, competence_unit_id, title, instructions,
                     pdf_path, xp_value, submission_open)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $ins->execute([
                $tenantId,
                $cuId,
                $data['title'],
                $data['instructions'] !== '' ? $data['instructions'] : null,
                $data['pdf_path'],
                $data['xp_value'],
                $data['submission_open'] ? 1 : 0,
            ]);
            return (int) $pdo->lastInsertId();
        });
    }

    /**
     * Atualiza campos editáveis. Se $pdfPath !== null, sobrescreve o
     * caminho (upload novo); se null, mantém o valor atual (sem alteração
     * do PDF). Retorna 'ok', 'not_found' ou 'course_archived'.
     *
     * @param array{title:string, instructions:string, xp_value:int, submission_open:bool, pdf_path:?string} $data
     */
    public static function update(int $id, int $tenantId, array $data): string
    {
        $evaluation = self::findForTenant($id, $tenantId);
        if ($evaluation === null) {
            return 'not_found';
        }
        if ((int) $evaluation['course_archived'] === 1) {
            return 'course_archived';
        }

        if ($data['pdf_path'] !== null) {
            Database::pdo()->prepare(
                'UPDATE evaluations
                    SET title = ?, instructions = ?, pdf_path = ?,
                        xp_value = ?, submission_open = ?
                  WHERE id = ?'
            )->execute([
                $data['title'],
                $data['instructions'] !== '' ? $data['instructions'] : null,
                $data['pdf_path'],
                $data['xp_value'],
                $data['submission_open'] ? 1 : 0,
                $id,
            ]);
        } else {
            Database::pdo()->prepare(
                'UPDATE evaluations
                    SET title = ?, instructions = ?,
                        xp_value = ?, submission_open = ?
                  WHERE id = ?'
            )->execute([
                $data['title'],
                $data['instructions'] !== '' ? $data['instructions'] : null,
                $data['xp_value'],
                $data['submission_open'] ? 1 : 0,
                $id,
            ]);
        }
        return 'ok';
    }

    /**
     * Conta submissões (todas as tentativas, não só is_current).
     */
    public static function countSubmissions(int $evaluationId): int
    {
        $stmt = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM evaluation_submissions WHERE evaluation_id = ?'
        );
        $stmt->execute([$evaluationId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Contagens usadas no modal de exclusão. xp_events é polimórfico
     * (source_type='evaluation'), sem FK.
     *
     * @return array{submissions:int, xp_events:int}
     */
    public static function countForDelete(int $evaluationId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT
                (SELECT COUNT(*) FROM evaluation_submissions WHERE evaluation_id = ?) AS submissions,
                (SELECT COUNT(*) FROM xp_events
                  WHERE source_type = \'evaluation\' AND source_id = ?) AS xp_events'
        );
        $stmt->execute([$evaluationId, $evaluationId]);
        $row = $stmt->fetch();
        return [
            'submissions' => (int) ($row['submissions'] ?? 0),
            'xp_events'   => (int) ($row['xp_events']   ?? 0),
        ];
    }

    /**
     * Exclui avaliação após revalidar título (case-sensitive). Retorna o
     * pdf_path e os stored_paths das submissions pro handler apagar do disco.
     *
     * Ordem: snapshot dos paths → DELETE xp_events (polimórfico, sem FK) →
     * DELETE evaluations (cascade apaga evaluation_submissions).
     *
     * @return array{status:string, pdf_path?:?string, stored_paths?:list<string>}
     */
    public static function delete(int $id, int $tenantId, string $expectedTitle): array
    {
        $evaluation = self::findForTenant($id, $tenantId);
        if ($evaluation === null) {
            return ['status' => 'not_found'];
        }
        if ((string) $evaluation['title'] !== $expectedTitle) {
            return ['status' => 'name_mismatch'];
        }

        return Database::tx(static function (PDO $pdo) use ($id, $evaluation): array {
            $stmt = $pdo->prepare(
                'SELECT stored_path FROM evaluation_submissions
                  WHERE evaluation_id = ? AND stored_path IS NOT NULL'
            );
            $stmt->execute([$id]);
            $paths = $stmt->fetchAll(PDO::FETCH_COLUMN);

            $pdo->prepare(
                'DELETE FROM xp_events
                  WHERE source_type = \'evaluation\' AND source_id = ?'
            )->execute([$id]);

            $pdo->prepare('DELETE FROM evaluations WHERE id = ?')
                ->execute([$id]);

            return [
                'status'       => 'ok',
                'pdf_path'     => $evaluation['pdf_path'] !== null ? (string) $evaluation['pdf_path'] : null,
                'stored_paths' => array_map('strval', $paths),
            ];
        });
    }
}
