<?php
declare(strict_types=1);

namespace Attender\Support;

/**
 * Reject empty / known-dev / weak face API keys in production.
 * Secrets are env-only — never frontend.
 */
final class FaceSecrets
{
    /** @var list<string> */
    private const FORBIDDEN = [
        '',
        'attender-face-dev-key',
        'secret',
        'changeme',
        'password',
        'test-key',
        'test',
        'apikey',
        'api-key',
        '12345678',
        'your-matching-key',
    ];

    public static function isProduction(?string $env = null): bool
    {
        if ($env === null) {
            $env = (string) (config('app')['env'] ?? getenv('ATTENDER_ENV') ?: 'local');
        }
        return in_array(strtolower($env), ['prod', 'production'], true);
    }

    public static function isForbiddenKey(?string $key): bool
    {
        if ($key === null) {
            return true;
        }
        $k = trim($key);
        if ($k === '') {
            return true;
        }
        $lower = strtolower($k);
        if (in_array($lower, self::FORBIDDEN, true)) {
            return true;
        }
        if (strlen($k) < 16) {
            return true;
        }
        if (preg_match('/^(.)\1+$/', $k)) {
            return true;
        }
        return false;
    }

    /**
     * @return array{ok:bool, reason?:string}
     */
    public static function assertUsable(?string $key, ?string $env = null): array
    {
        if (!self::isProduction($env)) {
            if ($key === null || trim($key) === '') {
                return ['ok' => false, 'reason' => 'Face API key is empty.'];
            }
            return ['ok' => true];
        }
        if (self::isForbiddenKey($key)) {
            return [
                'ok' => false,
                'reason' => 'Production rejects empty, short, or known development face API keys. Set a strong ATTENDER_FACE_API_KEY in the server environment.',
            ];
        }
        return ['ok' => true];
    }
}
