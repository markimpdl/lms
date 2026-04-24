<?php
declare(strict_types=1);

return [
    'subject' => 'Feedback on :activity_title',

    'html' => <<<'HTML'
<p style="margin:0 0 12px;">Hi <strong>:student_name</strong>,</p>
<p style="margin:0 0 12px;">Your teacher left feedback on your submission for <strong>:activity_title</strong>.</p>
<p style="margin:16px 0;">
  <a href=":link" style="display:inline-block;padding:10px 20px;border-radius:999px;background:#6366F1;color:#ffffff;text-decoration:none;font-weight:600;font-size:14px;">View feedback</a>
</p>
<p style="margin:0;color:#6B7280;font-size:13px;">Or copy this link into your browser: :link</p>
HTML,

    'text' => <<<TXT
Hi :student_name,

Your teacher left feedback on your submission for ":activity_title".

Open: :link
TXT,
];
