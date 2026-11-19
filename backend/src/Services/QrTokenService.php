<?php
declare(strict_types=1);

namespace Attender\Services;

use Attender\Database;
use function Attender\Support\config;
use function Attender\Support\hash_token;
use function Attender\Support\now_sql;
use function Attender\Support\random_token;

final class QrTokenService
{
    public static function issue(int $sessionId, ?int $ttlSeconds = null): array
    {
        $sec = config('security');
        $ttl = $ttlSeconds ?? (int) $sec['qr_token_ttl_seconds'];
        $plain = random_token((int) $sec['token_bytes']);
        $hash = hash_token($plain);
        $expires = (new \DateTimeImmutable('now'))->modify('+' . $ttl . ' seconds')->format('Y-m-d H:i:s');

        Database::exec(
            'UPDATE attendance_tokens SET is_active = 0 WHERE session_id = ? AND is_active = 1',
            [$sessionId]
        );
        $id = Database::insert(
            'INSERT INTO attendance_tokens (session_id, token_hash, token_plain, expires_at, is_active, created_at)
             VALUES (?, ?, ?, ?, 1, ?)',
            [$sessionId, $hash, $plain, $expires, now_sql()]
        );
        return ['id' => $id, 'token' => $plain, 'expires_at' => $expires];
    }

    public static function findActiveByPlain(string $plain): ?array
    {
        $hash = hash_token($plain);
        return Database::fetch(
            'SELECT t.*, s.status AS session_status, s.id AS sid
             FROM attendance_tokens t
             JOIN attendance_sessions s ON s.id = t.session_id
             WHERE t.token_hash = ? AND t.is_active = 1
             LIMIT 1',
            [$hash]
        );
    }

    public static function currentPlain(int $sessionId): ?array
    {
        return Database::fetch(
            'SELECT token_plain AS token, expires_at FROM attendance_tokens
             WHERE session_id = ? AND is_active = 1 ORDER BY id DESC LIMIT 1',
            [$sessionId]
        );
    }
}
