<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path = ($path === '' || $path === '/') ? '/' : rtrim($path, '/');

$routes = [
    '/'         => '/src/pages/home.php',
    '/login'    => '/src/pages/login.php',
    '/logout'   => '/src/pages/logout.php',
    '/forgot'   => '/src/pages/forgot.php',
    '/reset'    => '/src/pages/reset.php',
    '/admin'    => '/src/pages/dashboard/admin.php',
    '/teacher'  => '/src/pages/dashboard/teacher.php',
    '/student'  => '/src/pages/dashboard/student.php',
];

if (isset($routes[$path])) {
    require LMS_ROOT . $routes[$path];
    return;
}

http_response_code(404);
require LMS_ROOT . '/src/pages/404.php';
