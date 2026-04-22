<?php
declare(strict_types=1);

/**
 * Mapa de rotas do LMS (E1-05).
 *
 *   public         → qualquer visitante pode abrir; o front controller NÃO
 *                    chama require_auth antes.
 *   authenticated  → o front controller chama require_auth() antes de
 *                    incluir o arquivo.
 *   roles          → o front controller chama require_role($role). O path
 *                    casa por exact match OU por prefixo (ex.: /admin/users
 *                    cai no handler de /admin).
 *
 * Match é exact-first; prefixo só é testado quando nenhum exact bateu.
 * Sub-rotas específicas podem sobrescrever um prefixo adicionando-se às
 * próprias listas acima (exact match ganha).
 */
return [
    'public' => [
        '/'       => '/src/pages/home.php',
        '/login'  => '/src/pages/login.php',
        '/logout' => '/src/pages/logout.php',
        '/forgot' => '/src/pages/forgot.php',
        '/reset'  => '/src/pages/reset.php',
    ],

    'authenticated' => [
        '/profile' => '/src/pages/profile.php',
    ],

    'roles' => [
        '/admin'              => ['file' => '/src/pages/dashboard/admin.php',      'role' => 'super_admin'],
        '/admin/teachers'     => ['file' => '/src/pages/admin/teachers/index.php', 'role' => 'super_admin'],
        '/admin/teachers/new' => ['file' => '/src/pages/admin/teachers/new.php',   'role' => 'super_admin'],
        '/teacher'            => ['file' => '/src/pages/dashboard/teacher.php',    'role' => 'teacher'],
        '/student'            => ['file' => '/src/pages/dashboard/student.php',    'role' => 'student'],
    ],
];
