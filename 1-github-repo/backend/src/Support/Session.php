<?php
declare(strict_types=1);

namespace Attender\Support;

final class Session
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $app = config('app');
        $sec = config('security');
        session_name($app['session_name']);
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ((int) ($_SERVER['SERVER_PORT'] ?? 80) === 443)
            || (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');
        // Split deployment (frontend on another origin): the browser only sends the session
        // cookie on cross-site fetch() calls when SameSite=None; Secure (HTTPS required).
        $crossSite = $https && !empty($app['allowed_origins']);
        session_set_cookie_params([
            'lifetime' => $sec['session_lifetime'],
            'path' => '/',
            'httponly' => true,
            'samesite' => $crossSite ? 'None' : 'Lax',
            'secure' => $https,
        ]);
        session_start();
        if (!isset($_SESSION['_initiated'])) {
            session_regenerate_id(true);
            $_SESSION['_initiated'] = true;
            $_SESSION['_created'] = time();
        }
        if ((time() - (int) ($_SESSION['_created'] ?? 0)) > $sec['session_lifetime']) {
            self::destroy();
            self::start();
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public static function destroy(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'] ?? '', (bool) $p['secure'], (bool) $p['httponly']);
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }
}
