/**
 * Importador de markdown pros editores TinyMCE (lição e conteúdo/capa da CU).
 *
 * O professor cola markdown (tipicamente saída de um LLM) num modal, a gente
 * converte pra HTML aqui no browser e insere no editor. O conteúdo continua
 * sendo gravado como HTML — o ContentSanitizer no POST segue sendo a rede de
 * segurança, nada muda no backend nem no schema.
 *
 * A conversão não é markdown puro: o HTML de saída é normalizado pra allowlist
 * do ContentSanitizer, senão o professor veria no editor uma formatação que
 * some ao salvar:
 *   - títulos viram h2..h4 (h1/h5/h6 não estão na allowlist);
 *   - blocos de código viram <pre class="language-x">, o mesmo formato que o
 *     plugin `codesample` do TinyMCE gera (e que o CSS do aluno já estiliza);
 *   - checkbox de task list vira ☐/☑ em texto (<input> é removido no save).
 *
 * Depende de `marked` (CDN) já carregado na página.
 */
(function () {
    'use strict';

    /**
     * Linguagem do fence → uma das 5 do `codesample_languages`. Qualquer outra
     * (ou nenhuma) vira <pre> simples, sem classe: o Prism ignora e o CSS cai
     * no estilo claro de `pre:not([class*="language-"])`.
     */
    var CODE_LANGS = {
        python: 'python', py: 'python', python3: 'python',
        csharp: 'csharp', cs: 'csharp', dotnet: 'csharp',
        javascript: 'javascript', js: 'javascript', jsx: 'javascript',
        typescript: 'javascript', ts: 'javascript', node: 'javascript',
        html: 'markup', xml: 'markup', markup: 'markup', svg: 'markup',
        css: 'css', scss: 'css', sass: 'css'
    };

    function each(nodeList, fn) {
        Array.prototype.forEach.call(nodeList, fn);
    }

    function headingLevel(el) {
        return parseInt(el.tagName.substring(1), 10);
    }

    /**
     * h1..h6 → h2..h4 preservando a hierarquia: o menor nível presente no texto
     * colado vira h2, o seguinte h3, e daí pra baixo tudo satura em h4. Assim um
     * markdown que começa em `#` e outro que começa em `##` chegam os dois com o
     * título de topo em h2, sem achatar as seções internas.
     */
    function normalizeHeadings(doc) {
        var headings = doc.body.querySelectorAll('h1,h2,h3,h4,h5,h6');
        if (headings.length === 0) {
            return;
        }
        var min = 6;
        each(headings, function (h) { min = Math.min(min, headingLevel(h)); });

        each(headings, function (h) {
            var target = Math.min(4, 2 + (headingLevel(h) - min));
            if (target === headingLevel(h)) {
                return;
            }
            var fresh = doc.createElement('h' + target);
            fresh.innerHTML = h.innerHTML;
            h.parentNode.replaceChild(fresh, h);
        });
    }

    /** <pre><code class="language-x"> → <pre class="language-x"> (formato do codesample). */
    function normalizeCodeBlocks(doc) {
        each(doc.body.querySelectorAll('pre > code'), function (code) {
            var pre   = code.parentNode;
            var match = /(?:^|\s)language-([\w#+.-]+)/.exec(code.className || '');
            var lang  = match ? CODE_LANGS[match[1].toLowerCase()] : null;

            var fresh = doc.createElement('pre');
            if (lang) {
                fresh.className = 'language-' + lang;
            }
            fresh.textContent = code.textContent;
            pre.parentNode.replaceChild(fresh, pre);
        });
    }

    /** Task list do GFM: <input type=checkbox> não sobrevive ao sanitizador. */
    function normalizeTaskLists(doc) {
        each(doc.body.querySelectorAll('li input[type="checkbox"]'), function (box) {
            // Sem espaco no fim: o proprio marked emite um espaco depois do <input>.
            var mark = doc.createTextNode(box.hasAttribute('checked') ? '☑' : '☐');
            box.parentNode.replaceChild(mark, box);
        });
    }

    /**
     * Converte markdown em HTML pronto pro editor. Lança Error('marked-unavailable')
     * se o CDN do `marked` não carregou.
     */
    function toHtml(markdown) {
        if (typeof markdown !== 'string' || markdown.trim() === '') {
            return '';
        }
        if (!window.marked || typeof window.marked.parse !== 'function') {
            throw new Error('marked-unavailable');
        }

        var raw = window.marked.parse(markdown, { gfm: true, breaks: false, async: false });

        // DOMParser produz um documento inerte: script não roda e <img src> não
        // dispara request — ao contrário de jogar o HTML num innerHTML qualquer.
        var doc = new DOMParser().parseFromString(raw, 'text/html');

        normalizeHeadings(doc);
        normalizeCodeBlocks(doc);
        normalizeTaskLists(doc);

        return doc.body.innerHTML.trim();
    }

    /**
     * Liga o modal ao editor.
     *
     * @param {{modalId: string, editorId: string, labels: {empty: string, unavailable: string}}} options
     */
    function init(options) {
        var modal = document.getElementById(options.modalId);
        if (modal === null) {
            return;
        }

        var source    = modal.querySelector('.js-md-source');
        var replaceEl = modal.querySelector('.js-md-replace');
        var errorEl   = modal.querySelector('.js-md-error');
        var insertBtn = modal.querySelector('.js-md-insert');
        if (source === null || insertBtn === null) {
            return;
        }

        function showError(message) {
            if (errorEl === null) {
                return;
            }
            errorEl.textContent = message;
            errorEl.classList.remove('d-none');
        }

        function hideError() {
            if (errorEl !== null) {
                errorEl.classList.add('d-none');
            }
        }

        insertBtn.addEventListener('click', function () {
            hideError();

            var editor = window.tinymce ? tinymce.get(options.editorId) : null;
            if (!editor) {
                showError(options.labels.unavailable);
                return;
            }

            var html;
            try {
                html = toHtml(source.value);
            } catch (e) {
                showError(options.labels.unavailable);
                return;
            }
            if (html === '') {
                showError(options.labels.empty);
                return;
            }

            if (replaceEl !== null && replaceEl.checked) {
                editor.setContent(html);
            } else {
                editor.insertContent(html);
            }

            source.value = '';
            if (replaceEl !== null) {
                replaceEl.checked = false;
            }

            if (window.bootstrap) {
                bootstrap.Modal.getOrCreateInstance(modal).hide();
            }
        });

        modal.addEventListener('hidden.bs.modal', hideError);
    }

    window.LmsMarkdownImport = { toHtml: toHtml, init: init };
})();
