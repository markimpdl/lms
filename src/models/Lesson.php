<?php
declare(strict_types=1);

/**
 * Model de Licao (E36-03) — as telas da trilha de uma CU em curso V2.
 *
 * Sem `tenant_id` proprio, igual `contents` e `activities`: o tenant deriva da
 * cadeia CU -> CC -> course.tenant_id. Por isso toda query aqui faz o JOIN ate
 * `courses` com `c.tenant_id = ?` — eh o que isola o professor, e nao pode ser
 * omitido em nenhum metodo novo.
 *
 * `html` chega aqui JA sanitizado por `ContentSanitizer::purify()` — o model
 * nao sanitiza, igual `Content::upsertForCu`. Quem grava HTML cru aqui abre um
 * XSS; a responsabilidade fica na pagina, junto do TinyMCE.
 *
 * `position` compartilha o espaco de numeracao com `activities.position` na
 * mesma CU — ver `UnitTrackService`.
 */
final class Lesson
{
    /**
     * Retorna a licao se pertence ao tenant, ou null.
     * @return array<string,mixed>|null
     */
    public static function findForTenant(int $id, int $tenantId): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT l.id, l.competence_unit_id, l.title, l.html, l.xp_value,
                    l.published, l.position, l.created_at, l.updated_at,
                    cu.name AS cu_name, cc.id AS cc_id, cc.course_id,
                    c.archived AS course_archived, c.structure_version
               FROM lessons l
               JOIN competence_units  cu ON cu.id = l.competence_unit_id
               JOIN core_competencies cc ON cc.id = cu.core_competency_id
               JOIN courses c ON c.id = cc.course_id AND c.tenant_id = ?
              WHERE l.id = ?
              LIMIT 1'
        );
        $stmt->execute([$tenantId, $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Igual a `findForTenant`, mas pra o ALUNO: valida matricula ativa no curso
     * e exige licao publicada. Nunca devolve rascunho.
     * @return array<string,mixed>|null
     */
    public static function findForStudent(int $id, int $studentId): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT l.id, l.competence_unit_id, l.title, l.html, l.xp_value,
                    l.published, l.position,
                    cu.name AS cu_name, cc.id AS cc_id, cc.course_id,
                    c.name AS course_name, c.structure_version
               FROM lessons l
               JOIN competence_units  cu ON cu.id = l.competence_unit_id
               JOIN core_competencies cc ON cc.id = cu.core_competency_id
               JOIN courses c    ON c.id = cc.course_id
               JOIN enrollments e ON e.course_id = c.id AND e.student_user_id = ?
              WHERE l.id = ?
                AND l.published = 1
              LIMIT 1'
        );
        $stmt->execute([$studentId, $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** course_id da CU dona da licao, sem filtro de tenant (pra resolver autoria). */
    public static function courseIdOf(int $lessonId): ?int
    {
        $stmt = Database::pdo()->prepare(
            'SELECT cc.course_id
               FROM lessons l
               JOIN competence_units  cu ON cu.id = l.competence_unit_id
               JOIN core_competencies cc ON cc.id = cu.core_competency_id
              WHERE l.id = ?
              LIMIT 1'
        );
        $stmt->execute([$lessonId]);
        $v = $stmt->fetchColumn();
        return $v === false ? null : (int) $v;
    }

    /**
     * Licoes da CU em ordem. `$publishedOnly` pra visao do aluno.
     * @return list<array<string,mixed>>
     */
    public static function listByCu(int $cuId, int $tenantId, bool $publishedOnly = false): array
    {
        $sql = 'SELECT l.id, l.title, l.xp_value, l.published, l.position
                  FROM lessons l
                  JOIN competence_units  cu ON cu.id = l.competence_unit_id
                  JOIN core_competencies cc ON cc.id = cu.core_competency_id
                  JOIN courses c ON c.id = cc.course_id AND c.tenant_id = ?
                 WHERE l.competence_unit_id = ?'
             . ($publishedOnly ? ' AND l.published = 1' : '')
             . ' ORDER BY l.position ASC, l.id ASC';

        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute([$tenantId, $cuId]);
        return $stmt->fetchAll();
    }

    /**
     * Cria a licao no fim da trilha. Retorna o id, ou 'not_found' /
     * 'course_archived'.
     *
     * A posicao vem de `UnitTrackService::nextPosition` — MAX sobre licoes E
     * atividades. Dentro da mesma transacao pra dois professores criando ao
     * mesmo tempo nao pegarem a mesma posicao.
     *
     * @param array{title:string, html:string, xp_value:int, published:bool} $data
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

            $pos = UnitTrackService::nextPosition($cuId);

            $ins = $pdo->prepare(
                'INSERT INTO lessons
                    (competence_unit_id, title, html, xp_value, published, position)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $ins->execute([
                $cuId,
                $data['title'],
                $data['html'],
                $data['xp_value'],
                $data['published'] ? 1 : 0,
                $pos,
            ]);
            return (int) $pdo->lastInsertId();
        });
    }

    /**
     * Atualiza a licao. Retorna 'ok', 'not_found' ou 'course_archived'.
     *
     * `position` NAO esta no SET: a ordem eh responsabilidade exclusiva de
     * `UnitTrackService::reorder`, que reescreve a trilha inteira de uma vez.
     * Mexer na posicao por aqui furaria a numeracao densa.
     *
     * @param array{title:string, html:string, xp_value:int, published:bool} $data
     */
    public static function update(int $id, int $tenantId, array $data): string
    {
        $lesson = self::findForTenant($id, $tenantId);
        if ($lesson === null) {
            return 'not_found';
        }
        if ((int) $lesson['course_archived'] === 1) {
            return 'course_archived';
        }

        $stmt = Database::pdo()->prepare(
            'UPDATE lessons
                SET title = ?, html = ?, xp_value = ?, published = ?
              WHERE id = ?'
        );
        $stmt->execute([
            $data['title'],
            $data['html'],
            $data['xp_value'],
            $data['published'] ? 1 : 0,
            $id,
        ]);
        return 'ok';
    }

    /**
     * Quantos alunos ja concluiram a licao — o handler de delete usa pra
     * avisar o professor antes de apagar.
     */
    public static function countCompletions(int $lessonId): int
    {
        $stmt = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM lesson_completions WHERE lesson_id = ?'
        );
        $stmt->execute([$lessonId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Apaga a licao. `$expectedTitle` eh a confirmacao digitada pelo professor
     * (mesmo padrao de `Activity::delete`) — evita apagar a linha errada.
     *
     * `lesson_completions` cai por CASCADE. Os `xp_events` da licao NAO tem FK
     * (source_type/source_id sao polimorficos, ADR-020), entao sao removidos
     * aqui explicitamente — senao o XP sobreviveria a licao apagada e o
     * ranking ficaria com pontos de conteudo inexistente.
     *
     * @return array{status:string}
     */
    public static function delete(int $id, int $tenantId, string $expectedTitle): array
    {
        $lesson = self::findForTenant($id, $tenantId);
        if ($lesson === null) {
            return ['status' => 'not_found'];
        }
        if ((int) $lesson['course_archived'] === 1) {
            return ['status' => 'course_archived'];
        }
        if (trim($expectedTitle) !== trim((string) $lesson['title'])) {
            return ['status' => 'name_mismatch'];
        }

        return Database::tx(static function (PDO $pdo) use ($id): array {
            $pdo->prepare("DELETE FROM xp_events WHERE source_type = 'lesson' AND source_id = ?")
                ->execute([$id]);
            $pdo->prepare('DELETE FROM lessons WHERE id = ?')->execute([$id]);
            return ['status' => 'ok'];
        });
    }
}
