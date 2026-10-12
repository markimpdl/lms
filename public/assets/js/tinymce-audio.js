/**
 * Botão "Áudio" dos editores TinyMCE (conteúdo da CU, lição, atividade).
 *
 * Igual ao picker de imagem: não há upload direto no editor. O professor sobe
 * o MP3 nos anexos do conteúdo da CU e aqui escolhe um deles; o editor insere
 * um `<audio controls>` apontando pra rota autenticada de view do anexo.
 *
 * Os dados vêm de `window.lmsAudioPicker = { items, labels }`, emitido pelo
 * partial `tinymce_audio.php`. Uso no `setup` do `tinymce.init`:
 *   lmsRegisterAudioButton(editor);
 * e `audioinsert` no toolbar.
 */
(function () {
    'use strict';

    window.lmsRegisterAudioButton = function (editor) {
        // Sem o partial não há rótulos traduzidos: melhor não ter botão.
        var cfg = window.lmsAudioPicker;
        if (!cfg) return;
        var labels = cfg.labels;
        var items  = cfg.items || [];

        editor.ui.registry.addButton('audioinsert', {
            text: labels.button,
            tooltip: labels.title,
            onAction: function () {
                if (!items.length) {
                    editor.windowManager.alert(labels.none);
                    return;
                }
                editor.windowManager.open({
                    title: labels.title,
                    body: {
                        type: 'panel',
                        items: [{
                            type: 'selectbox',
                            name: 'src',
                            label: labels.field,
                            items: items.map(function (a) {
                                return { text: a.title, value: a.value };
                            })
                        }]
                    },
                    buttons: [
                        { type: 'cancel', text: labels.cancel },
                        { type: 'submit', text: labels.insert, primary: true }
                    ],
                    onSubmit: function (api) {
                        var src = api.getData().src;
                        if (src) {
                            editor.insertContent(
                                '<p><audio controls preload="metadata" src="' +
                                editor.dom.encode(src) + '"></audio></p>'
                            );
                        }
                        api.close();
                    }
                });
            }
        });
    };
})();
