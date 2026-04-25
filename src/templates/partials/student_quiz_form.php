<?php
declare(strict_types=1);

/**
 * Partial: Form de quiz pro aluno (E20-03). Renderiza questões + opções
 * com radios. Quando $readOnly=true, mostra o snapshot já submetido
 * (sem permitir mudanças); gabarito condicional fica em E20-05.
 *
 * Espera no escopo:
 *   $fullQuiz       array — Quiz::findFullById ou estrutura do snapshot
 *                   (mesma forma: questions[].options[])
 *   $previousAnswers array<int,int> — [question_id => option_id] pra
 *                    pré-marcar radios em modo edição (re-render após erro
 *                    de validação) ou pra mostrar a escolha do aluno em
 *                    modo readOnly
 *   $errors         array<string,string> — chaves 'question_<id>' e 'general'
 *   $readOnly       bool — se true, sem inputs habilitados, sem submit
 *   $formAction     string|null — URL do POST (null em readOnly)
 *   $titleText      string — título do quiz (vem da activity/evaluation)
 */
?>
<div class="card card-body shadow-sm">
    <?php if ($errors !== []): ?>
        <div class="alert alert-danger" role="alert">
            <?= e(__t('quiz.student.has_errors')) ?>
        </div>
    <?php endif; ?>

    <?php if ($readOnly): ?>
        <div class="alert alert-info" role="alert">
            <?= e(__t('quiz.student.already_submitted')) ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="<?= e((string) ($formAction ?? '')) ?>" novalidate>
        <?php if (!$readOnly): ?>
            <?= csrf_field() ?>
        <?php endif; ?>

        <?php foreach ($fullQuiz['questions'] ?? [] as $qIdx => $q): ?>
            <?php
                $qId = (int) ($q['id'] ?? 0);
                $errKey = 'question_' . $qId;
                $hasErr = isset($errors[$errKey]);
                $picked = $previousAnswers[$qId] ?? null;
            ?>
            <div class="lms-quiz-question mb-4 <?= $hasErr ? 'is-invalid' : '' ?>">
                <div class="d-flex align-items-baseline gap-2 mb-2">
                    <span class="lms-quiz-question__num"><?= (int) $qIdx + 1 ?>.</span>
                    <strong class="lms-quiz-question__text"><?= e((string) ($q['text'] ?? '')) ?></strong>
                </div>
                <div class="lms-quiz-question__weight small text-muted mb-2">
                    <?= e(__t('quiz.student.weight_label', ['weight' => number_format((float) ($q['weight'] ?? 0), 2, ',', '')])) ?>
                </div>

                <?php
                    // E20-05: gabarito condicional. Só renderiza marcadores
                    // de correta/errada quando readOnly + show_answers=1.
                    $showAnswers = $readOnly && (int) ($fullQuiz['show_answers'] ?? 0) === 1;
                ?>
                <div class="lms-quiz-options">
                    <?php foreach ($q['options'] ?? [] as $oIdx => $opt): ?>
                        <?php
                            $oId       = (int) ($opt['id'] ?? 0);
                            $optText   = (string) ($opt['text'] ?? '');
                            $isPicked  = ($picked !== null && (int) $picked === $oId);
                            $isCorrect = (int) ($opt['is_correct'] ?? 0) === 1;
                            $inputId   = 'q' . $qId . '_o' . $oId;

                            $optClass = 'form-check mb-1 lms-quiz-option';
                            if ($showAnswers) {
                                if ($isCorrect) {
                                    $optClass .= ' lms-quiz-option--correct';
                                } elseif ($isPicked && !$isCorrect) {
                                    $optClass .= ' lms-quiz-option--wrong';
                                }
                            }
                        ?>
                        <div class="<?= e($optClass) ?>">
                            <input type="radio"
                                   class="form-check-input"
                                   name="answers[<?= $qId ?>]"
                                   value="<?= $oId ?>"
                                   id="<?= e($inputId) ?>"
                                   <?= $isPicked ? 'checked' : '' ?>
                                   <?= $readOnly ? 'disabled' : 'required' ?>>
                            <label class="form-check-label" for="<?= e($inputId) ?>">
                                <?= e($optText) ?>
                                <?php if ($showAnswers && $isCorrect): ?>
                                    <span class="lms-quiz-option__badge lms-quiz-option__badge--correct">
                                        ✓ <?= e(__t('quiz.student.gabarito.correct')) ?>
                                    </span>
                                <?php elseif ($showAnswers && $isPicked && !$isCorrect): ?>
                                    <span class="lms-quiz-option__badge lms-quiz-option__badge--wrong">
                                        ✗ <?= e(__t('quiz.student.gabarito.your_wrong')) ?>
                                    </span>
                                <?php endif; ?>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php if ($hasErr): ?>
                    <div class="text-danger small mt-1">
                        <?= e(__t($errors[$errKey])) ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>

        <?php if (!$readOnly): ?>
            <div class="d-flex flex-wrap gap-2 mt-3">
                <button type="submit" class="btn btn-primary btn-lg">
                    <?= e(__t('quiz.student.submit')) ?>
                </button>
            </div>
        <?php endif; ?>
    </form>
</div>
