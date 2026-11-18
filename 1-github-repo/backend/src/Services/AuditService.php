<?php
declare(strict_types=1);

namespace Attender\Services;

use Attender\Database;
use function Attender\Support\client_ip;
use function Attender\Support\now_sql;

final class AuditService
{
    public static function log(
        ?int $lecturerId,
        string $action,
        string $detail = '',
        string $reason = '',
        ?string $entityType = null,
        ?int $entityId = null,
        ?array $previous = null,
        ?array $new = null,
        string $actorType = 'lecturer',
        ?int $actorId = null,
        ?string $actorName = null
    ): void {
        Database::insert(
            'INSERT INTO audit_logs
             (lecturer_id, actor_type, actor_id, actor_name, action, entity_type, entity_id, detail, reason, previous_json, new_json, ip_address, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $lecturerId,
                $actorType,
                $actorId,
                $actorName,
                $action,
                $entityType,
                $entityId,
                mb_substr($detail, 0, 500),
                mb_substr($reason, 0, 255),
                $previous !== null ? json_encode($previous) : null,
                $new !== null ? json_encode($new) : null,
                client_ip(),
                now_sql(),
            ]
        );
    }
}
