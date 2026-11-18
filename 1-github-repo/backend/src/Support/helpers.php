<?php
declare(strict_types=1);

namespace Attender\Support;

function config(string $file): array
{
    static $cache = [];
    if (!isset($cache[$file])) {
        $path = dirname(__DIR__, 2) . '/config/' . $file . '.php';
        $cache[$file] = require $path;
    }
    return $cache[$file];
}

function env_bool(string $key, bool $default = false): bool
{
    $v = getenv($key);
    if ($v === false) {
        return $default;
    }
    return in_array(strtolower((string) $v), ['1', 'true', 'yes', 'on'], true);
}

function json_response(mixed $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function json_error(string $message, int $status = 400, array $extra = []): void
{
    json_response(array_merge(['ok' => false, 'error' => $message], $extra), $status);
}

function request_json(): array
{
    $raw = file_get_contents('php://input') ?: '';
    if ($raw === '') {
        return $_POST ?: [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function user_agent(): string
{
    return substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
}

function now_sql(): string
{
    return (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');
}

function random_token(int $bytes = 24): string
{
    return bin2hex(random_bytes($bytes));
}

function hash_token(string $plain): string
{
    return hash('sha256', $plain);
}

function random_code(string $alphabet, int $length): string
{
    $out = '';
    $max = strlen($alphabet) - 1;
    for ($i = 0; $i < $length; $i++) {
        $out .= $alphabet[random_int(0, $max)];
    }
    return $out;
}

function base_url(): string
{
    $cfg = config('app');
    if (!empty($cfg['url'])) {
        return $cfg['url'];
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((int) ($_SERVER['SERVER_PORT'] ?? 80) === 443)
        || (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    // /attender/api/index.php -> /attender
    $base = preg_replace('#/api(?:/index\.php)?$#', '', $script) ?: '';
    $base = rtrim(str_replace('\\', '/', dirname($base === '' ? $script : $base . '/x')), '/');
    if (str_ends_with($base, '/public')) {
        $base = substr($base, 0, -7);
    }
    return $scheme . '://' . $host . ($base === '/' ? '' : $base);
}
