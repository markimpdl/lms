<?php
declare(strict_types=1);

return [
    'subject' => 'Resubmission enabled: :title',

    'html' => <<<'HTML'
<p style="margin:0 0 12px;">Hi <strong>:student_name</strong>,</p>
<p style="margin:0 0 12px;">Your teacher has enabled resubmission for your assessment <strong>:title</strong>.</p>
<p style="margin:0 0 16px;">Review the feedback and submit a new attempt when you're ready.</p>
<p style="margin:16px 0;">
  <a href=":link" style="display:inline-block;padding:10px 20px;border-radius:999px;background:#EC4899;color:#ffffff;text-decoration:none;font-weight:600;font-size:14px;">Resubmit assessment</a>
</p>
<p style="margin:0;color:#6B7280;font-size:13px;">Or copy this link into your browser: :link</p>
HTML,

    'text' => <<<TXT
Hi :student_name,

Your teacher has enabled resubmission for your assessment ":title".

Review the feedback and submit a new attempt when you're ready.

Open: :link
TXT,
];
