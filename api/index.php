<?php
// Front controller: every request that is not a static file lands here.
$path = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
$root = dirname(__DIR__);

// Home page -> login
if ($path === '') {
    require __DIR__ . '/login.php';
    exit;
}

// Public pages that live next to this file, in /api
$apiPages = ['login.php', 'register.php', 'logout.php'];
if (in_array($path, $apiPages, true)) {
    require __DIR__ . '/' . $path;
    exit;
}

// Role areas that live at the project root: /admin, /counselor, /patient
// e.g. /patient/        -> patient/index.php
//      /patient/book.php -> patient/book.php
$parts = explode('/', $path);
$areas = ['admin', 'counselor', 'patient'];

if (in_array($parts[0], $areas, true)) {
    $file = (isset($parts[1]) && $parts[1] !== '') ? $parts[1] : 'index.php';

    // Whitelist the filename so URLs like /admin/../.env can never be loaded
    if (preg_match('/^[A-Za-z0-9_-]+\.php$/', $file) && !isset($parts[2])) {
        $target = $root . '/' . $parts[0] . '/' . $file;
        if (is_file($target)) {
            require $target;
            exit;
        }
    }
}

http_response_code(404);
echo '404 Not Found';
?>