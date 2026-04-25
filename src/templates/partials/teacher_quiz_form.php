<?php
declare(strict_types=1);

/**
 * Partial: Form do quiz pro professor (E20-02). Lista dinâmica de
 * questões + opções via Alpine.js. Validação client-side da soma de
 * pesos = 10.00 (UX); server-side estrita em TeacherQuizController.
 *
 * Espera no escopo:
 *   $formAction   string — URL do POST
 *   $cancelUrl    string — URL do botão Cancelar
 *   $errors       array<string,string>
 *   $ownerName    string — nome da activity/evaluation pai
 *   $showAnswers  bool   — flag atual do quiz
 *   $questions    list<array> — payload pra inicializar Alpine.js (formato detalhado abaixo)
 *
 * `$questions` é uma lista de objetos PHP convertidos pra JSON e
 * injetados como state inicial do Alpine. Cada questão tem:
 *   { text:string, weight:float, correct_idx:int, options:[{text:string}] }
 */

$initialQuestionsJson = json_encode($questions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
?>
<div class="row justify-content-center">
    <div class="col-12 col-lg-10">
        <h1 class="h4 mb-3"><?= e(__t('quiz.form.title', ['name' => $ownerName])) ?></h1>
        <p class="text-muted small mb-3"><?= e(__t('quiz.form.subtitle')) ?></p>

        <?php if ($errors !== []): ?>
            <div class="alert alert-danger" role="alert">
                <?= e(__t('quiz.form.has_errors')) ?>
                <?php if (isset($errors['weight_sum'])): ?>
                    <div class="small mt-1"><?= e(__t($errors['weight_sum'])) ?></div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="<?= e($formAction) ?>" novalidate
              x-data='quizForm(<?= htmlspecialchars($initialQuestionsJson, ENT_QUOTES, 'UTF-8') ?>, <?= $showAnswers ? 'true' : 'false' ?>)'>
            <?= csrf_field() ?>

            <div class="card card-body shadow-sm mb-3">
                <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                    <div>
                        <strong><?= e(__t('quiz.form.weight_sum_label')) ?>:</strong>
                        <span x-text="weightSum.toFixed(2)"
                              :class="weightSumOk ? 'text-success fw-bold' : 'text-danger fw-bold'"></span>
                        / 10.00
                    </div>
                    <div class="form-check m-0">
                        <input class="form-check-input" type="checkbox" id="f-show-answers"
                               name="show_answers" value="1" x-model="showAnswers">
                        <label class="form-check-label" for="f-show-answers">
                            <?= e(__t('quiz.form.show_answers')) ?>
                        </label>
                    </div>
                </div>
                <div x-show="!weightSumOk" class="text-danger small mt-2"
                     x-transition.opacity>
                    <?= e(__t('quiz.form.weight_sum_warning')) ?>
                </div>
            </div>

            <template x-for="(q, qIdx) in questions" :key="q.uid">
                <div class="card card-body shadow-sm mb-3">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <strong><?= e(__t('quiz.form.question_label')) ?> <span x-text="qIdx + 1"></span></strong>
                        <button type="button" class="btn btn-sm btn-outline-danger"
                                @click="removeQuestion(qIdx)"
                                x-show="questions.length > 1">
                            <?= e(__t('quiz.form.remove_question')) ?>
                        </button>
                    </div>

                    <div class="row g-2 mb-2">
                        <div class="col-12 col-md-9">
                            <label class="form-label small mb-1">
                                <?= e(__t('quiz.form.question_text')) ?>
                            </label>
                            <textarea class="form-control" rows="2" required maxlength="1000"
                                      :name="`questions[${qIdx}][text]`" x-model="q.text"></textarea>
                        </div>
                        <div class="col-12 col-md-3">
                            <label class="form-label small mb-1">
                                <?= e(__t('quiz.form.question_weight')) ?>
                            </label>
                            <input type="number" class="form-control" required
                                   step="0.25" min="0" max="10"
                                   :name="`questions[${qIdx}][weight]`"
                                   x-model.number="q.weight">
                        </div>
                    </div>

                    <label class="form-label small mb-1">
                        <?= e(__t('quiz.form.options_label')) ?>
                    </label>
                    <template x-for="(opt, oIdx) in q.options" :key="opt.uid">
                        <div class="d-flex gap-2 mb-2 align-items-center">
                            <div class="form-check m-0">
                                <input type="radio" class="form-check-input" required
                                       :name="`questions[${qIdx}][correct]`"
                                       :value="oIdx"
                                       :checked="q.correct_idx === oIdx"
                                       @change="q.correct_idx = oIdx">
                            </div>
                            <input type="text" class="form-control" required maxlength="500"
                                   :placeholder="`Opção ${oIdx + 1}`"
                                   :name="`questions[${qIdx}][options][${oIdx}][text]`"
                                   x-model="opt.text">
                            <button type="button" class="btn btn-sm btn-outline-danger"
                                    @click="removeOption(qIdx, oIdx)"
                                    x-show="q.options.length > 2"
                                    aria-label="Remover opção">
                                ×
                            </button>
                        </div>
                    </template>

                    <button type="button" class="btn btn-sm btn-outline-secondary mt-1"
                            @click="addOption(qIdx)">
                        + <?= e(__t('quiz.form.add_option')) ?>
                    </button>
                </div>
            </template>

            <div class="d-flex flex-wrap gap-2 mb-3">
                <button type="button" class="btn btn-outline-primary" @click="addQuestion()">
                    + <?= e(__t('quiz.form.add_question')) ?>
                </button>
            </div>

            <div class="d-flex flex-wrap gap-2">
                <button type="submit" class="btn btn-primary btn-lg" :disabled="!weightSumOk">
                    <?= e(__t('common.save')) ?>
                </button>
                <a href="<?= e($cancelUrl) ?>" class="btn btn-outline-secondary btn-lg">
                    <?= e(__t('common.cancel')) ?>
                </a>
            </div>
        </form>
    </div>
</div>

<script>
// Alpine.js: state e ações do form de quiz. Inicializado com as questões
// existentes (vazio quando criando). Todos os UIDs locais (não persistem).
function quizForm(initialQuestions, showAnswers) {
    function uid() {
        return 'u' + Math.random().toString(36).slice(2) + Date.now().toString(36);
    }
    function makeOption(text) {
        return { uid: uid(), text: text || '' };
    }
    function makeQuestion(text, weight, correctIdx, options) {
        var opts = (options && options.length >= 2)
            ? options.map(function (o) { return makeOption(o.text); })
            : [makeOption(''), makeOption('')];
        return {
            uid: uid(),
            text: text || '',
            weight: typeof weight === 'number' ? weight : 1.0,
            correct_idx: typeof correctIdx === 'number' ? correctIdx : 0,
            options: opts
        };
    }

    var seed = (initialQuestions && initialQuestions.length > 0)
        ? initialQuestions.map(function (q) {
            return makeQuestion(q.text, q.weight, q.correct_idx, q.options);
        })
        : [makeQuestion('', 1.0, 0, [{text:''},{text:''}])];

    return {
        questions: seed,
        showAnswers: !!showAnswers,
        get weightSum() {
            return this.questions.reduce(function (sum, q) {
                var w = parseFloat(q.weight);
                return sum + (isNaN(w) ? 0 : w);
            }, 0);
        },
        get weightSumOk() {
            return Math.abs(this.weightSum - 10) < 0.001;
        },
        addQuestion: function () {
            this.questions.push(makeQuestion('', 1.0, 0, [{text:''},{text:''}]));
        },
        removeQuestion: function (qIdx) {
            if (this.questions.length > 1) this.questions.splice(qIdx, 1);
        },
        addOption: function (qIdx) {
            this.questions[qIdx].options.push(makeOption(''));
        },
        removeOption: function (qIdx, oIdx) {
            var q = this.questions[qIdx];
            if (q.options.length <= 2) return;
            q.options.splice(oIdx, 1);
            if (q.correct_idx >= q.options.length) q.correct_idx = 0;
        }
    };
}
</script>
