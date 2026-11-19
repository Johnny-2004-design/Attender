<?php
declare(strict_types=1);

use Attender\Support\Session;
use function Attender\Support\config;

$root = dirname(__DIR__);

// Shared hosts often cannot set process env. Optional server-local overrides
// (gitignored). Copy config/local.production.php.example → local.production.php
// on the host only — never commit secrets.
$localProduction = $root . '/config/local.production.php';
if (is_file($localProduction)) {
    $local = require $localProduction;
    if (is_array($local)) {
        foreach ($local as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            if ($value === null) {
                continue;
            }
            putenv($key . '=' . (string) $value);
            $_ENV[$key] = (string) $value;
            $_SERVER[$key] = (string) $value;
        }
    }
}

if (is_file($root . '/vendor/autoload.php')) {
    require $root . '/vendor/autoload.php';
} else {
    spl_autoload_register(static function (string $class) use ($root): void {
        if (!str_starts_with($class, 'Attender\\')) {
            return;
        }
        $path = $root . '/src/' . str_replace('\\', '/', substr($class, 9)) . '.php';
        if (is_file($path)) {
            require $path;
        }
    });
    require $root . '/src/Support/helpers.php';
}

$app = config('app');
date_default_timezone_set($app['timezone'] ?? 'UTC');

if (strtolower((string) ($app['env'] ?? 'local')) === 'production' || empty($app['debug'])) {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    error_reporting(E_ALL);
}

if (!is_dir($app['storage_path'] . '/logs')) {
    @mkdir($app['storage_path'] . '/logs', 0755, true);
}
if (!is_dir($app['template_path'])) {
    @mkdir($app['template_path'], 0750, true);
}
if (!is_dir($app['storage_path'] . '/exports')) {
    @mkdir($app['storage_path'] . '/exports', 0755, true);
}

Session::start();

return $app;
