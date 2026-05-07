/**
 * LMS — script global de UX.
 *
 * Loading overlay no submit (#XXX): aluno estava clicando em "Enviar" varias
 * vezes e gerando submissoes duplicadas no servidor. Soluçao opt-out: todo
 * <form> recebe overlay full-screen + spinner ao submeter, exceto quando:
 *   - outro listener chamou event.preventDefault() (ex: data-confirm cancelado)
 *   - o form tem atributo data-no-loading
 *
 * Texto do overlay vem de body[data-submitting-label] (i18n via PHP).
 */
(function () {
    'use strict';

    var overlay = null;
    var overlayShowTimer = null;

    function ensureOverlay() {
        if (overlay) return overlay;
        overlay = document.createElement('div');
        overlay.className = 'lms-loading-overlay';
        overlay.setAttribute('aria-hidden', 'true');
        var panel = document.createElement('div');
        panel.className = 'lms-loading-overlay__panel';
        panel.setAttribute('role', 'status');
        panel.setAttribute('aria-live', 'polite');
        var spinner = document.createElement('div');
        spinner.className = 'lms-loading-overlay__spinner';
        var label = document.createElement('span');
        label.className = 'lms-loading-overlay__label';
        label.textContent = document.body.dataset.submittingLabel || 'Submitting...';
        panel.appendChild(spinner);
        panel.appendChild(label);
        overlay.appendChild(panel);
        document.body.appendChild(overlay);
        return overlay;
    }

    function showOverlay() {
        var el = ensureOverlay();
        // Reflow pra transition pegar
        void el.offsetWidth;
        el.classList.add('is-visible');
        el.setAttribute('aria-hidden', 'false');
    }

    function hideOverlay() {
        if (overlayShowTimer) {
            window.clearTimeout(overlayShowTimer);
            overlayShowTimer = null;
        }
        if (!overlay) return;
        overlay.classList.remove('is-visible');
        overlay.setAttribute('aria-hidden', 'true');
    }

    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!form || form.tagName !== 'FORM') return;
        // Respeita preventDefault de outros listeners (data-confirm cancelado,
        // validação HTML5, Alpine bloqueando, etc.)
        if (event.defaultPrevented) return;
        if (form.hasAttribute('data-no-loading')) return;

        // Desabilita o submitter pra bloquear duplo-clique antes do overlay
        // aparecer. setTimeout(0) garante que o navegador já montou o request
        // (se desabilitarmos sincronamente, alguns browsers omitem o
        // name=value do botão submit do payload).
        var submitter = event.submitter;
        if (submitter && !submitter.disabled) {
            window.setTimeout(function () {
                submitter.disabled = true;
                submitter.setAttribute('aria-busy', 'true');
            }, 0);
        }

        // Delay de 150ms evita flash do overlay em redirects instantaneos.
        overlayShowTimer = window.setTimeout(showOverlay, 150);
    }, false);

    // BFCache (back button): restaura estado quando o navegador "ressuscita"
    // a pagina cacheada — overlay e botões ficariam travados sem isso.
    window.addEventListener('pageshow', function (event) {
        if (!event.persisted) return;
        hideOverlay();
        var busy = document.querySelectorAll('[aria-busy="true"]');
        for (var i = 0; i < busy.length; i++) {
            busy[i].disabled = false;
            busy[i].removeAttribute('aria-busy');
        }
    });
})();
