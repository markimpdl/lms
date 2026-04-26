<?php
declare(strict_types=1);

/**
 * Learning Outcomes por CU (E25 — F16). Cada UC em curso `grading_mode='learning_outcomes'`
 * tem **exatamente 5** LOs cadastrados pelo professor; a avaliação é nota 0-10
 * por LO + média = nota final em `evaluation_submissions.grade`.
 *
 * Disponibilidade restrita a tenants Actvet (gate na UI + defesa server-side
 * em `Course`/handlers). Este model é puro acesso a dados — não verifica
 * tenant nem grading_mode.
 */
final class LearningOutcome
{
    /**
     * Lista os LOs de uma CU em ordem (`position ASC`). Retorna [] quando
     * não há nenhum cadastrado (curso virou LO depois de criar UCs).
     *
     * @return list<array{id:int, cu_id:int, description:string, position:int}>
     */
    public static function findByCu(int $cuId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT id, cu_id, description, position
               FROM learning_outcomes
              WHERE cu_id = ?
              ORDER BY position ASC, id ASC'
        );
        $stmt->execute([$cuId]);
        $rows = [];
        foreach ($stmt->fetchAll() as $r) {
            $rows[] = [
                'id'          => (int)    $r['id'],
                'cu_id'       => (int)    $r['cu_id'],
                'description' => (string) $r['description'],
                'position'    => (int)    $r['position'],
            ];
        }
        return $rows;
    }

    /**
     * Substitui atomicamente os LOs de uma CU pelos descriptions fornecidos
     * (em ordem — position = 1..N). Caller valida que são exatamente 5
     * (regra única do épico). DELETE + N×INSERT em transação.
     *
     * Cuidado: o DELETE cascateia pra `evaluation_submission_lo_grades` —
     * se já houver feedback gravado, as notas são apagadas. Caller deve
     * gatear isso quando aplicável (no MVP, replaceForCu só é chamado
     * antes de qualquer feedback existir, ou intencionalmente pelo prof).
     *
     * @param list<string> $descriptions ordem importa (posição 1..N)
     */
    public static function replaceForCu(int $cuId, array $descriptions): void
    {
        Database::tx(static function (PDO $pdo) use ($cuId, $descriptions): void {
            $pdo->prepare('DELETE FROM learning_outcomes WHERE cu_id = ?')
                ->execute([$cuId]);

            $insert = $pdo->prepare(
                'INSERT INTO learning_outcomes (cu_id, description, position)
                      VALUES (?, ?, ?)'
            );
            $position = 1;
            foreach ($descriptions as $desc) {
                $insert->execute([$cuId, (string) $desc, $position]);
                $position++;
            }
        });
    }

    /**
     * Conveniência pra UI: quantos LOs estão cadastrados na CU. Pra
     * mostrar badge "X/5" na tela de cadastro.
     */
    public static function countByCu(int $cuId): int
    {
        $stmt = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM learning_outcomes WHERE cu_id = ?'
        );
        $stmt->execute([$cuId]);
        return (int) $stmt->fetchColumn();
    }
}
