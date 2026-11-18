<?php
declare(strict_types=1);

namespace Attender\Services;

use Attender\Database;
use function Attender\Support\config;
use function Attender\Support\hash_token;
use function Attender\Support\now_sql;
use function Attender\Support\random_code;

final class CodeService
{
    public static function issue(int $sessionId, ?int $ttlMinutes = null): array
    {
        $sec = config('security');
        $ttl = $ttlMinutes ?? (int) $sec['code_ttl_minutes'];
        $plain = random_code($sec['code_alphabet'], (int) $sec['code_length']);
        $hash = hash_token(strtoupper($plain));
        $expires = (new \DateTimeImmutable('now'))->modify('+' . $ttl . ' minutes')->format('Y-m-d H:i:s');

        Database::exec(
            'UPDATE attendance_codes SET is_active = 0 WHERE session_id = ? AND is_active = 1',
            [$sessionId]
        );
        $id = Database::insert(
            'INSERT INTO attendance_codes (session_id, code_hash, code_plain, expires_at, attempt_limit, is_active, created_at)
             VALUES (?, ?, ?, ?, ?, 1, ?)',
            [$sessionId, $hash, $plain, $expires, (int) $sec['max_code_attempts'], now_sql()]
        );
        return ['id' => $id, 'code' => $plain, 'expires_at' => $expires];
    }

    public static function findActiveByPlain(string $plain): ?array
    {
        $hash = hash_token(strtoupper(trim($plain)));
        return Database::fetch(
            'SELECT c.*, s.status AS session_status
             FROM attendance_codes c
             JOIN attendance_sessions s ON s.id = c.session_id
             WHERE c.code_hash = ? AND c.is_active = 1
             LIMIT 1',
            [$hash]
        );
    }

    public static function currentPlain(int $sessionId): ?array
    {
        return Database::fetch(
            'SELECT code_plain AS code, expires_at FROM attendance_codes
             WHERE session_id = ? AND is_active = 1 ORDER BY id DESC LIMIT 1',
            [$sessionId]
        );
    }
}
