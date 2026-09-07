/**
 * Modo foco do aluno (E36) — maximizar/restaurar o conteudo do curso V2.
 *
 * Alterna `lms-focus` no <body>: a sidebar vira rail de icones e a grid
 * solta o max-width, dando largura horizontal pro conteudo sem tirar a
 * trilha da direita. Todo o desenho esta em student-area.css.
 *
 * O estado persiste em localStorage pra acompanhar o aluno enquanto ele
 * anda pela trilha (licao -> exercicio -> licao) sem ter de reapertar o
 * botao em cada tela. O layout aplica a classe num script inline no topo do
 * <body> em TODA tela do aluno, entao aqui so tratamos o clique e a sincronia
 * dos botoes.
 *
 * Ha dois toggles e o script trata os dois igual: o "maximizar" no conteudo
 * (so nas telas de curso V2) e o "restaurar" no fim do rail, que existe em
 * qualquer tela do aluno — sem ele o aluno ficaria preso no modo compacto ao
 * sair da trilha.
 *
 * localStorage pode lancar (modo privativo, cookies bloqueados) — por isso
 * todo acesso vai em try/catch e o modo foco degrada pra "so nesta pagina".
 */
(function () {
    'use strict';

    var KEY = 'lms-focus';

    function readStored() {
        try {
            return window.localStorage.getItem(KEY) === '1';
        } catch (e) {
            return false;
        }
    }

    function writeStored(on) {
        try {
            window.localStorage.setItem(KEY, on ? '1' : '0');
        } catch (e) {
            /* sem persistencia — o modo vale so nesta pagina */
        }
    }

    function syncButton(btn, on) {
        var label = on
            ? (btn.dataset.labelOn || '')
            : (btn.dataset.labelOff || '');
        btn.setAttribute('aria-pressed', on ? 'true' : 'false');
        if (label !== '') {
            btn.setAttribute('title', label);
            btn.setAttribute('aria-label', label);
        }
        var icon = btn.querySelector('i');
        if (icon) {
            icon.classList.toggle('bi-arrows-angle-contract', on);
            icon.classList.toggle('bi-arrows-angle-expand', !on);
        }
    }

    /** @return {boolean} true se achou botao e ligou os handlers. */
    function init() {
        var buttons = document.querySelectorAll('[data-lms-focus-toggle]');
        if (buttons.length === 0) {
            return false;
        }

        var on = document.body.classList.contains('lms-focus') || readStored();
        document.body.classList.toggle('lms-focus', on);

        buttons.forEach(function (btn) {
            syncButton(btn, on);
            btn.addEventListener('click', function () {
                on = !document.body.classList.contains('lms-focus');
                document.body.classList.toggle('lms-focus', on);
                writeStored(on);
                buttons.forEach(function (b) { syncButton(b, on); });
            });
        });

        return true;
    }

    // A tag roda no fim do <body> e SEM defer, entao os botoes ja estao no DOM
    // e a sincronia acontece antes do primeiro paint. O fallback so existe pro
    // caso de alguem mover a tag pra cima.
    if (!init() && document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    }
})();
