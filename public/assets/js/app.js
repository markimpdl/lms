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

    // WAF da hospedagem (mod_security) devolve 406 antes do app quando o texto
    // tem cara de SQL, contas com `=`, certas sequencias de tags, arabe com
    // harakat... e a lista so cresce. No envio de todo form POST, os campos de
    // texto viram um unico `__waf` = base64(querystring urlencoded), opaco pro
    // WAF; src/bootstrap.php reconstroi $_POST. Arquivos seguem no multipart.
    // Opt-out: atributo data-no-waf-encode no form. Sem JS, envio normal.
    document.addEventListener('formdata', function (event) {
        var form = event.target;
        if (!form || form.tagName !== 'FORM') return;
        if ((form.getAttribute('method') || 'get').toLowerCase() !== 'post') return;
        if (form.hasAttribute('data-no-waf-encode')) return;

        var fd = event.formData;
        if (fd.has('__waf')) return;
        var params = new URLSearchParams();
        var names = [];
        fd.forEach(function (value, name) {
            if (typeof value !== 'string') return;
            params.append(name, value);
            if (names.indexOf(name) === -1) names.push(name);
        });
        if (names.length === 0) return;
        for (var i = 0; i < names.length; i++) {
            // delete remove todas as entradas do nome; arquivos com o mesmo
            // nome nao existem (campo de arquivo tem nome proprio).
            fd.delete(names[i]);
        }
        // URLSearchParams ja percent-encoda tudo (saida ASCII) — btoa direto.
        fd.append('__waf', window.btoa(params.toString()));
    });

    // Tabela do conteúdo rico (lição, capa, enunciado) com texto que não
    // quebra — ex.: caminho longo em `code` — fica mais larga que o cartão e
    // vaza por cima da coluna ao lado. O invólucro rola na horizontal só
    // quando precisa; tabela que cabe continua igual. Feito aqui porque o
    // HTML vem do TinyMCE/import e não passa por template.
    var tables = document.querySelectorAll('.unit-prose table');
    for (var t = 0; t < tables.length; t++) {
        var table = tables[t];
        if (table.parentElement && table.parentElement.classList.contains('lms-table-scroll')) continue;
        var wrap = document.createElement('div');
        wrap.className = 'lms-table-scroll';
        table.parentNode.insertBefore(wrap, table);
        wrap.appendChild(table);
    }
    // Caminho em `code` dentro da célula (`Deliverables/project/x.sql`) só
    // quebra no hífen, e a coluna dele espreme as outras. `<wbr>` depois de
    // cada `/` deixa quebrar como caminho, e a tabela volta a caber sem rolar.
    var codes = document.querySelectorAll('.unit-prose td code, .unit-prose th code');
    for (var c = 0; c < codes.length; c++) {
        var code = codes[c];
        if (code.children.length > 0 || code.textContent.indexOf('/') === -1) continue;
        var parts = code.textContent.split('/');
        code.textContent = '';
        for (var p = 0; p < parts.length; p++) {
            if (p > 0) {
                code.appendChild(document.createTextNode('/'));
                code.appendChild(document.createElement('wbr'));
            }
            if (parts[p] !== '') code.appendChild(document.createTextNode(parts[p]));
        }
    }

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
