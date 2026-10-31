<?php
// Front controller: every request that is not a static file lands here.
$path = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
$root = dirname(__DIR__);

// Static assets: serve /css/* and /js/* from /public (works whether or not Vercel serves /public itself)
if (preg_match('#^(css|js)/([A-Za-z0-9._-]+\.(css|js))$#', $path, $m)) {
    $file = $root . '/public/' . $m[1] . '/' . $m[2];
    if (is_file($file)) {
        header('Content-Type: ' . ($m[3] === 'css' ? 'text/css' : 'application/javascript') . '; charset=utf-8');
        header('Cache-Control: public, max-age=300');
        readfile($file);
        exit;
    }
    http_response_code(404);
    exit;
}

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