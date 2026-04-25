<?php
declare(strict_types=1);

/**
 * Controller do CRUD do Quiz pro professor (E20-02). Coordena validação
 * e save bulk das questões + opções dentro de uma transação. Tenant
 * vem sempre de `current_tenant_id()` — nunca do input.
 *
 * Owner é polimórfico: 'activity' ou 'evaluation'. Validação de ownership
 * delega pra `Activity::findForTenant` / `Evaluation::findForTenant`.
 */
final class TeacherQuizController
{
    public const MIN_QUESTIONS = 1;
    public const MIN_OPTIONS_PER_QUESTION = 2;
    public const WEIGHT_SUM_TARGET = 10.00;
    public const WEIGHT_SUM_TOLERANCE = 0.001; // float comparison

    /**
     * Valida o owner (activity/evaluation) + retorna os dados básicos pra
     * renderizar o form. Garante que owner é tipo='quiz' (rejeita se for
     * 'projeto'/'codigo').
     *
     * @return array{owner: array<string,mixed>, owner_name: string, course_id: int}|null
     */
    public static function loadOwner(string $ownerType, int $ownerId, int $tenantId): ?array
    {
        if ($ownerType === 'activity') {
            $activity = Activity::findForTenant($ownerId, $tenantId);
            if ($activity === null) {
                return null;
            }
            if ((string) ($activity['type'] ?? '') !== 'quiz') {
                return null; // não é quiz — não tem o que editar
            }
            return [
                'owner'      => $activity,
                'owner_name' => (string) $activity['title'],
                'course_id'  => (int) $activity['course_id'],
            ];
        }
        if ($ownerType === 'evaluation') {
            $evaluation = Evaluation::findForTenant($ownerId, $tenantId);
            if ($evaluation === null) {
                return null;
            }
            if ((string) ($evaluation['type'] ?? '') !== 'quiz') {
                return null;
            }
            return [
                'owner'      => $evaluation,
                'owner_name' => (string) $evaluation['title'],
                'course_id'  => (int) $evaluation['course_id'],
            ];
        }
        return null;
    }

    /**
     * Valida o input do form. Retorna `['errors' => map, 'data' => parsed]`
     * onde `data['questions']` é uma lista de `{text, weight, correct_idx,
     * options: [{text, is_correct}]}` pronta pro save.
     *
     * @param array<string,mixed> $input — payload de $_POST['questions']
     * @return array{errors: array<string,string>, data: array{show_answers: bool, questions: list<array<string,mixed>>}}
     */
    public static function validate(array $input): array
    {
        $errors = [];
        $showAnswers = !empty($input['show_answers']);

        $rawQuestions = $input['questions'] ?? [];
        if (!is_array($rawQuestions) || count($rawQuestions) < self::MIN_QUESTIONS) {
            return [
                'errors' => ['questions' => 'quiz.form.err.no_questions'],
                'data'   => ['show_answers' => $showAnswers, 'questions' => []],
            ];
        }

        $parsed = [];
        $weightSum = 0.0;
        foreach (array_values($rawQuestions) as $qIdx => $q) {
            if (!is_array($q)) {
                continue;
            }
            $text   = trim((string) ($q['text'] ?? ''));
            $weight = (float) ($q['weight'] ?? 0);
            $correct = (int) ($q['correct'] ?? -1);

            if ($text === '' || mb_strlen($text) > 1000) {
                $errors['question_' . $qIdx . '_text'] = 'quiz.form.err.question_text';
            }
            if ($weight < 0 || $weight > 10) {
                $errors['question_' . $qIdx . '_weight'] = 'quiz.form.err.question_weight_range';
            }

            $rawOpts = $q['options'] ?? [];
            $opts = [];
            if (is_array($rawOpts)) {
                foreach (array_values($rawOpts) as $oIdx => $opt) {
                    if (!is_array($opt)) {
                        continue;
                    }
                    $optText = trim((string) ($opt['text'] ?? ''));
                    if ($optText === '' || mb_strlen($optText) > 500) {
                        $errors['question_' . $qIdx . '_option_' . $oIdx] = 'quiz.form.err.option_text';
                    }
                    $opts[] = [
                        'text'       => $optText,
                        'is_correct' => $oIdx === $correct,
                    ];
                }
            }
            if (count($opts) < self::MIN_OPTIONS_PER_QUESTION) {
                $errors['question_' . $qIdx . '_options'] = 'quiz.form.err.min_options';
            }
            if ($correct < 0 || $correct >= count($opts)) {
                $errors['question_' . $qIdx . '_correct'] = 'quiz.form.err.correct_required';
            }

            $weightSum += $weight;
            $parsed[] = [
                'text'        => $text,
                'weight'      => $weight,
                'correct_idx' => $correct,
                'options'     => $opts,
            ];
        }

        if (abs($weightSum - self::WEIGHT_SUM_TARGET) > self::WEIGHT_SUM_TOLERANCE) {
            $errors['weight_sum'] = 'quiz.form.err.weight_sum';
        }

        return [
            'errors' => $errors,
            'data'   => ['show_answers' => $showAnswers, 'questions' => $parsed],
        ];
    }

    /**
     * Save bulk: REPLACE estilo (apaga questões antigas + insere novas).
     * Cascade do schema apaga as opções automaticamente. Se o quiz não
     * existir, cria. Roda em transação. Idempotente.
     *
     * @param array{show_answers: bool, questions: list<array<string,mixed>>} $data
     * @return int quizId
     */
    public static function saveBulk(string $ownerType, int $ownerId, int $tenantId, array $data): int
    {
        return Database::tx(static function () use ($ownerType, $ownerId, $tenantId, $data): int {
            $existing = Quiz::findByOwner($ownerType, $ownerId, $tenantId);
            if ($existing === null) {
                $quizId = Quiz::create($tenantId, $ownerType, $ownerId, $data['show_answers']);
            } else {
                $quizId = (int) $existing['id'];
                Quiz::updateShowAnswers($quizId, $tenantId, $data['show_answers']);
                QuizQuestion::deleteAllForQuiz($quizId);
            }

            foreach ($data['questions'] as $qIdx => $q) {
                $questionId = QuizQuestion::create(
                    $quizId,
                    (string) $q['text'],
                    (float)  $q['weight'],
                    (int)    $qIdx
                );
                foreach ($q['options'] as $oIdx => $opt) {
                    QuizOption::create(
                        $questionId,
                        (string) $opt['text'],
                        (bool)   $opt['is_correct'],
                        (int)    $oIdx
                    );
                }
            }
            return $quizId;
        });
    }
}
