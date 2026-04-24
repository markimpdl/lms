<?php
declare(strict_types=1);

/**
 * Layout base dos emails — idioma pt. Emitido por `EmailTemplates::render`
 * em volta do corpo do template específico.
 *
 * Sem imagens externas (compatibilidade com clientes de email).
 * CSS inline — é o padrão em HTML email. Sem gradients/animações.
 */

return [
    'html_before' => <<<'HTML'
<!doctype html>
<html lang="pt">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>LMS</title>
</head>
<body style="margin:0;padding:0;background:#F8F7FB;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#111827;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#F8F7FB;">
    <tr><td align="center" style="padding:24px 12px;">
      <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:16px;border:1px solid #E5E7EB;overflow:hidden;">
        <tr><td style="padding:20px 24px;border-bottom:1px solid #F3F4F6;">
          <span style="display:inline-block;width:34px;height:34px;border-radius:10px;background:#6366F1;color:#ffffff;font-weight:800;font-size:18px;line-height:34px;text-align:center;vertical-align:middle;">L</span>
          <span style="display:inline-block;margin-left:10px;font-weight:800;font-size:18px;color:#111827;vertical-align:middle;">LMS</span>
        </td></tr>
        <tr><td style="padding:24px;color:#374151;font-size:14px;line-height:1.6;">
HTML,

    'html_after' => <<<'HTML'
        </td></tr>
        <tr><td style="padding:16px 24px;border-top:1px solid #F3F4F6;background:#FAFAFA;color:#6B7280;font-size:12px;line-height:1.5;">
          Este email foi enviado automaticamente pela plataforma LMS do seu professor. Não responda a este endereço.
        </td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML,

    'text_before' => "== LMS ==\n\n",

    'text_after'  => "\n\n--\nEste email foi enviado automaticamente pela plataforma LMS do seu professor. Não responda.",
];
