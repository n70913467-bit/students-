<?php
declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

// Apply browser security headers to HTML and static assets as well as API responses.
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'");


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
