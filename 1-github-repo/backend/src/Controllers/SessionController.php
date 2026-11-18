<?php
declare(strict_types=1);

namespace Attender\Controllers;

use Attender\Database;
use Attender\Middleware\AuthMiddleware;
use Attender\Middleware\CsrfMiddleware;
use Attender\Middleware\LecturerScope;
use Attender\Services\AuditService;
use Attender\Services\CodeService;
use Attender\Services\QrTokenService;
use Attender\Services\SettingsService;
use function Attender\Support\json_error;
use function Attender\Support\json_response;
use function Attender\Support\now_sql;
use function Attender\Support\request_json;

final class SessionController
{
    public static function list(): void
    {
        $lec = AuthMiddleware::requireLecturer();
        $rows = Database::fetchAll(
            "SELECT s.*, c.code AS course_code, c.title AS course_title, cl.name AS class_name
             FROM attendance_sessions s
             JOIN courses c ON c.id = s.course_id
             JOIN lecturer_classes cl ON cl.id = s.class_id
             WHERE s.lecturer_id = ?
             ORDER BY s.opened_at DESC",
            [(int) $lec['id']]
        );
        json_response(array_map(static function ($s) {
            return [
                'id' => (int) $s['id'],
                'courseId' => (int) $s['course_id'],
                'classId' => (int) $s['class_id'],
                'date' => $s['opened_at'],
                'start' => $s['opened_at'],
                'end' => $s['closed_at'],
                'method' => $s['method'],
                'radius' => (int) $s['radius_m'],
                'location' => $s['location_name'],
                'status' => $s['status'],
                'qrMinutes' => (int) round(((int) $s['qr_rotate_sec']) / 60) ?: 5,
                'lateAfter' => (int) $s['late_after_min'],
                'stats' => DashboardController::sessionStats((int) $s['id']),
                'course' => ['code' => $s['course_code'], 'title' => $s['course_title']],
                'class' => ['name' => $s['class_name']],
            ];
        }, $rows));
    }

    public static function one(): void
    {
        $lec = AuthMiddleware::requireLecturer();
        $id = (int) ($_GET['id'] ?? 0);
        LecturerScope::assertOwns('attendance_sessions', $id, (int) $lec['id']);
        $s = Database::fetch(
            'SELECT s.*, c.code AS course_code, c.title AS course_title, cl.name AS class_name
             FROM attendance_sessions s
             JOIN courses c ON c.id = s.course_id
             JOIN lecturer_classes cl ON cl.id = s.class_id
             WHERE s.id = ?',
            [$id]
        );
        json_response([
            'id' => (int) $s['id'],
            'courseId' => (int) $s['course_id'],
            'classId' => (int) $s['class_id'],
            'date' => $s['opened_at'],
            'start' => $s['opened_at'],
            'end' => $s['closed_at'],
            'method' => $s['method'],
            'radius' => (int) $s['radius_m'],
            'location' => $s['location_name'],
            'status' => $s['status'],
            'stats' => DashboardController::sessionStats($id),
            'course' => ['code' => $s['course_code'], 'title' => $s['course_title']],
            'class' => ['name' => $s['class_name']],
        ]);
    }

    public static function active(): void
    {
        $lec = AuthMiddleware::requireLecturer();
        json_response(DashboardController::activeSessionPayload((int) $lec['id']));
    }

    public static function open(): void
    {
        $lec = AuthMiddleware::requireLecturer();
        CsrfMiddleware::requireValid();
        $body = request_json();
        $lid = (int) $lec['id'];
        $courseId = (int) ($body['courseId'] ?? 0);
        LecturerScope::assertOwns('courses', $courseId, $lid);

        $open = Database::fetch(
            "SELECT id FROM attendance_sessions WHERE lecturer_id = ? AND status = 'open' LIMIT 1",
            [$lid]
        );
        if ($open) {
            json_error('Close the current live session before opening another.');
            return;
        }

        $course = Database::fetch('SELECT * FROM courses WHERE id = ?', [$courseId]);
        $settings = SettingsService::get($lid);
        $eligible = (int) (Database::fetch(
            'SELECT COUNT(*) c FROM course_enrollments WHERE course_id = ?',
            [$courseId]
        )['c'] ?? 0);

        $method = (string) ($body['method'] ?? 'qr');
        if (!in_array($method, ['qr', 'code', 'both'], true)) {
            $method = 'qr';
        }
        $radius = (int) ($body['radius'] ?? $settings['defaultRadius']);
        $lateAfter = (int) ($body['lateAfter'] ?? 10);
        $duration = isset($body['duration']) ? (int) $body['duration'] : null;
        $qrMode = (string) ($body['qrMode'] ?? 'rotate');
        if (!in_array($qrMode, ['rotate', 'fixed', 'print'], true)) {
            $qrMode = 'rotate';
        }
        $qrMinutes = (int) ($body['qrMinutes'] ?? 5);
        $gps = array_key_exists('gps', $body) ? (bool) $body['gps'] : (bool) $settings['requireGps'];
        $face = array_key_exists('face', $body) ? (bool) $body['face'] : (bool) $settings['requireFace'];
        $location = trim((string) ($body['location'] ?? $settings['locName'] ?? ''));
        $lat = isset($body['lat']) ? (float) $body['lat'] : (float) $settings['lat'];
        $lng = isset($body['lng']) ? (float) $body['lng'] : (float) $settings['lng'];

        $id = Database::insert(
            'INSERT INTO attendance_sessions
             (lecturer_id, course_id, class_id, method, status, opened_at, duration_min, late_after_min, radius_m,
              require_gps, require_face, qr_mode, qr_rotate_sec, location_name, latitude, longitude, eligible_count, created_at)
             VALUES (?, ?, ?, ?, \'open\', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $lid, $courseId, (int) $course['class_id'], $method, now_sql(), $duration, $lateAfter, $radius,
                $gps ? 1 : 0, $face ? 1 : 0, $qrMode,
                max(15, (int) ($settings['qrRotateSeconds'] ?? 30)),
                $location, $lat, $lng, $eligible, now_sql(),
            ]
        );

        $tok = QrTokenService::issue($id, $qrMode === 'rotate' ? (int) ($settings['qrRotateSeconds'] ?? 30) : max(60, $qrMinutes * 60));
        $code = CodeService::issue($id);

        AuditService::log($lid, 'Session opened', $course['code'], '', 'attendance_session', $id, null, null, 'lecturer', $lid, $lec['name']);

        json_response(DashboardController::activeSessionPayload($lid));
    }

    public static function rotateToken(): void
    {
        $lec = AuthMiddleware::requireLecturer();
        CsrfMiddleware::requireValid();
        $active = DashboardController::activeSessionPayload((int) $lec['id']);
        if (!$active) {
            json_error('No open session.', 404);
            return;
        }
        $settings = SettingsService::get((int) $lec['id']);
        QrTokenService::issue((int) $active['id'], (int) ($settings['qrRotateSeconds'] ?? 30));
        json_response(DashboardController::activeSessionPayload((int) $lec['id']));
    }

    public static function newCode(): void
    {
        $lec = AuthMiddleware::requireLecturer();
        CsrfMiddleware::requireValid();
        $active = DashboardController::activeSessionPayload((int) $lec['id']);
        if (!$active) {
            json_error('No open session.', 404);
            return;
        }
        CodeService::issue((int) $active['id']);
        json_response(DashboardController::activeSessionPayload((int) $lec['id']));
    }

    public static function patchActive(): void
    {
        $lec = AuthMiddleware::requireLecturer();
        CsrfMiddleware::requireValid();
        $body = request_json();
        $s = Database::fetch(
            "SELECT * FROM attendance_sessions WHERE lecturer_id = ? AND status = 'open' LIMIT 1",
            [(int) $lec['id']]
        );
        if (!$s) {
            json_error('No open session.', 404);
            return;
        }
        $sets = [];
        $params = [];
        if (isset($body['location'])) {
            $sets[] = 'location_name = ?';
            $params[] = trim((string) $body['location']);
        }
        if (isset($body['radius'])) {
            $sets[] = 'radius_m = ?';
            $params[] = (int) $body['radius'];
        }
        if (isset($body['lat'], $body['lng'])) {
            $sets[] = 'latitude = ?';
            $params[] = (float) $body['lat'];
            $sets[] = 'longitude = ?';
            $params[] = (float) $body['lng'];
        }
        if ($sets) {
            $params[] = (int) $s['id'];
            Database::exec('UPDATE attendance_sessions SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);
        }
        json_response(DashboardController::activeSessionPayload((int) $lec['id']));
    }

    public static function close(): void
    {
        $lec = AuthMiddleware::requireLecturer();
        CsrfMiddleware::requireValid();
        $lid = (int) $lec['id'];
        $s = Database::fetch(
            "SELECT * FROM attendance_sessions WHERE lecturer_id = ? AND status = 'open' LIMIT 1",
            [$lid]
        );
        if (!$s) {
            json_response(null);
            return;
        }
        $sid = (int) $s['id'];
        Database::begin();
        try {
            // Mark enrolled students without a successful/pending record as absent
            $enrolled = Database::fetchAll(
                'SELECT student_id FROM course_enrollments WHERE course_id = ?',
                [(int) $s['course_id']]
            );
            foreach ($enrolled as $e) {
                $exists = Database::fetch(
                    'SELECT id FROM attendance_records WHERE session_id = ? AND student_id = ?',
                    [$sid, (int) $e['student_id']]
                );
                if (!$exists) {
                    Database::insert(
                        'INSERT INTO attendance_records (session_id, lecturer_id, student_id, status, created_at)
                         VALUES (?, ?, ?, \'absent\', ?)',
                        [$sid, $lid, (int) $e['student_id'], now_sql()]
                    );
                }
            }
            Database::exec(
                "UPDATE attendance_sessions SET status = 'closed', closed_at = ? WHERE id = ?",
                [now_sql(), $sid]
            );
            Database::exec('UPDATE attendance_tokens SET is_active = 0, token_plain = NULL WHERE session_id = ?', [$sid]);
            Database::exec('UPDATE attendance_codes SET is_active = 0, code_plain = NULL WHERE session_id = ?', [$sid]);
            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
        AuditService::log($lid, 'Session closed', 'Session #' . $sid, '', 'attendance_session', $sid, null, null, 'lecturer', $lid, $lec['name']);
        $closed = Database::fetch('SELECT * FROM attendance_sessions WHERE id = ?', [$sid]);
        json_response([
            'id' => $sid,
            'courseId' => (int) $closed['course_id'],
            'classId' => (int) $closed['class_id'],
            'date' => $closed['opened_at'],
            'start' => $closed['opened_at'],
            'end' => $closed['closed_at'],
            'method' => $closed['method'],
            'radius' => (int) $closed['radius_m'],
            'location' => $closed['location_name'],
            'status' => 'closed',
            'stats' => DashboardController::sessionStats($sid),
        ]);
    }
}
