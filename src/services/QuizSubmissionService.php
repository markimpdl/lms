<?php
declare(strict_types=1);

/**
 * Cálculo + snapshot de submissões de quiz (E20-03 + E20-04).
 *
 * Funções puras (sem efeito colateral, sem leitura de DB exceto onde
 * indicado). Usadas pelos handlers do aluno em activity/quiz e
 * evaluation/quiz pra validar respostas, calcular nota e construir
 * o JSON snapshot persistido em `*_submissions.quiz_snapshot`.
 *
 * Estrutura do snapshot (write-once, persistido como JSON):
 * {
 *   quiz_id: int,
 *   show_answers: int,
 *   questions: [
 *     {id, text, weight, options: [{id, text, is_correct}]},
 *     ...
 *   ],
 *   answers: { question_id: option_id, ... }
 * }
 *
 * Cálculo da nota: soma dos pesos das questões cuja opção marcada
 * tem `is_correct=1` no snapshot. Soma máx = 10.00 (validado no form
 * do professor em E20-02). Sem questão respondida = 0 pra essa questão.
 */
final class QuizSubmissionService
{
    /**
     * Valida respostas contra a estrutura do quiz. Retorna mapa normalizado
     * `[question_id => option_id]` (todos ints) + erros por questão.
     *
     * @param array<string,mixed> $fullQuiz estrutura de Quiz::findFullById
     * @param array<int|string,mixed> $rawAnswers dado cru de $_POST['answers'] (chaves podem ser string)
     * @return array{errors: array<string,string>, answers: array<int,int>}
     */
    public static function validateAnswers(array $fullQuiz, array $rawAnswers): array
    {
        $errors  = [];
        $answers = [];

        foreach (($fullQuiz['questions'] ?? []) as $q) {
            $qId = (int) ($q['id'] ?? 0);
            if ($qId <= 0) {
                continue;
            }
            $raw = $rawAnswers[$qId] ?? $rawAnswers[(string) $qId] ?? null;
            if ($raw === null || $raw === '') {
                $errors['question_' . $qId] = 'quiz.student.err.unanswered';
                continue;
            }
            $optId = (int) $raw;
            $validOptIds = array_map(static fn ($o): int => (int) ($o['id'] ?? 0), $q['options'] ?? []);
            if (!in_array($optId, $validOptIds, true)) {
                $errors['question_' . $qId] = 'quiz.student.err.invalid_option';
                continue;
            }
            $answers[$qId] = $optId;
        }
        return ['errors' => $errors, 'answers' => $answers];
    }

    /**
     * Monta o snapshot JSON (estrutura completa do quiz no momento +
     * respostas do aluno). Persistido em `*_submissions.quiz_snapshot`.
     *
     * @param array<string,mixed> $fullQuiz estrutura de Quiz::findFullById
     * @param array<int,int> $answers normalizado de validateAnswers
     */
    public static function buildSnapshot(array $fullQuiz, array $answers): array
    {
        $questions = [];
        foreach (($fullQuiz['questions'] ?? []) as $q) {
            $opts = [];
            foreach ($q['options'] ?? [] as $o) {
                $opts[] = [
                    'id'         => (int) ($o['id'] ?? 0),
                    'text'       => (string) ($o['text'] ?? ''),
                    'is_correct' => (int) ($o['is_correct'] ?? 0),
                ];
            }
            $questions[] = [
                'id'      => (int) ($q['id'] ?? 0),
                'text'    => (string) ($q['text'] ?? ''),
                'weight'  => (float) ($q['weight'] ?? 0),
                'options' => $opts,
            ];
        }
        return [
            'quiz_id'      => (int) ($fullQuiz['id'] ?? 0),
            'show_answers' => (int) ($fullQuiz['show_answers'] ?? 0),
            'questions'    => $questions,
            'answers'      => $answers, // map [question_id => option_id]
        ];
    }

    /**
     * Calcula nota baseada no snapshot. Itera questões; pra cada uma,
     * busca a opção marcada e soma o peso se ela for is_correct=1.
     *
     * Retorna 0.0-10.0 com 1 casa decimal (ADR-015). Sem resposta = 0.
     */
    public static function calculateGrade(array $snapshot): float
    {
        $sum = 0.0;
        $answers = $snapshot['answers'] ?? [];
        foreach ($snapshot['questions'] ?? [] as $q) {
            $qId      = (int) ($q['id'] ?? 0);
            $answered = $answers[$qId] ?? $answers[(string) $qId] ?? null;
            if ($answered === null) {
                continue;
            }
            $answeredId = (int) $answered;
            foreach ($q['options'] ?? [] as $o) {
                if ((int) ($o['id'] ?? 0) === $answeredId && (int) ($o['is_correct'] ?? 0) === 1) {
                    $sum += (float) ($q['weight'] ?? 0);
                    break;
                }
            }
        }
        // Clamp 0..10 + 1 decimal (ADR-015).
        $sum = max(0.0, min(10.0, $sum));
        return round($sum, 1);
    }
}
