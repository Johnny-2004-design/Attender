<?php
declare(strict_types=1);

namespace Attender\Middleware;

use Attender\Database;
use function Attender\Support\json_error;

/** Ensures lecturer-owned rows cannot be accessed across lecturer boundaries. */
final class LecturerScope
{
    public static function assertOwns(string $table, int $id, int $lecturerId, string $idColumn = 'id'): void
    {
        $allowed = [
            'lecturer_classes', 'courses', 'student_registrations', 'course_enrollments',
            'class_invitations', 'classroom_locations', 'attendance_sessions',
            'attendance_records', 'system_settings', 'notifications', 'audit_logs',
        ];
        if (!in_array($table, $allowed, true)) {
            throw new \InvalidArgumentException('Table not scopable');
        }
        $row = Database::fetch(
            "SELECT id FROM {$table} WHERE {$idColumn} = ? AND lecturer_id = ? LIMIT 1",
            [$id, $lecturerId]
        );
        if (!$row) {
            json_error('Resource not found.', 404);
            exit;
        }
    }
}
