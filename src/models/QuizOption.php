<?php
declare(strict_types=1);

/**
 * Modelo de `quiz_options` (E20-01). Cada opção pertence a uma questão
 * e tem texto + flag is_correct + posição. MVP: 1 correta por questão
 * (radio button no aluno) — validação no controller.
 */
final class QuizOption
{
    public static function listForQuestion(int $questionId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT id, question_id, text, is_correct, position
               FROM quiz_options
              WHERE question_id = ?
              ORDER BY position ASC, id ASC'
        );
        $stmt->execute([$questionId]);
        return $stmt->fetchAll();
    }

    public static function create(int $questionId, string $text, bool $isCorrect, int $position): int
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO quiz_options (question_id, text, is_correct, position)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$questionId, $text, $isCorrect ? 1 : 0, $position]);
        return (int) Database::pdo()->lastInsertId();
    }
}
