<?php
declare(strict_types=1);

/**
 * Modelo de `quizzes` (E20-01). Quiz polimórfico 1:1 com activity ou
 * evaluation via `(owner_type, owner_id)`. Pesos das questões somam
 * exatamente 10.00 (validação no controller, não no banco — CHECK só
 * limita 0-10 por questão).
 *
 * Spec: doc/15-roadmap-pos-mvp.md F8.
 */
final class Quiz
{
    /**
     * Busca quiz pelo dono (atividade ou avaliação) dentro do tenant.
     * @return array<string,mixed>|null
     */
    public static function findByOwner(string $ownerType, int $ownerId, int $tenantId): ?array
    {
        if ($ownerType !== 'activity' && $ownerType !== 'evaluation') {
            return null;
        }
        if ($ownerId <= 0 || $tenantId <= 0) {
            return null;
        }
        $stmt = Database::pdo()->prepare(
            'SELECT id, tenant_id, owner_type, owner_id, show_answers, created_at, updated_at
               FROM quizzes
              WHERE owner_type = ? AND owner_id = ? AND tenant_id = ?
              LIMIT 1'
        );
        $stmt->execute([$ownerType, $ownerId, $tenantId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public static function findById(int $quizId, int $tenantId): ?array
    {
        if ($quizId <= 0 || $tenantId <= 0) {
            return null;
        }
        $stmt = Database::pdo()->prepare(
            'SELECT id, tenant_id, owner_type, owner_id, show_answers, created_at, updated_at
               FROM quizzes
              WHERE id = ? AND tenant_id = ?
              LIMIT 1'
        );
        $stmt->execute([$quizId, $tenantId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public static function create(
        int $tenantId,
        string $ownerType,
        int $ownerId,
        bool $showAnswers = false
    ): int {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO quizzes (tenant_id, owner_type, owner_id, show_answers)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$tenantId, $ownerType, $ownerId, $showAnswers ? 1 : 0]);
        return (int) Database::pdo()->lastInsertId();
    }

    /**
     * Apaga o quiz preso a um owner, SEM filtro de tenant. Só pra chamar logo
     * após criar ou apagar a activity/evaluation dona.
     *
     * Quiz é polimórfico (sem FK pro dono), e o MariaDB da hospedagem reaproveita
     * o id de activity apagada (AUTO_INCREMENT volta a MAX+1 no restart). O quiz
     * que sobrou casava com a atividade nova: a cópia de CU estourava
     * uk_quiz_owner e a tela de quiz abria com as questões da atividade antiga.
     * Owner recém-criado não tem quiz legítimo; o que existir é resto — de
     * qualquer tenant, por isso o filtro não cabe aqui.
     */
    public static function purgeForOwner(PDO $pdo, string $ownerType, int $ownerId): void
    {
        $pdo->prepare('DELETE FROM quizzes WHERE owner_type = ? AND owner_id = ?')
            ->execute([$ownerType, $ownerId]);
    }

    /**
     * Apaga todo quiz cujo dono não existe mais. Chamado depois de exclusões em
     * cascata (CU, CC, curso), que levam activities/evaluations pelo schema mas
     * não alcançam `quizzes`. Só toca linha órfã, então não precisa de tenant.
     */
    public static function purgeOrphans(PDO $pdo): void
    {
        $pdo->exec(
            "DELETE q FROM quizzes q
               LEFT JOIN activities  a ON q.owner_type = 'activity'   AND a.id = q.owner_id
               LEFT JOIN evaluations e ON q.owner_type = 'evaluation' AND e.id = q.owner_id
              WHERE a.id IS NULL AND e.id IS NULL"
        );
    }

    public static function updateShowAnswers(int $quizId, int $tenantId, bool $showAnswers): bool
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE quizzes SET show_answers = ?
              WHERE id = ? AND tenant_id = ?'
        );
        $stmt->execute([$showAnswers ? 1 : 0, $quizId, $tenantId]);
        return $stmt->rowCount() > 0;
    }

    public static function delete(int $quizId, int $tenantId): bool
    {
        // Cascade do schema apaga questões + opções automaticamente.
        $stmt = Database::pdo()->prepare(
            'DELETE FROM quizzes WHERE id = ? AND tenant_id = ?'
        );
        $stmt->execute([$quizId, $tenantId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Estrutura completa do quiz com questões + opções, ordenadas por
     * `position`. Usado por: page do professor (E20-02 render),
     * page do aluno (E20-03 render), buildQuizSnapshot (E20-04).
     *
     * @return array{
     *   id:int, tenant_id:int, owner_type:string, owner_id:int, show_answers:int,
     *   questions: list<array{id:int, text:string, weight:float, position:int,
     *                          options: list<array{id:int, text:string, is_correct:int, position:int}>}>
     * }|null
     */
    public static function findFullById(int $quizId, int $tenantId): ?array
    {
        $quiz = self::findById($quizId, $tenantId);
        if ($quiz === null) {
            return null;
        }

        $stmt = Database::pdo()->prepare(
            'SELECT id, text, weight, position
               FROM quiz_questions
              WHERE quiz_id = ?
              ORDER BY position ASC, id ASC'
        );
        $stmt->execute([$quizId]);
        $questionRows = $stmt->fetchAll();

        $questions = [];
        foreach ($questionRows as $qr) {
            $qId = (int) $qr['id'];
            $optStmt = Database::pdo()->prepare(
                'SELECT id, text, is_correct, position
                   FROM quiz_options
                  WHERE question_id = ?
                  ORDER BY position ASC, id ASC'
            );
            $optStmt->execute([$qId]);
            $optRows = $optStmt->fetchAll();
            $options = [];
            foreach ($optRows as $or) {
                $options[] = [
                    'id'         => (int)    $or['id'],
                    'text'       => (string) $or['text'],
                    'is_correct' => (int)    $or['is_correct'],
                    'position'   => (int)    $or['position'],
                ];
            }
            $questions[] = [
                'id'       => $qId,
                'text'     => (string) $qr['text'],
                'weight'   => (float)  $qr['weight'],
                'position' => (int)    $qr['position'],
                'options'  => $options,
            ];
        }

        return [
            'id'           => (int) $quiz['id'],
            'tenant_id'    => (int) $quiz['tenant_id'],
            'owner_type'   => (string) $quiz['owner_type'],
            'owner_id'     => (int) $quiz['owner_id'],
            'show_answers' => (int) $quiz['show_answers'],
            'questions'    => $questions,
        ];
    }
}
