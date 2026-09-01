<?php
declare(strict_types=1);

/**
 * Trilha de uma CU em curso V2 (E36-03).
 *
 * A trilha eh a sequencia navegavel dentro da Unidade: licoes e exercicios
 * intercalados numa ordem unica, com a avaliacao sempre fechando o percurso.
 *
 * **Como a ordem unica funciona.** Nao ha tabela polimorfica: `lessons.position`
 * e `activities.position` compartilham o MESMO espaco de numeracao dentro da CU,
 * e a trilha eh um UNION ALL ordenado por position. Duas consequencias:
 *
 *  1. Todo item novo (licao OU atividade) em curso V2 tem de pegar a posicao
 *     por `nextPosition()`, que faz MAX sobre as DUAS tabelas. Usar so o MAX da
 *     propria tabela geraria empate de position e ordem indefinida.
 *  2. O reorder reescreve as posicoes 1..N das duas tabelas numa transacao, o
 *     que mantem a numeracao densa e sem colisao.
 *
 * A avaliacao NAO entra na numeracao: eh 1 por CU (ADR-007) e sempre ultima,
 * entao a posicao dela eh implicita.
 *
 * Em curso V1 nada disso roda — nao existem licoes, e `Activity::moveUp/moveDown`
 * continua servindo a reordenacao classica.
 */
final class UnitTrackService
{
    /**
     * Proxima posicao livre da CU, considerando licoes E atividades.
     *
     * Comeca em 1 (nao 0) pra a numeracao bater com o que o professor ve na
     * tela. `GREATEST` com COALESCE nos dois lados porque uma CU pode ter so
     * licoes, so atividades, ou nenhuma das duas.
     */
    public static function nextPosition(int $cuId): int
    {
        $stmt = Database::pdo()->prepare(
            'SELECT GREATEST(
                        COALESCE((SELECT MAX(position) FROM lessons    WHERE competence_unit_id = ?), 0),
                        COALESCE((SELECT MAX(position) FROM activities WHERE competence_unit_id = ?), 0)
                    ) + 1'
        );
        $stmt->execute([$cuId, $cuId]);
        return max(1, (int) $stmt->fetchColumn());
    }

    /**
     * Trilha ordenada da CU: licoes + atividades por `position`, e a avaliacao
     * (se existir) sempre no fim.
     *
     * Desempate por `type` e depois `id` pra a ordem ser estavel mesmo se duas
     * linhas empatarem em position (dado legado, ou reorder interrompido).
     *
     * @param bool $publishedOnly true na visao do aluno — licao rascunho nao
     *                            aparece. Atividade e avaliacao nao tem flag de
     *                            publicacao, entram sempre.
     * @return list<array{type:string, id:int, title:string, position:int, published:int, xp_value:int}>
     */
    public static function forCu(int $cuId, bool $publishedOnly = false): array
    {
        $sql = "SELECT 'lesson' AS type, id, title, position, published, xp_value
                  FROM lessons
                 WHERE competence_unit_id = :cu1"
             . ($publishedOnly ? ' AND published = 1' : '')
             . " UNION ALL
                SELECT 'activity' AS type, id, title, position, 1 AS published, xp_value
                  FROM activities
                 WHERE competence_unit_id = :cu2
                 ORDER BY position ASC, type ASC, id ASC";

        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute([':cu1' => $cuId, ':cu2' => $cuId]);

        $items = [];
        foreach ($stmt->fetchAll() as $r) {
            $items[] = [
                'type'      => (string) $r['type'],
                'id'        => (int)    $r['id'],
                'title'     => (string) $r['title'],
                'position'  => (int)    $r['position'],
                'published' => (int)    $r['published'],
                'xp_value'  => (int)    $r['xp_value'],
            ];
        }

        // Avaliacao fecha a trilha. Fora do UNION porque nao participa da
        // numeracao — a posicao dela eh "sempre a ultima", nao um numero.
        $ev = Database::pdo()->prepare(
            'SELECT id, title, xp_value FROM evaluations WHERE competence_unit_id = ? LIMIT 1'
        );
        $ev->execute([$cuId]);
        $evaluation = $ev->fetch();
        if ($evaluation !== false) {
            $items[] = [
                'type'      => 'evaluation',
                'id'        => (int)    $evaluation['id'],
                'title'     => (string) $evaluation['title'],
                'position'  => PHP_INT_MAX,
                'published' => 1,
                'xp_value'  => (int)    $evaluation['xp_value'],
            ];
        }

        return $items;
    }

    /**
     * Trilha da CU na visao do ALUNO: so itens publicados, cada um ja com o
     * estado de conclusao dele e o link pra abrir.
     *
     * Duas queries no total (licoes concluidas + entregas de atividade), nao
     * uma por item — a timeline renderiza N itens sem N+1.
     *
     * `done` por tipo:
     *  - licao      -> existe linha em lesson_completions
     *  - exercicio  -> existe entrega em activity_submissions
     *  - avaliacao  -> existe submissao com nota >= 6 (mesmo criterio de
     *                  aprovacao que StudentProgress usa)
     *
     * @return list<array{type:string,id:int,title:string,done:bool,href:string,xp_value:int}>
     */
    public static function forStudentCu(int $cuId, int $studentId): array
    {
        $items = self::forCu($cuId, true);
        if ($items === []) {
            return [];
        }

        $lessonsDone = LessonCompletion::lessonIdsForStudentInCu($cuId, $studentId);

        // Entregas de atividade + aprovacao da avaliacao, numa query so.
        $stmt = Database::pdo()->prepare(
            "SELECT 'activity' AS type, a.id
               FROM activities a
               JOIN activity_submissions s
                 ON s.activity_id = a.id AND s.student_user_id = :sid1
              WHERE a.competence_unit_id = :cu1
              UNION ALL
             SELECT 'evaluation' AS type, e.id
               FROM evaluations e
               JOIN evaluation_submissions es
                 ON es.evaluation_id = e.id AND es.student_user_id = :sid2
              WHERE e.competence_unit_id = :cu2
                AND es.grade IS NOT NULL
                AND es.grade >= 6.0"
        );
        $stmt->execute([':sid1' => $studentId, ':cu1' => $cuId, ':sid2' => $studentId, ':cu2' => $cuId]);

        $othersDone = [];
        foreach ($stmt->fetchAll() as $r) {
            $othersDone[$r['type'] . ':' . (int) $r['id']] = true;
        }

        $out = [];
        foreach ($items as $item) {
            $key  = $item['type'] . ':' . $item['id'];
            $done = $item['type'] === 'lesson'
                ? isset($lessonsDone[$item['id']])
                : isset($othersDone[$key]);

            $href = match ($item['type']) {
                'lesson'   => '/student/lesson/' . $item['id'],
                'activity' => '/student/activity/' . $item['id'],
                default    => '/student/evaluation/' . $item['id'],
            };

            $out[] = [
                'type'     => $item['type'],
                'id'       => $item['id'],
                'title'    => $item['title'],
                'done'     => $done,
                'href'     => $href,
                'xp_value' => $item['xp_value'],
            ];
        }
        return $out;
    }

    /**
     * Primeiro item ainda nao concluido da trilha — o destino do botao
     * "Comecar"/"Continuar" na capa da CU. Se o aluno concluiu tudo, devolve o
     * ultimo item (reabrir o fim eh mais util que nao ter para onde ir).
     *
     * @param list<array<string,mixed>> $studentTrack retorno de forStudentCu
     * @return array<string,mixed>|null
     */
    public static function resumePoint(array $studentTrack): ?array
    {
        foreach ($studentTrack as $item) {
            if (!$item['done']) {
                return $item;
            }
        }
        return $studentTrack === [] ? null : $studentTrack[count($studentTrack) - 1];
    }

    /**
     * Item anterior e proximo de um item da trilha — alimenta os botoes
     * "Anterior"/"Proximo" do aluno.
     *
     * @return array{prev: ?array<string,mixed>, next: ?array<string,mixed>}
     */
    public static function neighbors(int $cuId, string $type, int $id, bool $publishedOnly = true): array
    {
        $items = self::forCu($cuId, $publishedOnly);
        foreach ($items as $i => $item) {
            if ($item['type'] === $type && $item['id'] === $id) {
                return [
                    'prev' => $items[$i - 1] ?? null,
                    'next' => $items[$i + 1] ?? null,
                ];
            }
        }
        return ['prev' => null, 'next' => null];
    }

    /**
     * Reescreve a ordem da trilha. `$ordered` eh a lista na ordem desejada,
     * cada item `['type' => 'lesson'|'activity', 'id' => int]`.
     *
     * Ignora a avaliacao caso o caller a envie — ela nao tem position.
     *
     * Valida que TODO id pertence a esta CU antes de escrever qualquer coisa:
     * um id forjado no POST nao pode reposicionar item de outra unidade. Se
     * qualquer id nao bater, nada eh gravado.
     *
     * @param list<array{type:string,id:int}> $ordered
     * @return 'ok'|'not_found'|'course_archived'|'invalid'
     */
    public static function reorder(int $cuId, int $tenantId, array $ordered): string
    {
        return Database::tx(static function (PDO $pdo) use ($cuId, $tenantId, $ordered): string {
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

            // Ids que realmente pertencem a CU, por tipo.
            $owned = ['lesson' => [], 'activity' => []];
            foreach (['lesson' => 'lessons', 'activity' => 'activities'] as $type => $table) {
                $q = $pdo->prepare("SELECT id FROM {$table} WHERE competence_unit_id = ?");
                $q->execute([$cuId]);
                foreach ($q->fetchAll() as $r) {
                    $owned[$type][(int) $r['id']] = true;
                }
            }

            $clean = [];
            foreach ($ordered as $item) {
                $type = (string) ($item['type'] ?? '');
                $id   = (int)    ($item['id']   ?? 0);
                if ($type === 'evaluation') {
                    continue;
                }
                if (!isset($owned[$type][$id])) {
                    return 'invalid';
                }
                $clean[] = ['type' => $type, 'id' => $id];
            }

            // A lista tem de cobrir a trilha inteira. Um POST parcial deixaria
            // itens fora com position velha, colidindo com a numeracao nova.
            if (count($clean) !== count($owned['lesson']) + count($owned['activity'])) {
                return 'invalid';
            }

            $updLesson   = $pdo->prepare('UPDATE lessons    SET position = ? WHERE id = ? AND competence_unit_id = ?');
            $updActivity = $pdo->prepare('UPDATE activities SET position = ? WHERE id = ? AND competence_unit_id = ?');

            $pos = 1;
            foreach ($clean as $item) {
                $stmtUpd = $item['type'] === 'lesson' ? $updLesson : $updActivity;
                $stmtUpd->execute([$pos, $item['id'], $cuId]);
                $pos++;
            }

            return 'ok';
        });
    }
}
