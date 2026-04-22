<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path = ($path === '' || $path === '/') ? '/' : rtrim($path, '/');

$routes = require LMS_ROOT . '/src/routes.php';

// 1. Público — sem auth.
if (isset($routes['public'][$path])) {
    require LMS_ROOT . $routes['public'][$path];
    return;
}

// 2. Autenticado — exige sessão válida (active + senha não rotacionada em outro device).
if (isset($routes['authenticated'][$path])) {
    require_auth();
    require LMS_ROOT . $routes['authenticated'][$path];
    return;
}

// 3. Por papel — exact match primeiro, depois prefixo (/admin/** → /admin).
if (isset($routes['roles'][$path])) {
    require_role($routes['roles'][$path]['role']);
    require LMS_ROOT . $routes['roles'][$path]['file'];
    return;
}
foreach ($routes['roles'] as $prefix => $meta) {
    if (str_starts_with($path, $prefix . '/')) {
        require_role($meta['role']);
        require LMS_ROOT . $meta['file'];
        return;
    }
}

// 4. Nada bateu → 404.
http_response_code(404);
require LMS_ROOT . '/src/templates/errors/404.php';
