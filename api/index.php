<?php
$requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$requestUri = trim($requestUri, '/');

// Default fallback to root index
if (empty($requestUri)) {
    require_once __DIR__ . '/../index.php';
    exit;
}


?>