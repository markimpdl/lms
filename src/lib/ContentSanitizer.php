<?php
declare(strict_types=1);

/**
 * Sanitizador de HTML de conteúdo da CU (E5-01). Wrapper fino sobre a
 * biblioteca ezyang/htmlpurifier.
 *
 * Allowlist estrita (OWASP): tags de texto, títulos, listas, links,
 * tabelas, imagens e iframe restrito ao embed de YouTube e Vimeo
 * (E5-02). Remove silenciosamente qualquer tag/atributo fora da
 * allowlist e event handlers (`onclick`, `onerror`, etc.).
 *
 * Cache de config serializada em `storage/cache/htmlpurifier/` para não
 * reconstruir a config a cada request (é custoso).
 *
 * ⚠ O nome desta classe NÃO pode colidir com `HTMLPurifier` da lib, pois
 * PHP trata nomes de classe como case-insensitive e o Composer autoload
 * (registrado antes do autoload do projeto) carrega a lib primeiro —
 * qualquer wrapper chamado `HtmlPurifier` seria sombreado pela lib.
 */
final class ContentSanitizer
{
    private static ?HTMLPurifier $purifier = null;

    /**
     * Aplica a sanitização no HTML de entrada. Retorna HTML limpo pronto
     * pra gravar no banco e renderizar depois com `{!! $html !!}` (sem
     * escape adicional — o Purifier já garante).
     */
    public static function purify(string $dirtyHtml): string
    {
        return self::instance()->purify($dirtyHtml);
    }

    private static function instance(): HTMLPurifier
    {
        if (self::$purifier !== null) {
            return self::$purifier;
        }

        $config = HTMLPurifier_Config::createDefault();

        $cacheDir = LMS_ROOT . '/storage/cache/htmlpurifier';
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0775, true);
        }
        $config->set('Cache.SerializerPath', $cacheDir);

        $config->set('Core.Encoding', 'UTF-8');
        $config->set('HTML.Doctype', 'HTML 4.01 Transitional');

        $config->set('HTML.Allowed', implode(',', [
            'p', 'br', 'hr',
            'strong', 'em', 'u', 's', 'del', 'sub', 'sup',
            'span[class|style]',
            'h2', 'h3', 'h4',
            'ul', 'ol', 'li',
            'a[href|title|target|rel]',
            'blockquote',
            'pre[class]', 'code[class]',
            'table[class]', 'thead', 'tbody', 'tr',
            'th[colspan|rowspan]', 'td[colspan|rowspan]',
            'img[src|alt|title|width|height]',
            'iframe[src|width|height|allowfullscreen|frameborder|loading|class]',
            'div[class]', 'figure[class]', 'figcaption',
        ]));

        $config->set('CSS.AllowedProperties', ['color', 'background-color', 'text-align', 'font-weight', 'font-style']);

        $config->set('Attr.AllowedFrameTargets', ['_blank']);
        $config->set('Attr.DefaultInvalidImageAlt', '');
        $config->set('AutoFormat.AutoParagraph', false);
        $config->set('AutoFormat.RemoveEmpty', true);

        $config->set('HTML.SafeIframe', true);
        $config->set(
            'URI.SafeIframeRegexp',
            '%^https://(www\.youtube\.com/embed/|player\.vimeo\.com/video/)%'
        );

        self::$purifier = new HTMLPurifier($config);
        return self::$purifier;
    }
}
