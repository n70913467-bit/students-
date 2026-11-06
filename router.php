<?php
declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if (str_starts_with($path, '/storage/')) {
    http_response_code(404);
    echo 'Not found';
    return true;
}

if (preg_match('#^/api/?(.*)$#', $path, $matches)) {
    $_GET['route'] = trim($matches[1] ?? '', '/') ?: 'health';
    require __DIR__ . '/api.php';
    return true;
}

if ($path === '/' || $path === '/index.html') {
    require __DIR__ . '/index.html';
    return true;
}

$file = realpath(__DIR__ . $path);
$root = realpath(__DIR__);
if ($file !== false && $root !== false && str_starts_with($file, $root . DIRECTORY_SEPARATOR) && is_file($file)) {
    return false;
}

http_response_code(404);
echo 'Not found';
return true;
