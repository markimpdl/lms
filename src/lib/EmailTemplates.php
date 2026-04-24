<?php
declare(strict_types=1);

/**
 * Renderizador de templates de email (E10-02).
 *
 * Cada template é 1 arquivo PHP por idioma em `src/templates/emails/`
 * que `return [...]` com `subject`, `html`, `text`. Wrapped por um
 * layout base (`_layout.<lang>.php`) com header/footer.
 *
 * Convenção de nomes:
 *   src/templates/emails/activity_feedback.pt.php
 *   src/templates/emails/activity_feedback.en.php
 *   src/templates/emails/_layout.pt.php
 *   src/templates/emails/_layout.en.php
 *
 * Variáveis passadas em `$vars` são substituídas nos `:placeholder`
 * (mesma sintaxe do `__t`). Vars são **escapadas com htmlspecialchars**
 * quando entram no corpo HTML; passam raw no subject e no texto
 * plain-text (onde não há risco de injeção de markup).
 *
 * Fallback: se não existir arquivo para o `$lang` pedido, cai pra `pt`.
 * Se também não existir em `pt`, retorna strings vazias (o chamador
 * decide se loga / aborta / manda um alerta genérico).
 */
final class EmailTemplates
{
    private const BASE = '/src/templates/emails/';

    /**
     * @param array<string,scalar|\Stringable> $vars
     * @return array{subject:string, html:string, text:string}
     */
    public static function render(string $name, string $lang, array $vars = []): array
    {
        $lang = in_array($lang, ['pt', 'en'], true) ? $lang : 'pt';

        // Defesa contra path traversal: `$name` vai direto num `require`.
        // Hoje é sempre um literal interno (ex.: 'activity_feedback'), mas
        // um guard trivial evita que um futuro caller passe user input.
        if (preg_match('/^[a-z_][a-z0-9_]*$/', $name) !== 1) {
            return ['subject' => '', 'html' => '', 'text' => ''];
        }

        $tpl    = self::load($name, $lang);
        $layout = self::load('_layout', $lang);

        $subject = self::substitute((string) ($tpl['subject'] ?? ''), $vars, false);
        $bodyH   = self::substitute((string) ($tpl['html']    ?? ''), $vars, true);
        $bodyT   = self::substitute((string) ($tpl['text']    ?? ''), $vars, false);

        $html = (string) ($layout['html_before'] ?? '')
              . $bodyH
              . (string) ($layout['html_after'] ?? '');

        $text = (string) ($layout['text_before'] ?? '')
              . $bodyT
              . (string) ($layout['text_after'] ?? '');

        return [
            'subject' => $subject,
            'html'    => $html,
            'text'    => $text,
        ];
    }

    /**
     * Carrega `<name>.<lang>.php` com fallback pra `<name>.pt.php`.
     * Retorna array vazio e loga em `storage/logs/email-missing.log` se
     * nenhum dos dois existir (padrão igual ao i18n em `helpers.php`).
     *
     * @return array<string,mixed>
     */
    private static function load(string $name, string $lang): array
    {
        $file = LMS_ROOT . self::BASE . $name . '.' . $lang . '.php';
        if (!is_file($file) && $lang !== 'pt') {
            $file = LMS_ROOT . self::BASE . $name . '.pt.php';
        }
        if (!is_file($file)) {
            if (!str_starts_with($name, '_')) {
                $logFile = LMS_ROOT . '/storage/logs/email-missing.log';
                $line = sprintf("[%s] %s.%s.php\n", date('Y-m-d H:i:s'), $name, $lang);
                @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
            }
            return [];
        }
        $data = require $file;
        return is_array($data) ? $data : [];
    }

    /**
     * @param array<string,scalar|\Stringable> $vars
     */
    private static function substitute(string $template, array $vars, bool $escapeHtml): string
    {
        if ($template === '' || $vars === []) {
            return $template;
        }
        $replace = [];
        foreach ($vars as $k => $v) {
            $value = (string) $v;
            if ($escapeHtml) {
                $value = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }
            $replace[':' . $k] = $value;
        }
        return strtr($template, $replace);
    }
}
