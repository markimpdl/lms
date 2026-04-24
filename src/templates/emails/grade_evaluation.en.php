<?php
declare(strict_types=1);

return [
    'subject' => 'Grade available: :title',

    'html' => <<<'HTML'
<p style="margin:0 0 12px;">Hi <strong>:student_name</strong>,</p>
<p style="margin:0 0 12px;">Your teacher has graded your assessment <strong>:title</strong>. Open it to see your grade and feedback.</p>
<p style="margin:16px 0;">
  <a href=":link" style="display:inline-block;padding:10px 20px;border-radius:999px;background:#6366F1;color:#ffffff;text-decoration:none;font-weight:600;font-size:14px;">View grade</a>
</p>
<p style="margin:0;color:#6B7280;font-size:13px;">Or copy this link into your browser: :link</p>
HTML,

    'text' => <<<TXT
Hi :student_name,

Your teacher has graded your assessment ":title". Open it to see your grade and feedback.

Open: :link
TXT,
];
