<?php
declare(strict_types=1);

namespace Attender\Services;

use Attender\Database;

/**
 * Enforces the lecturer's "Delete face templates after" setting (Settings → Privacy and biometric data).
 *   '12' / '6' → templates older than that many months are deleted.
 *   '0'        → a template is deleted once the student no longer has a pending or approved
 *                registration with the lecturer it was captured for (rejected / removed from the class).
 *
 * Shared hosting has no cron, so this runs whenever a lecturer opens/closes a session or saves
 * settings. It is cheap: one query per lecturer that owns any active template.
 */
final class FaceRetention
{
    /** @return int number of templates deleted */
    public static function purge(?int $onlyLecturerId = null): int
    {
        $sql = 'SELECT DISTINCT lecturer_id FROM facial_templates WHERE lecturer_id IS NOT NULL';
        $params = [];
        if ($onlyLecturerId !== null) {
            $sql .= ' AND lecturer_id = ?';
            $params[] = $onlyLecturerId;
        }
        $total = 0;
        foreach (Database::fetchAll($sql, $params) as $row) {
            $lid = (int) $row['lecturer_id'];
            $retention = (string) (SettingsService::get($lid)['retention'] ?? '12');

            if ($retention === '0') {
                $victims = Database::fetchAll(
                    "SELECT t.id, t.student_id FROM facial_templates t
                     WHERE t.lecturer_id = ?
                       AND NOT EXISTS (SELECT 1 FROM student_registrations r
                                       WHERE r.student_id = t.student_id AND r.lecturer_id = t.lecturer_id AND r.status IN ('pending','approved'))",
                    [$lid]
                );
            } else {
                $months = max(1, (int) $retention);
                $cutoff = (new \DateTimeImmutable('now'))->modify('-' . $months . ' months')->format('Y-m-d H:i:s');
                $victims = Database::fetchAll(
                    'SELECT id, student_id FROM facial_templates WHERE lecturer_id = ? AND created_at < ?',
                    [$lid, $cutoff]
                );
            }
            if (!$victims) {
                continue;
            }
            $students = [];
            foreach ($victims as $v) {
                Database::exec('DELETE FROM facial_templates WHERE id = ?', [(int) $v['id']]);
                $students[(int) $v['student_id']] = true;
                $total++;
            }
            foreach (array_keys($students) as $sid) {
                // Only clear the flag if no other usable template is left for this student.
                $left = Database::fetch('SELECT id FROM facial_templates WHERE student_id = ? AND is_active = 1 AND embedding_ready = 1 LIMIT 1', [$sid]);
                if (!$left) {
                    Database::exec('UPDATE student_registrations SET face_enrolled = 0 WHERE student_id = ?', [$sid]);
                }
                (new FaceNullProvider())->purgeAuditImages($sid); // any old audit JPEGs on disk
            }
            AuditService::log($lid, 'face.retention', count($victims) . ' face template(s) deleted by retention policy (' . ($retention === '0' ? 'left class' : $retention . ' months') . ')', '', 'student', null, null, null, 'system', null, 'retention');
        }
        return $total;
    }
}
