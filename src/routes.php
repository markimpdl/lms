<?php
declare(strict_types=1);

/**
 * Mapa de rotas do LMS (E1-05 + E2-03).
 *
 *   public         → qualquer visitante pode abrir; o front controller NÃO
 *                    chama require_auth antes.
 *   authenticated  → o front controller chama require_auth() antes de
 *                    incluir o arquivo.
 *   roles          → o front controller chama require_role($role). O path
 *                    casa por exact match OU por prefixo (ex.: /admin/users
 *                    cai no handler de /admin).
 *   role_patterns  → rotas com parâmetros capturáveis via regex. Testadas
 *                    entre exact e prefix match. Capturas nomeadas caem em
 *                    $_REQUEST conforme `params`.
 *
 * Match é exact → pattern → prefix. Sub-rotas específicas ganham ao estar
 * na lista exata (exact match tem a maior precedência).
 */
return [
    'public' => [
        '/'                 => '/src/pages/home.php',
        '/login'            => '/src/pages/login.php',
        '/logout'           => '/src/pages/logout.php',
        '/forgot'           => '/src/pages/forgot.php',
        '/reset'            => '/src/pages/reset.php',
        '/register-teacher' => '/src/pages/register-teacher.php',
    ],

    'authenticated' => [
        '/profile' => '/src/pages/profile.php',
    ],

    'roles' => [
        '/admin'               => ['file' => '/src/pages/dashboard/admin.php',       'role' => 'super_admin'],
        '/admin/settings'      => ['file' => '/src/pages/admin/settings.php',        'role' => 'super_admin'],
        '/admin/teachers'      => ['file' => '/src/pages/admin/teachers/index.php',  'role' => 'super_admin'],
        '/admin/teachers/new'  => ['file' => '/src/pages/admin/teachers/new.php',    'role' => 'super_admin'],
        '/teacher'             => ['file' => '/src/pages/dashboard/teacher.php',     'role' => 'teacher'],
        '/teacher/courses'      => ['file' => '/src/pages/teacher/courses/index.php',  'role' => 'teacher'],
        '/teacher/courses/new'  => ['file' => '/src/pages/teacher/courses/new.php',    'role' => 'teacher'],
        '/teacher/cc/new'       => ['file' => '/src/pages/teacher/cc/new.php',         'role' => 'teacher'],
        '/teacher/cu/new'       => ['file' => '/src/pages/teacher/cu/new.php',         'role' => 'teacher'],
        '/teacher/students'     => ['file' => '/src/pages/teacher/students/index.php', 'role' => 'teacher'],
        '/teacher/students/new' => ['file' => '/src/pages/teacher/students/new.php',   'role' => 'teacher'],
        '/teacher/groups'       => ['file' => '/src/pages/teacher/groups/index.php',   'role' => 'teacher'],
        '/teacher/groups/new'   => ['file' => '/src/pages/teacher/groups/new.php',     'role' => 'teacher'],
        '/student'             => ['file' => '/src/pages/dashboard/student.php',     'role' => 'student'],
    ],

    'role_patterns' => [
        '#^/admin/teachers/(\d+)$#' => [
            'file'   => '/src/pages/admin/teachers/edit.php',
            'role'   => 'super_admin',
            'params' => ['id'],
        ],
        '#^/admin/teachers/(\d+)/toggle$#' => [
            'file'   => '/src/pages/admin/teachers/toggle.php',
            'role'   => 'super_admin',
            'params' => ['id'],
        ],
        '#^/admin/teachers/(\d+)/reset-password$#' => [
            'file'   => '/src/pages/admin/teachers/reset-password.php',
            'role'   => 'super_admin',
            'params' => ['id'],
        ],
        '#^/teacher/courses/(\d+)$#' => [
            'file'   => '/src/pages/teacher/courses/show.php',
            'role'   => 'teacher',
            'params' => ['id'],
        ],
        '#^/teacher/courses/(\d+)/edit$#' => [
            'file'   => '/src/pages/teacher/courses/edit.php',
            'role'   => 'teacher',
            'params' => ['id'],
        ],
        '#^/teacher/courses/(\d+)/toggle-archive$#' => [
            'file'   => '/src/pages/teacher/courses/toggle-archive.php',
            'role'   => 'teacher',
            'params' => ['id'],
        ],
        '#^/teacher/courses/(\d+)/delete$#' => [
            'file'   => '/src/pages/teacher/courses/delete.php',
            'role'   => 'teacher',
            'params' => ['id'],
        ],
        '#^/teacher/cc/(\d+)/rename$#' => [
            'file'   => '/src/pages/teacher/cc/rename.php',
            'role'   => 'teacher',
            'params' => ['id'],
        ],
        '#^/teacher/cc/(\d+)/move-(up|down)$#' => [
            'file'   => '/src/pages/teacher/cc/move.php',
            'role'   => 'teacher',
            'params' => ['id', 'direction'],
        ],
        '#^/teacher/cc/(\d+)/delete$#' => [
            'file'   => '/src/pages/teacher/cc/delete.php',
            'role'   => 'teacher',
            'params' => ['id'],
        ],
        '#^/teacher/courses/(\d+)/cc/(\d+)$#' => [
            'file'   => '/src/pages/teacher/cc/show.php',
            'role'   => 'teacher',
            'params' => ['course_id', 'cc_id'],
        ],
        '#^/teacher/cu/(\d+)$#' => [
            'file'   => '/src/pages/teacher/cu/show.php',
            'role'   => 'teacher',
            'params' => ['id'],
        ],
        '#^/teacher/cu/(\d+)/content/edit$#' => [
            'file'   => '/src/pages/teacher/cu/content-edit.php',
            'role'   => 'teacher',
            'params' => ['id'],
        ],
        '#^/teacher/cu/(\d+)/attachment$#' => [
            'file'   => '/src/pages/teacher/cu/attachment-upload.php',
            'role'   => 'teacher',
            'params' => ['id'],
        ],
        '#^/teacher/cu/(\d+)/attachment/(\d+)/delete$#' => [
            'file'   => '/src/pages/teacher/cu/attachment-delete.php',
            'role'   => 'teacher',
            'params' => ['id', 'aid'],
        ],
        '#^/teacher/cu/(\d+)/attachment/(\d+)/view$#' => [
            'file'   => '/src/pages/teacher/cu/attachment-view.php',
            'role'   => 'teacher',
            'params' => ['id', 'aid'],
        ],
        '#^/teacher/cu/(\d+)/activity/new$#' => [
            'file'   => '/src/pages/teacher/activity/new.php',
            'role'   => 'teacher',
            'params' => ['id'],
        ],
        '#^/teacher/activity/(\d+)/edit$#' => [
            'file'   => '/src/pages/teacher/activity/edit.php',
            'role'   => 'teacher',
            'params' => ['id'],
        ],
        '#^/teacher/activity/(\d+)/move-(up|down)$#' => [
            'file'   => '/src/pages/teacher/activity/move.php',
            'role'   => 'teacher',
            'params' => ['id', 'direction'],
        ],
        '#^/teacher/activity/(\d+)/toggle$#' => [
            'file'   => '/src/pages/teacher/activity/toggle.php',
            'role'   => 'teacher',
            'params' => ['id'],
        ],
        '#^/teacher/activity/(\d+)/submissions$#' => [
            'file'   => '/src/pages/teacher/activity/submissions.php',
            'role'   => 'teacher',
            'params' => ['id'],
        ],
        '#^/teacher/activity/(\d+)/delete$#' => [
            'file'   => '/src/pages/teacher/activity/delete.php',
            'role'   => 'teacher',
            'params' => ['id'],
        ],
        '#^/teacher/activity/(\d+)/submission/(\d+)$#' => [
            'file'   => '/src/pages/teacher/activity/submission-review.php',
            'role'   => 'teacher',
            'params' => ['id', 'student_id'],
        ],
        '#^/teacher/activity/(\d+)/submission/(\d+)/file$#' => [
            'file'   => '/src/pages/teacher/activity/submission-file.php',
            'role'   => 'teacher',
            'params' => ['id', 'student_id'],
        ],
        '#^/teacher/cu/(\d+)/evaluation/new$#' => [
            'file'   => '/src/pages/teacher/evaluation/new.php',
            'role'   => 'teacher',
            'params' => ['id'],
        ],
        '#^/teacher/evaluation/(\d+)/edit$#' => [
            'file'   => '/src/pages/teacher/evaluation/edit.php',
            'role'   => 'teacher',
            'params' => ['id'],
        ],
        '#^/teacher/evaluation/(\d+)/delete$#' => [
            'file'   => '/src/pages/teacher/evaluation/delete.php',
            'role'   => 'teacher',
            'params' => ['id'],
        ],
        '#^/student/evaluation/(\d+)$#' => [
            'file'   => '/src/pages/student/evaluation/show.php',
            'role'   => 'student',
            'params' => ['id'],
        ],
        '#^/student/evaluation/(\d+)/brief$#' => [
            'file'   => '/src/pages/student/evaluation/brief.php',
            'role'   => 'student',
            'params' => ['id'],
        ],
        '#^/student/evaluation/(\d+)/submission/(\d+)/file$#' => [
            'file'   => '/src/pages/student/evaluation/file.php',
            'role'   => 'student',
            'params' => ['id', 'sid'],
        ],
        '#^/teacher/cu/(\d+)/attachment/(\d+)$#' => [
            'file'   => '/src/pages/teacher/cu/attachment-download.php',
            'role'   => 'teacher',
            'params' => ['id', 'aid'],
        ],
        '#^/student/course/(\d+)$#' => [
            'file'   => '/src/pages/student/course/show.php',
            'role'   => 'student',
            'params' => ['id'],
        ],
        '#^/student/activity/(\d+)$#' => [
            'file'   => '/src/pages/student/activity/show.php',
            'role'   => 'student',
            'params' => ['id'],
        ],
        '#^/student/activity/(\d+)/delete$#' => [
            'file'   => '/src/pages/student/activity/delete.php',
            'role'   => 'student',
            'params' => ['id'],
        ],
        '#^/student/activity/(\d+)/file$#' => [
            'file'   => '/src/pages/student/activity/file.php',
            'role'   => 'student',
            'params' => ['id'],
        ],
        '#^/student/cu/(\d+)$#' => [
            'file'   => '/src/pages/student/cu/show.php',
            'role'   => 'student',
            'params' => ['id'],
        ],
        '#^/student/cu/(\d+)/attachment/(\d+)/view$#' => [
            'file'   => '/src/pages/student/cu/attachment-view.php',
            'role'   => 'student',
            'params' => ['id', 'aid'],
        ],
        '#^/student/cu/(\d+)/attachment/(\d+)$#' => [
            'file'   => '/src/pages/student/cu/attachment-download.php',
            'role'   => 'student',
            'params' => ['id', 'aid'],
        ],
        '#^/teacher/cu/(\d+)/rename$#' => [
            'file'   => '/src/pages/teacher/cu/rename.php',
            'role'   => 'teacher',
            'params' => ['id'],
        ],
        '#^/teacher/cu/(\d+)/move-(up|down)$#' => [
            'file'   => '/src/pages/teacher/cu/move.php',
            'role'   => 'teacher',
            'params' => ['id', 'direction'],
        ],
        '#^/teacher/cu/(\d+)/delete$#' => [
            'file'   => '/src/pages/teacher/cu/delete.php',
            'role'   => 'teacher',
            'params' => ['id'],
        ],
        '#^/teacher/students/(\d+)$#' => [
            'file'   => '/src/pages/teacher/students/show.php',
            'role'   => 'teacher',
            'params' => ['id'],
        ],
        '#^/teacher/students/(\d+)/toggle$#' => [
            'file'   => '/src/pages/teacher/students/toggle.php',
            'role'   => 'teacher',
            'params' => ['id'],
        ],
        '#^/teacher/students/(\d+)/delete$#' => [
            'file'   => '/src/pages/teacher/students/delete.php',
            'role'   => 'teacher',
            'params' => ['id'],
        ],
        '#^/teacher/students/(\d+)/reset-password$#' => [
            'file'   => '/src/pages/teacher/students/reset-password.php',
            'role'   => 'teacher',
            'params' => ['id'],
        ],
        '#^/teacher/students/(\d+)/enroll$#' => [
            'file'   => '/src/pages/teacher/students/enroll.php',
            'role'   => 'teacher',
            'params' => ['id'],
        ],
        '#^/teacher/students/(\d+)/unenroll/(\d+)$#' => [
            'file'   => '/src/pages/teacher/students/unenroll.php',
            'role'   => 'teacher',
            'params' => ['id', 'course_id'],
        ],
        '#^/teacher/groups/(\d+)$#' => [
            'file'   => '/src/pages/teacher/groups/show.php',
            'role'   => 'teacher',
            'params' => ['id'],
        ],
        '#^/teacher/groups/(\d+)/rename$#' => [
            'file'   => '/src/pages/teacher/groups/rename.php',
            'role'   => 'teacher',
            'params' => ['id'],
        ],
        '#^/teacher/groups/(\d+)/delete$#' => [
            'file'   => '/src/pages/teacher/groups/delete.php',
            'role'   => 'teacher',
            'params' => ['id'],
        ],
        '#^/teacher/groups/(\d+)/add-member$#' => [
            'file'   => '/src/pages/teacher/groups/add-member.php',
            'role'   => 'teacher',
            'params' => ['id'],
        ],
        '#^/teacher/groups/(\d+)/remove-member/(\d+)$#' => [
            'file'   => '/src/pages/teacher/groups/remove-member.php',
            'role'   => 'teacher',
            'params' => ['id', 'student_id'],
        ],
        '#^/teacher/students/(\d+)/assign-groups$#' => [
            'file'   => '/src/pages/teacher/students/assign-groups.php',
            'role'   => 'teacher',
            'params' => ['id'],
        ],
        '#^/teacher/students/(\d+)/unassign-group/(\d+)$#' => [
            'file'   => '/src/pages/teacher/students/unassign-group.php',
            'role'   => 'teacher',
            'params' => ['id', 'group_id'],
        ],
    ],
];
