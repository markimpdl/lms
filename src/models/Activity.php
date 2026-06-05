<?php
declare(strict_types=1);

/**
 * Model de Atividade (E6-01).
 *
 * Atividade pertence a uma CU → CC → curso → tenant. Toda query valida
 * esse caminho via JOIN. Não tem nota (doc 06) — XP é creditado no
 * momento da entrega (ADR-002), e a entrega é única por aluno (UK em
 * activity_submissions).
 */
final class Activity
{
    public const TYPES = ['projeto', 'codigo', 'quiz'];

    /** Linguagens suportadas pra atividades tipo `codigo` (E8-00). NULL em
     *  atividades legacy ou quando o professor ainda não configurou. */
    public const CODE_LANGUAGES = ['python', 'csharp', 'javascript', 'html'];

    /**
     * Retorna atividade + contexto (cu_id, course_id, course_archived) se
     * pertence ao tenant; null caso contrário.
     *
     * @return array<string,mixed>|null
     */
    public static function findForTenant(int $id, int $tenantId): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT a.id, a.competence_unit_id, a.title, a.instruction,
                    a.type, a.code_language, a.pdf_path,
                    a.xp_value, a.submission_open,
                    a.allow_online_code_run, a.position,
                    a.created_at, a.updated_at,
                    cc.id AS cc_id, c.id AS course_id, c.archived AS course_archived
               FROM activities a
               JOIN competence_units cu   ON cu.id = a.competence_unit_id
               JOIN core_competencies cc  ON cc.id = cu.core_competency_id
               JOIN courses c             ON c.id  = cc.course_id AND c.tenant_id = ?
              WHERE a.id = ?
              LIMIT 1'
        );
        $stmt->execute([$tenantId, $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * course_id da atividade, SEM filtro de tenant — para o gating de acesso
     * compartilhado (E32). Retorna null se a atividade não existe.
     */
    public static function courseIdOf(int $activityId): ?int
    {
        $st = Database::pdo()->prepare(
            'SELECT cc.course_id
               FROM activities a
               JOIN competence_units cu  ON cu.id = a.competence_unit_id
               JOIN core_competencies cc ON cc.id = cu.core_competency_id
              WHERE a.id = ? LIMIT 1'
        );
        $st->execute([$activityId]);
        $v = $st->fetchColumn();
        return $v === false ? null : (int) $v;
    }

    /**
     * Cria atividade com position = MAX+1 da CU. Valida que a CU pertence ao
     * tenant e que o curso não está arquivado. Retorna id novo, ou 'not_found'
     * / 'course_archived'.
     *
     * `pdf_path` é null no INSERT — o handler chama `store()` no service de
     * brief depois (precisa do `id` no path) e atualiza via UPDATE separado.
     * Mesmo padrão de `Evaluation::create`. Brief só faz sentido pra
     * type='projeto' (gateado no form).
     *
     * @param array{title:string, instruction:string, type:string, code_language?:?string, pdf_path?:?string, xp_value:int, submission_open:bool, allow_online_code_run:bool} $data
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
                'SELECT COALESCE(MAX(position), -1) + 1
                   FROM activities WHERE competence_unit_id = ?'
            );
            $stmt->execute([$cuId]);
            $pos = (int) $stmt->fetchColumn();

            // code_language só se aplica a type='codigo' e precisa estar no
            // ENUM; pra qualquer outro cenário, grava NULL (defense-in-depth).
            $lang = null;
            if (($data['type'] ?? '') === 'codigo') {
                $raw = $data['code_language'] ?? null;
                if ($raw !== null && in_array($raw, self::CODE_LANGUAGES, true)) {
                    $lang = $raw;
                }
            }

            // pdf_path só pra type='projeto'; gravado pelo handler via UPDATE
            // após store() pra ter o id no path. INSERT inicial sempre NULL.
            $pdfPath = null;
            if (($data['type'] ?? '') === 'projeto') {
                $pdfPath = $data['pdf_path'] ?? null;
            }

            $ins = $pdo->prepare(
                'INSERT INTO activities
                    (competence_unit_id, title, instruction, type, code_language,
                     pdf_path, xp_value, submission_open, allow_online_code_run, position)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $ins->execute([
                $cuId,
                $data['title'],
                $data['instruction'],
                $data['type'],
                $lang,
                $pdfPath,
                $data['xp_value'],
                $data['submission_open'] ? 1 : 0,
                $data['allow_online_code_run'] ? 1 : 0,
                $pos,
            ]);
            return (int) $pdo->lastInsertId();
        });
    }

    /**
     * Atualiza campos editáveis. Retorna 'ok', 'not_found' ou 'course_archived'.
     * Curso arquivado bloqueia edição.
     *
     * `pdf_path` semântica (igual a `Evaluation::update`):
     *   - `null`     → mantém o valor atual (sem upload novo nem remoção)
     *   - string     → grava o novo path (caller já fez `store()`)
     *   - `''`       → zera pdf_path (caller pediu remover; deletar arquivo é responsabilidade do handler via `ActivityBriefStorage::delete`)
     *
     * @param array{title:string, instruction:string, type:string, code_language?:?string, pdf_path?:?string, xp_value:int, submission_open:bool, allow_online_code_run:bool} $data
     */
    public static function update(int $id, int $tenantId, array $data): string
    {
        $activity = self::findForTenant($id, $tenantId);
        if ($activity === null) {
            return 'not_found';
        }
        if ((int) $activity['course_archived'] === 1) {
            return 'course_archived';
        }

        $lang = null;
        if (($data['type'] ?? '') === 'codigo') {
            $raw = $data['code_language'] ?? null;
            if ($raw !== null && in_array($raw, self::CODE_LANGUAGES, true)) {
                $lang = $raw;
            }
        }

        $pdfRaw    = $data['pdf_path'] ?? null;
        $touchPdf  = $pdfRaw !== null;
        $newPdf    = ($pdfRaw === '' || $data['type'] !== 'projeto') ? null : $pdfRaw;

        if ($touchPdf) {
            Database::pdo()->prepare(
                'UPDATE activities
                    SET title = ?, instruction = ?, type = ?, code_language = ?,
                        pdf_path = ?,
                        xp_value = ?, submission_open = ?, allow_online_code_run = ?
                  WHERE id = ?'
            )->execute([
                $data['title'],
                $data['instruction'],
                $data['type'],
                $lang,
                $newPdf,
                $data['xp_value'],
                $data['submission_open'] ? 1 : 0,
                $data['allow_online_code_run'] ? 1 : 0,
                $id,
            ]);
        } else {
            Database::pdo()->prepare(
                'UPDATE activities
                    SET title = ?, instruction = ?, type = ?, code_language = ?,
                        xp_value = ?, submission_open = ?, allow_online_code_run = ?
                  WHERE id = ?'
            )->execute([
                $data['title'],
                $data['instruction'],
                $data['type'],
                $lang,
                $data['xp_value'],
                $data['submission_open'] ? 1 : 0,
                $data['allow_online_code_run'] ? 1 : 0,
                $id,
            ]);
        }
        return 'ok';
    }

    /**
     * Quantas submissões existem para a atividade. Usado no aviso do form
     * de edição (doc 06: "editar é permitido mas gera aviso").
     */
    public static function countSubmissions(int $activityId): int
    {
        $stmt = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM activity_submissions WHERE activity_id = ?'
        );
        $stmt->execute([$activityId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Contagens usadas no modal de exclusão (E6-05). Submissions são apagadas
     * via cascade FK; xp_events é polimórfico (sem FK) e precisa DELETE manual.
     *
     * @return array{submissions:int, xp_events:int}
     */
    public static function countForDelete(int $activityId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT
                (SELECT COUNT(*) FROM activity_submissions WHERE activity_id = ?) AS submissions,
                (SELECT COUNT(*) FROM xp_events
                  WHERE source_type = \'activity\' AND source_id = ?) AS xp_events'
        );
        $stmt->execute([$activityId, $activityId]);
        $row = $stmt->fetch();
        return [
            'submissions' => (int) ($row['submissions'] ?? 0),
            'xp_events'   => (int) ($row['xp_events']   ?? 0),
        ];
    }

    /**
     * Exclui atividade após revalidar título (case-sensitive) e pertencimento
     * ao tenant. Retorna 'ok' + lista de stored_paths pro handler apagar, ou
     * 'not_found' / 'name_mismatch' em erro.
     *
     * Ordem: snapshot dos paths → DELETE xp_events (polimórfico, sem FK) →
     * DELETE activities (cascade apaga activity_submissions).
     *
     * @return array{status:string, stored_paths?:list<string>}
     */
    public static function delete(int $id, int $tenantId, string $expectedTitle): array
    {
        $activity = self::findForTenant($id, $tenantId);
        if ($activity === null) {
            return ['status' => 'not_found'];
        }
        if ((string) $activity['title'] !== $expectedTitle) {
            return ['status' => 'name_mismatch'];
        }

        return Database::tx(static function (PDO $pdo) use ($id): array {
            // Submissões + brief PDF (v0.30.0) — UNION ALL pra um SELECT só.
            $stmt = $pdo->prepare(
                'SELECT stored_path FROM activity_submissions
                  WHERE activity_id = ? AND stored_path IS NOT NULL
                  UNION ALL
                 SELECT pdf_path FROM activities
                  WHERE id = ? AND pdf_path IS NOT NULL'
            );
            $stmt->execute([$id, $id]);
            $paths = $stmt->fetchAll(PDO::FETCH_COLUMN);

            $pdo->prepare(
                'DELETE FROM xp_events
                  WHERE source_type = \'activity\' AND source_id = ?'
            )->execute([$id]);

            $pdo->prepare('DELETE FROM activities WHERE id = ?')
                ->execute([$id]);

            return [
                'status'       => 'ok',
                'stored_paths' => array_map('strval', $paths),
            ];
        });
    }

    /**
     * Lista atividades da CU ordenadas por `position ASC, id ASC` com
     * contagem de submissões. Valida que a CU pertence ao tenant via
     * JOIN em courses.
     *
     * @return list<array<string,mixed>>
     */
    public static function listByCu(int $cuId, int $tenantId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT a.id, a.title, a.type, a.xp_value,
                    a.submission_open, a.allow_online_code_run, a.position,
                    (SELECT COUNT(*) FROM activity_submissions s
                      WHERE s.activity_id = a.id) AS submission_count
               FROM activities a
               JOIN competence_units cu   ON cu.id = a.competence_unit_id
               JOIN core_competencies cc  ON cc.id = cu.core_competency_id
               JOIN courses c             ON c.id  = cc.course_id AND c.tenant_id = ?
              WHERE a.competence_unit_id = ?
              ORDER BY a.position ASC, a.id ASC'
        );
        $stmt->execute([$tenantId, $cuId]);
        return $stmt->fetchAll();
    }

    /**
     * Alterna `submission_open`. Retorna novo valor (0|1) ou null se
     * atividade não pertence ao tenant / curso arquivado.
     */
    public static function toggleSubmissionOpen(int $id, int $tenantId): ?int
    {
        $activity = self::findForTenant($id, $tenantId);
        if ($activity === null || (int) $activity['course_archived'] === 1) {
            return null;
        }
        $next = (int) $activity['submission_open'] === 1 ? 0 : 1;
        Database::pdo()
            ->prepare('UPDATE activities SET submission_open = ? WHERE id = ?')
            ->execute([$next, $id]);
        return $next;
    }

    public static function moveUp(int $id, int $tenantId): bool
    {
        return self::move($id, $tenantId, -1);
    }

    public static function moveDown(int $id, int $tenantId): bool
    {
        return self::move($id, $tenantId, +1);
    }

    /**
     * Swap + renormalização das positions em 0..N-1 (padrão E3-02).
     * false se atividade não existe, não pertence ao tenant, curso
     * arquivado, ou já está na ponta.
     */
    private static function move(int $id, int $tenantId, int $offset): bool
    {
        return Database::tx(static function (PDO $pdo) use ($id, $tenantId, $offset): bool {
            $stmt = $pdo->prepare(
                'SELECT a.competence_unit_id, c.archived
                   FROM activities a
                   JOIN competence_units cu   ON cu.id = a.competence_unit_id
                   JOIN core_competencies cc  ON cc.id = cu.core_competency_id
                   JOIN courses c             ON c.id  = cc.course_id AND c.tenant_id = ?
                  WHERE a.id = ? LIMIT 1'
            );
            $stmt->execute([$tenantId, $id]);
            $row = $stmt->fetch();
            if ($row === false || (int) $row['archived'] === 1) {
                return false;
            }
            $cuId = (int) $row['competence_unit_id'];

            $stmt = $pdo->prepare(
                'SELECT id FROM activities
                  WHERE competence_unit_id = ?
                  ORDER BY position ASC, id ASC'
            );
            $stmt->execute([$cuId]);
            $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

            $idx = array_search($id, $ids, true);
            if ($idx === false) {
                return false;
            }
            $newIdx = $idx + $offset;
            if ($newIdx < 0 || $newIdx >= count($ids)) {
                return false;
            }

            [$ids[$idx], $ids[$newIdx]] = [$ids[$newIdx], $ids[$idx]];

            $upd = $pdo->prepare(
                'UPDATE activities SET position = ? WHERE id = ? AND competence_unit_id = ?'
            );
            foreach ($ids as $pos => $aid) {
                $upd->execute([$pos, $aid, $cuId]);
            }
            return true;
        });
    }
}
