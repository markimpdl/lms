<?php
declare(strict_types=1);

/**
 * Conclusao de licao pelo aluno (E36-05).
 *
 * O aluno marca a licao como concluida explicitamente (decisao do PO): so
 * abrir nao conta. A PK composta (lesson_id, student_user_id) da a
 * idempotencia — clicar duas vezes nao duplica nem falha.
 *
 * O XP correspondente vive em `xp_events` (source_type='lesson') e eh
 * creditado em paralelo por `XpEvents::awardLesson`, que tambem eh idempotente
 * pela UK composite. As duas tabelas podem ser escritas na mesma requisicao
 * sem risco de divergir por clique repetido.
 */
final class LessonCompletion
{
    /**
     * Ids das licoes que o aluno ja concluiu DENTRO de uma CU. Uma query por
     * render — a timeline consulta o set em memoria, sem N+1 por item.
     *
     * @return array<int,true> mapa lesson_id => true
     */
    public static function lessonIdsForStudentInCu(int $cuId, int $studentId): array
    {
        if ($cuId <= 0 || $studentId <= 0) {
            return [];
        }
        $stmt = Database::pdo()->prepare(
            'SELECT lc.lesson_id
               FROM lesson_completions lc
               JOIN lessons l ON l.id = lc.lesson_id
              WHERE lc.student_user_id = ?
                AND l.competence_unit_id = ?'
        );
        $stmt->execute([$studentId, $cuId]);

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(int) $row['lesson_id']] = true;
        }
        return $out;
    }

    /** Marca a licao como concluida. Idempotente via PK composta. */
    public static function complete(int $lessonId, int $studentId): void
    {
        Database::pdo()
            ->prepare(
                'INSERT IGNORE INTO lesson_completions (lesson_id, student_user_id)
                 VALUES (?, ?)'
            )
            ->execute([$lessonId, $studentId]);
    }

    /** Desfaz a conclusao. O XP eh revogado pelo caller, nao aqui. */
    public static function uncomplete(int $lessonId, int $studentId): void
    {
        Database::pdo()
            ->prepare('DELETE FROM lesson_completions WHERE lesson_id = ? AND student_user_id = ?')
            ->execute([$lessonId, $studentId]);
    }

    public static function isComplete(int $lessonId, int $studentId): bool
    {
        $stmt = Database::pdo()->prepare(
            'SELECT 1 FROM lesson_completions WHERE lesson_id = ? AND student_user_id = ? LIMIT 1'
        );
        $stmt->execute([$lessonId, $studentId]);
        return $stmt->fetchColumn() !== false;
    }

    /** Quantas licoes publicadas da CU o aluno concluiu — usado no progresso. */
    public static function countDoneInCu(int $cuId, int $studentId): int
    {
        $stmt = Database::pdo()->prepare(
            'SELECT COUNT(*)
               FROM lesson_completions lc
               JOIN lessons l ON l.id = lc.lesson_id
              WHERE lc.student_user_id = ?
                AND l.competence_unit_id = ?
                AND l.published = 1'
        );
        $stmt->execute([$studentId, $cuId]);
        return (int) $stmt->fetchColumn();
    }
}
