<?php
declare(strict_types=1);

/**
 * Modelo de `quiz_questions` (E20-01). Cada questão pertence a um quiz
 * e tem texto + peso DECIMAL(4,2) + posição. Cascade do schema apaga
 * suas opções automaticamente.
 *
 * Validação de soma de pesos = 10.00 vive no controller que coordena
 * o save bulk (E20-02). O CHECK no schema só limita 0-10 por questão
 * individual.
 */
final class QuizQuestion
{
    public static function listForQuiz(int $quizId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT id, quiz_id, text, weight, position
               FROM quiz_questions
              WHERE quiz_id = ?
              ORDER BY position ASC, id ASC'
        );
        $stmt->execute([$quizId]);
        return $stmt->fetchAll();
    }

    public static function create(int $quizId, string $text, float $weight, int $position): int
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO quiz_questions (quiz_id, text, weight, position)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$quizId, $text, $weight, $position]);
        return (int) Database::pdo()->lastInsertId();
    }

    public static function deleteAllForQuiz(int $quizId): int
    {
        $stmt = Database::pdo()->prepare(
            'DELETE FROM quiz_questions WHERE quiz_id = ?'
        );
        $stmt->execute([$quizId]);
        return $stmt->rowCount();
    }
}
