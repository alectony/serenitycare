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

// Home page -> landing page
if ($path === '' || $path === 'index.php') {
    require __DIR__ . '/landing.php';
    exit;
}

// Public pages that live next to this file, in /api
$apiPages = ['login.php', 'register.php', 'logout.php', 'landing.php', 'forgot-password.php', 'chat.php', 'apply-counselor.php'];

// Tolerant aliases for public URLs
if (in_array($path, ['apply-counselor.php', 'apply-counselors.php', 'apply-counselor', 'apply-counselors', 'apply'], true)) {
    $path = 'apply-counselor.php';
} elseif (in_array($path, ['forgot-password', 'forgot.php', 'forgot'], true)) {
    $path = 'forgot-password.php';
}

if (in_array($path, $apiPages, true)) {
    $target = __DIR__ . '/' . $path;
    if (is_file($target)) {
        require $target;
        exit;
    }
}

// Role areas that live at the project root: /admin, /counselor, /patient
// e.g. /patient/        -> patient/index.php
//      /patient/book.php -> patient/appointment.php
$parts = explode('/', $path);
$areas = ['admin', 'counselor', 'patient'];

if (in_array($parts[0], $areas, true)) {
    $file = (isset($parts[1]) && $parts[1] !== '') ? rawurldecode($parts[1]) : 'index.php';

    // Useful aliases for smooth navigation
    if ($parts[0] === 'patient' && in_array($file, ['results.php', 'diagnostic.php'], true)) {
        $file = 'Diagnostic and result.php';
    } elseif ($parts[0] === 'patient' && in_array($file, ['book.php', 'book-appointment.php'], true)) {
        $file = 'appointment.php';
    } elseif (in_array($file, ['print.php', 'slip.php'], true)) {
        $file = 'print-appointment.php';
    }

    // Whitelist the filename so URLs like /admin/../.env can never be loaded
    if (preg_match('/^[A-Za-z0-9_ -]+\.php$/', $file) && !isset($parts[2])) {
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
