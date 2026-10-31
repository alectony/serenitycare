<?php
$requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$requestUri = trim($requestUri, '/');

// Default fallback directly to login.php
if (empty($requestUri)) {
    require_once __DIR__ . '/../login.php';
    exit;
}

// Map root files (e.g. login.php, register.php, logout.php)
$rootFile = __DIR__ . '/../' . $requestUri;
if (file_exists($rootFile) && !is_dir($rootFile)) {
    require_once $rootFile;
    exit;
}

// Map folder paths (e.g. /admin/, /counselor/, /patient/)
$folderIndex = __DIR__ . '/../' . $requestUri . (substr($requestUri, -1) === '/' ? '' : '/') . 'index.php';
if (file_exists($folderIndex)) {
    require_once $folderIndex;
    exit;
}

// Map direct scripts inside subfolders
$subFile = __DIR__ . '/../' . $requestUri;
if (file_exists($subFile) && !is_dir($subFile)) {
    require_once $subFile;
    exit;
}

http_response_code(404);
echo "404 Not Found";