<?php
declare(strict_types=1);

return [
    'subject' => 'Enrollment confirmed: :title',

    'html' => <<<'HTML'
<p style="margin:0 0 12px;">Hi <strong>:student_name</strong>,</p>
<p style="margin:0 0 12px;">You've been enrolled in the course <strong>:title</strong>.</p>
<p style="margin:0 0 16px;">Head over to the platform to explore the competence units, download content and start the activities.</p>
<p style="margin:16px 0;">
  <a href=":link" style="display:inline-block;padding:10px 20px;border-radius:999px;background:#6366F1;color:#ffffff;text-decoration:none;font-weight:600;font-size:14px;">Open course</a>
</p>
<p style="margin:0;color:#6B7280;font-size:13px;">Or copy this link into your browser: :link</p>
HTML,

    'text' => <<<TXT
Hi :student_name,

You've been enrolled in the course ":title".

Head over to the platform to explore the competence units, download content and start the activities.

Open: :link
TXT,
];
