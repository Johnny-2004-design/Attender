<?php
declare(strict_types=1);

namespace Attender\Controllers;

use Attender\Database;
use Attender\Middleware\AuthMiddleware;
use Attender\Middleware\CsrfMiddleware;
use Attender\Middleware\LecturerScope;
use Attender\Services\AuditService;
use function Attender\Support\json_error;
use function Attender\Support\json_response;
use function Attender\Support\now_sql;
use function Attender\Support\request_json;

final class RecordController
{
    public static function list(): void
    {
        $lec = AuthMiddleware::requireLecturer();
        $rows = Database::fetchAll(
            'SELECT r.*, st.full_name, st.index_number, st.id AS student_pk,
                    s.opened_at, s.course_id, s.class_id,
                    c.code AS course_code, c.title AS course_title,
                    cl.name AS class_name
             FROM attendance_records r
             JOIN students st ON st.id = r.student_id
             JOIN attendance_sessions s ON s.id = r.session_id
             JOIN courses c ON c.id = s.course_id
             JOIN lecturer_classes cl ON cl.id = s.class_id
             WHERE r.lecturer_id = ?
             ORDER BY s.opened_at DESC, st.full_name ASC',
            [(int) $lec['id']]
        );
        json_response(array_map(static function ($r) {
            return [
                'id' => (int) $r['id'],
                'sessionId' => (int) $r['session_id'],
                'studentId' => (int) $r['student_pk'],
                'status' => $r['status'] === 'pending' ? 'review' : $r['status'],
                'time' => $r['marked_at'],
                'distance' => $r['distance_m'] !== null ? (float) $r['distance_m'] : null,
                'accuracy' => $r['gps_accuracy_m'] !== null ? (float) $r['gps_accuracy_m'] : null,
                'similarity' => $r['face_similarity'] !== null ? (float) $r['face_similarity'] : null,
                'student' => [
                    'id' => (int) $r['student_pk'],
                    'name' => $r['full_name'],
                    'index' => $r['index_number'],
                    'classId' => (int) $r['class_id'],
                ],
                'session' => [
                    'id' => (int) $r['session_id'],
                    'date' => $r['opened_at'],
                    'courseId' => (int) $r['course_id'],
                ],
                'course' => ['code' => $r['course_code'], 'title' => $r['course_title']],
                'class' => ['name' => $r['class_name']],
            ];
        }, $rows));
    }

    public static function correct(): void
    {
        $lec = AuthMiddleware::requireLecturer();
        CsrfMiddleware::requireValid();
        $body = request_json();
        $id = (int) ($body['id'] ?? 0);
        $status = (string) ($body['status'] ?? '');
        $reason = trim((string) ($body['reason'] ?? ''));
        if (!in_array($status, ['present', 'late', 'absent', 'review'], true)) {
            json_error('Invalid status.');
            return;
        }
        if ($reason === '') {
            json_error('A reason is required for corrections.');
            return;
        }
        LecturerScope::assertOwns('attendance_records', $id, (int) $lec['id']);
        $row = Database::fetch(
            'SELECT r.*, st.full_name, c.code FROM attendance_records r
             JOIN students st ON st.id = r.student_id
             JOIN attendance_sessions s ON s.id = r.session_id
             JOIN courses c ON c.id = s.course_id
             WHERE r.id = ?',
            [$id]
        );
        $prev = $row['status'];
        $dbStatus = $status === 'review' ? 'review' : $status;
        Database::exec(
            'UPDATE attendance_records SET status=?, corrected_from=?, correction_reason=?, marked_at=COALESCE(marked_at, ?) WHERE id=?',
            [$dbStatus, $prev, $reason, now_sql(), $id]
        );
        AuditService::log(
            (int) $lec['id'],
            'Attendance corrected',
            $row['full_name'] . ' · ' . $row['code'] . ' · ' . strtoupper((string) $prev) . ' → ' . strtoupper($status),
            $reason,
            'attendance_record',
            $id,
            ['status' => $prev],
            ['status' => $dbStatus],
            'lecturer',
            (int) $lec['id'],
            $lec['name']
        );
        $row['status'] = $status;
        json_response([
            'id' => $id,
            'status' => $status,
            'time' => $row['marked_at'] ?: now_sql(),
        ]);
    }

    public static function audit(): void
    {
        $lec = AuthMiddleware::requireLecturer();
        $rows = Database::fetchAll(
            'SELECT * FROM audit_logs WHERE lecturer_id = ? ORDER BY created_at DESC LIMIT 200',
            [(int) $lec['id']]
        );
        json_response(array_map(static function ($a) {
            return [
                'id' => (int) $a['id'],
                'at' => $a['created_at'],
                'actor' => $a['actor_name'] ?: 'System',
                'action' => $a['action'],
                'detail' => $a['detail'],
                'reason' => $a['reason'],
            ];
        }, $rows));
    }
}
