<?php
declare(strict_types=1);

/**
 * Template `course_shared` — en (E32-03 / F23).
 *
 * Sent when a teacher shares course authoring with another teacher. Vars:
 *   :student_name — invited teacher's name (reuses the generic slot)
 *   :title        — shared course name
 *   :body         — name of the teacher who shared (owner)
 *   :link         — absolute URL to /teacher/courses/{id}
 */

return [
    'subject' => 'A course was shared with you: :title',

    'html' => <<<'HTML'
<p style="margin:0 0 12px;">Hi, <strong>:student_name</strong>!</p>
<p style="margin:0 0 12px;"><strong>:body</strong> shared authoring of the course <strong>:title</strong> with you.</p>
<p style="margin:0 0 16px;">You can now edit its content, create competencies and units, and enroll your own students. Each teacher keeps their own students, grades and ranking separate.</p>
<p style="margin:16px 0;">
  <a href=":link" style="display:inline-block;padding:10px 20px;border-radius:999px;background:#6366F1;color:#ffffff;text-decoration:none;font-weight:600;font-size:14px;">Open course</a>
</p>
<p style="margin:0;color:#6B7280;font-size:13px;">Or paste this address in your browser: :link</p>
HTML,

    'text' => <<<TXT
Hi, :student_name!

:body shared authoring of the course ":title" with you.

You can now edit its content, create competencies and units, and enroll your own students. Each teacher keeps their own students, grades and ranking separate.

Open: :link
TXT,
];
