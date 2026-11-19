<?php
declare(strict_types=1);

namespace Attender\Support;

final class Csrf
{
    public static function token(): string
    {
        Session::start();
        $key = config('app')['csrf_key'];
        $existing = Session::get($key);
        if (!is_array($existing) || empty($existing['value']) || ($existing['exp'] ?? 0) < time()) {
            $existing = [
                'value' => bin2hex(random_bytes(32)),
                'exp' => time() + (int) config('security')['csrf_ttl'],
            ];
            Session::set($key, $existing);
        }
        return $existing['value'];
    }

    public static function validate(?string $token): bool
    {
        Session::start();
        $key = config('app')['csrf_key'];
        $existing = Session::get($key);
        if (!is_array($existing) || empty($existing['value']) || ($existing['exp'] ?? 0) < time()) {
            return false;
        }
        return is_string($token) && hash_equals($existing['value'], $token);
    }
}
