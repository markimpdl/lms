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

    // Tabela do conteúdo rico (lição, capa, enunciado de atividade e de
    // avaliação, telas do professor — tudo sob `.content-render`) com texto
    // que não quebra — ex.: caminho longo em `code` — fica mais larga que o
    // cartão e vaza por cima da coluna ao lado. Feito aqui porque o HTML vem
    // do TinyMCE/import e não passa por template.
    //
    // 1º: caminho em `code` na célula (`Deliverables/project/x.sql`) ganha
    // `<wbr>` depois de cada `/`, pra quebrar como caminho. Só em texto sem
    // espaço e comprido (não parte `a / b` nem `x/2`), e nunca em código que
    // o Prism vai reescrever (ele reconstrói pelo textContent).
    var codes = document.querySelectorAll('.content-render td code, .content-render th code');
    for (var c = 0; c < codes.length; c++) {
        var code = codes[c];
        var txt = code.textContent;
        if (code.children.length > 0 || txt.length < 16 || /\s/.test(txt)
            || txt.indexOf('/') === -1 || code.closest('[class*="language-"]')) continue;
        var parts = txt.split('/');
        code.textContent = '';
        for (var p = 0; p < parts.length; p++) {
            if (p > 0) {
                code.appendChild(document.createTextNode('/'));
                code.appendChild(document.createElement('wbr'));
            }
            if (parts[p] !== '') code.appendChild(document.createTextNode(parts[p]));
        }
    }
    // 2º: rede de segurança — a tabela que ainda não couber rola dentro de
    // um invólucro em vez de vazar. `overflow-x` inline porque as telas do
    // professor não carregam o student-area.css. O invólucro que de fato
    // rola vira focável, senão quem navega por teclado não alcança as
    // colunas escondidas.
    var tables = document.querySelectorAll('.content-render table');
    for (var t = 0; t < tables.length; t++) {
        var table = tables[t];
        if (table.parentElement && table.parentElement.classList.contains('lms-table-scroll')) continue;
        var wrap = document.createElement('div');
        wrap.className = 'lms-table-scroll';
        wrap.style.overflowX = 'auto';
        table.parentNode.insertBefore(wrap, table);
        wrap.appendChild(table);
        if (wrap.scrollWidth > wrap.clientWidth) {
            wrap.tabIndex = 0;
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
