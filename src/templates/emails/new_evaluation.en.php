<?php
declare(strict_types=1);

return [
    'subject' => 'New assessment: :title',

    'html' => <<<'HTML'
<p style="margin:0 0 12px;">Hi <strong>:student_name</strong>,</p>
<p style="margin:0 0 12px;">Your teacher has published a new assessment: <strong>:title</strong>.</p>
<p style="margin:0 0 16px;">Read the brief (PDF) and submit your answer before the deadline.</p>
<p style="margin:16px 0;">
  <a href=":link" style="display:inline-block;padding:10px 20px;border-radius:999px;background:#6366F1;color:#ffffff;text-decoration:none;font-weight:600;font-size:14px;">Open assessment</a>
</p>
<p style="margin:0;color:#6B7280;font-size:13px;">Or copy this link into your browser: :link</p>
HTML,

    'text' => <<<TXT
Hi :student_name,

Your teacher has published a new assessment: ":title".

Read the brief (PDF) and submit your answer before the deadline.

Open: :link
TXT,
];
