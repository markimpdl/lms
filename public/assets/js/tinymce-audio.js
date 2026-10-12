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
        var cfg    = window.lmsAudioPicker || { items: [], labels: {} };
        var labels = cfg.labels || {};
        var items  = cfg.items || [];

        editor.ui.registry.addButton('audioinsert', {
            text: labels.button || 'Audio',
            tooltip: labels.title || 'Audio',
            onAction: function () {
                if (!items.length) {
                    editor.windowManager.alert(labels.none || '');
                    return;
                }
                editor.windowManager.open({
                    title: labels.title || 'Audio',
                    body: {
                        type: 'panel',
                        items: [{
                            type: 'selectbox',
                            name: 'src',
                            label: labels.field || 'MP3',
                            items: items.map(function (a) {
                                return { text: a.title, value: a.value };
                            })
                        }]
                    },
                    buttons: [
                        { type: 'cancel', text: labels.cancel || 'Cancel' },
                        { type: 'submit', text: labels.insert || 'Insert', primary: true }
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
