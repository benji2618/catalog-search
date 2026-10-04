<?php
declare(strict_types=1);

// Front controller. Dev: php -S localhost:8000 -t public public/index.php
require dirname(__DIR__) . '/vendor/autoload.php';

use App\Controllers\SearchController;

ini_set('display_errors', '0'); // errors go to the log, never into the response

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

// PHP built-in server: let it serve existing static files itself (never .php files)
if (PHP_SAPI === 'cli-server' && $path !== '/' && !str_ends_with($path, '.php')) {
    $file = realpath(__DIR__ . $path);
    if ($file !== false && is_file($file) && str_starts_with($file, __DIR__ . DIRECTORY_SEPARATOR)) return false;
}

if ($path === '/' && is_file(__DIR__ . '/index.html')) {
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . '/index.html');
} elseif ($path === '/api/search' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    (new SearchController())->handle();
} elseif ($path === '/api' || str_starts_with($path, '/api/')) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Not found']);
} else {
    http_response_code(404);
    echo 'Not found';
}
