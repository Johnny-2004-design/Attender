<?php
declare(strict_types=1);

namespace Attender\Controllers;

use Attender\Database;
use Attender\Middleware\AuthMiddleware;
use Attender\Services\CodeService;
use Attender\Services\QrTokenService;
use function Attender\Support\json_response;

final class DashboardController
{
    public static function dashboard(): void
    {
        $lec = AuthMiddleware::requireLecturer();
        $lid = (int) $lec['id'];

        $approved = (int) (Database::fetch(
            "SELECT COUNT(*) c FROM student_registrations WHERE lecturer_id = ? AND status = 'approved'",
            [$lid]
        )['c'] ?? 0);
        $classes = (int) (Database::fetch('SELECT COUNT(*) c FROM lecturer_classes WHERE lecturer_id = ?', [$lid])['c'] ?? 0);
        $courses = (int) (Database::fetch('SELECT COUNT(*) c FROM courses WHERE lecturer_id = ?', [$lid])['c'] ?? 0);
        $sessions = (int) (Database::fetch(
            "SELECT COUNT(*) c FROM attendance_sessions WHERE lecturer_id = ? AND status = 'closed'",
            [$lid]
        )['c'] ?? 0);

        $closed = Database::fetchAll(
            "SELECT s.*, c.code AS course_code, c.title AS course_title, cl.name AS class_name
             FROM attendance_sessions s
             JOIN courses c ON c.id = s.course_id
             JOIN lecturer_classes cl ON cl.id = s.class_id
             WHERE s.lecturer_id = ? AND s.status = 'closed'
             ORDER BY s.opened_at ASC",
            [$lid]
        );
        $trend = [];
        $rates = [];
        $byCourseMap = [];
        foreach ($closed as $s) {
            $stats = self::sessionStats((int) $s['id']);
            $rates[] = $stats['rate'];
            $trend[] = ['date' => $s['opened_at'], 'rate' => $stats['rate']];
            $code = $s['course_code'];
            if (!isset($byCourseMap[$code])) {
                $byCourseMap[$code] = [];
            }
            $byCourseMap[$code][] = $stats['rate'];
        }
        $byCourse = [];
        foreach ($byCourseMap as $code => $rs) {
            $byCourse[] = ['code' => $code, 'rate' => (int) round(array_sum($rs) / max(1, count($rs)))];
        }
        $avgRate = $rates ? (int) round(array_sum($rates) / count($rates)) : 0;

        $recentSessions = array_map(static function ($s) {
            $stats = DashboardController::sessionStats((int) $s['id']);
            return [
                'id' => (int) $s['id'],
                'date' => $s['opened_at'],
                'courseId' => (int) $s['course_id'],
                'classId' => (int) $s['class_id'],
                'stats' => $stats,
                'course' => ['code' => $s['course_code'], 'title' => $s['course_title']],
                'class' => ['name' => $s['class_name']],
            ];
        }, array_reverse(array_slice($closed, -5)));

        $pendingRows = Database::fetchAll(
            "SELECT r.id, r.created_at AS registeredAt, r.status, s.full_name AS name, s.index_number AS `index`,
                    s.programme, s.level, r.class_id AS classId
             FROM student_registrations r
             JOIN students s ON s.id = r.student_id
             WHERE r.lecturer_id = ? AND r.status = 'pending'
             ORDER BY r.created_at DESC LIMIT 4",
            [$lid]
        );
        $pendingCount = (int) (Database::fetch(
            "SELECT COUNT(*) c FROM student_registrations WHERE lecturer_id = ? AND status = 'pending'",
            [$lid]
        )['c'] ?? 0);

        $recentStudents = Database::fetchAll(
            "SELECT s.full_name AS name, s.index_number AS `index`, s.programme, s.level, r.created_at AS registeredAt
             FROM student_registrations r
             JOIN students s ON s.id = r.student_id
             WHERE r.lecturer_id = ? AND r.status = 'approved'
             ORDER BY r.created_at DESC LIMIT 5",
            [$lid]
        );

        json_response([
            'totals' => [
                'students' => $approved,
                'classes' => $classes,
                'courses' => $courses,
                'sessions' => $sessions,
                'avgRate' => $avgRate,
            ],
            'recentSessions' => array_values($recentSessions),
            'recentStudents' => $recentStudents,
            'pending' => $pendingRows,
            'pendingCount' => $pendingCount,
            'byCourse' => $byCourse,
            'trend' => $trend,
            'active' => self::activeSessionPayload($lid),
        ]);
    }

    public static function sessionStats(int $sessionId): array
    {
        $rows = Database::fetchAll(
            'SELECT status, COUNT(*) c FROM attendance_records WHERE session_id = ? GROUP BY status',
            [$sessionId]
        );
        $counts = ['present' => 0, 'late' => 0, 'absent' => 0, 'review' => 0, 'pending' => 0, 'failed' => 0];
        $total = 0;
        foreach ($rows as $r) {
            $counts[$r['status']] = (int) $r['c'];
            $total += (int) $r['c'];
        }
        $attended = $counts['present'] + $counts['late'];
        return [
            'total' => $total,
            'present' => $counts['present'],
            'late' => $counts['late'],
            'absent' => $counts['absent'],
            'review' => $counts['review'] + $counts['pending'],
            'rate' => $total ? (int) round(($attended / $total) * 100) : 0,
        ];
    }

    public static function activeSessionPayload(int $lecturerId): ?array
    {
        $s = Database::fetch(
            "SELECT * FROM attendance_sessions WHERE lecturer_id = ? AND status = 'open' ORDER BY id DESC LIMIT 1",
            [$lecturerId]
        );
        if (!$s) {
            return null;
        }
        $token = QrTokenService::currentPlain((int) $s['id']);
        $code = CodeService::currentPlain((int) $s['id']);
        $feed = Database::fetchAll(
            "SELECT r.status, r.marked_at AS time, r.distance_m AS distance, r.gps_accuracy_m AS accuracy,
                    r.face_similarity AS similarity, r.fail_reason AS reason, r.student_id AS studentId,
                    st.full_name AS name,
                    CASE WHEN r.status IN ('present','late','pending','review') THEN 1 ELSE 0 END AS ok
             FROM attendance_records r
             JOIN students st ON st.id = r.student_id
             WHERE r.session_id = ? AND r.status <> 'absent'
             ORDER BY r.marked_at DESC, r.id DESC LIMIT 40",
            [(int) $s['id']]
        );
        $feedOut = array_map(static function ($f) {
            $ok = (int) $f['ok'] === 1 && !in_array($f['status'], ['failed'], true);
            return [
                'ok' => $ok,
                'studentId' => (int) $f['studentId'],
                'name' => $f['name'],
                'status' => $f['status'],
                'time' => $f['time'],
                'distance' => $f['distance'] !== null ? (float) $f['distance'] : null,
                'accuracy' => $f['accuracy'] !== null ? (float) $f['accuracy'] : null,
                'similarity' => $f['similarity'] !== null ? (float) $f['similarity'] : null,
                'reason' => $f['reason'],
            ];
        }, $feed);

        $present = count(array_filter($feedOut, static fn ($f) => $f['ok'] && in_array($f['status'], ['present', 'late'], true)));

        return [
            'id' => (int) $s['id'],
            'courseId' => (int) $s['course_id'],
            'classId' => (int) $s['class_id'],
            'opened' => $s['opened_at'],
            'method' => $s['method'],
            'radius' => (int) $s['radius_m'],
            'duration' => $s['duration_min'] !== null ? (int) $s['duration_min'] : null,
            'lateAfter' => (int) $s['late_after_min'],
            'qrMode' => $s['qr_mode'],
            'qrMinutes' => (int) round(((int) $s['qr_rotate_sec']) / 60) ?: 5,
            'gps' => (int) $s['require_gps'] === 1,
            'face' => (int) $s['require_face'] === 1,
            'location' => $s['location_name'],
            'eligible' => (int) $s['eligible_count'],
            'token' => $token['token'] ?? null,
            'code' => $code['code'] ?? null,
            'codeExpires' => $code['expires_at'] ?? null,
            'tokenExpires' => $token['expires_at'] ?? null,
            'feed' => $feedOut,
            'presentCount' => $present,
        ];
    }
}
