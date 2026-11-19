<?php
declare(strict_types=1);

/**
 * Router for PHP's built-in server (local smoke tests):
 *   php -S 0.0.0.0:8080 router.php
 *
 * XAMPP / Apache users do not need this file — use api/.htaccess instead.
 */

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');
$file = __DIR__ . $uri;

if ($uri !== '/' && is_file($file)) {
    return false; // serve static file as-is
}

if (preg_match('#^/api(?:/index\.php)?(?:/|$)(.*)$#', $uri, $m)) {
    $_GET['r'] = $_GET['r'] ?? trim($m[1], '/');
    require __DIR__ . '/api/index.php';
    return true;
}

if ($uri === '/' || $uri === '') {
    require __DIR__ . '/index.html';
    return true;
}

http_response_code(404);
echo 'Not found';
return true;
