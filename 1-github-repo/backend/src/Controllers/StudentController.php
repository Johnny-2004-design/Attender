<?php
declare(strict_types=1);

namespace Attender\Controllers;

use Attender\Database;
use Attender\Middleware\AuthMiddleware;
use Attender\Middleware\CsrfMiddleware;
use Attender\Middleware\LecturerScope;
use Attender\Services\AuditService;
use Attender\Services\FaceService;
use function Attender\Support\json_error;
use function Attender\Support\json_response;
use function Attender\Support\now_sql;
use function Attender\Support\request_json;

final class StudentController
{
    public static function list(): void
    {
        $lec = AuthMiddleware::requireLecturer();
        $lid = (int) $lec['id'];
        $rows = Database::fetchAll(
            "SELECT r.id AS reg_id, r.status, r.class_id AS classId, r.created_at AS registeredAt,
                    s.id, s.full_name AS name, s.index_number AS `index`, s.email, s.phone, s.programme, s.level,
                    cl.name AS class_name,
                    (SELECT COUNT(*) FROM facial_templates f WHERE f.student_id = s.id AND f.is_active = 1) AS face_count
             FROM student_registrations r
             JOIN students s ON s.id = r.student_id
             JOIN lecturer_classes cl ON cl.id = r.class_id
             WHERE r.lecturer_id = ? AND r.status = 'approved'
             ORDER BY s.full_name",
            [$lid]
        );
        $out = [];
        foreach ($rows as $row) {
            $enrolled = Database::fetchAll(
                'SELECT course_id FROM course_enrollments WHERE student_id = ? AND lecturer_id = ?',
                [(int) $row['id'], $lid]
            );
            $courseIds = array_map(static fn ($e) => (int) $e['course_id'], $enrolled);
            $codes = [];
            if ($courseIds) {
                $in = implode(',', array_fill(0, count($courseIds), '?'));
                $codes = array_column(Database::fetchAll("SELECT code FROM courses WHERE id IN ($in)", $courseIds), 'code');
            }
            $rec = Database::fetchAll(
                'SELECT status FROM attendance_records WHERE student_id = ? AND lecturer_id = ?',
                [(int) $row['id'], $lid]
            );
            $att = count(array_filter($rec, static fn ($r) => in_array($r['status'], ['present', 'late'], true)));
            $out[] = [
                'id' => (int) $row['id'],
                'name' => $row['name'],
                'index' => $row['index'],
                'email' => $row['email'],
                'phone' => $row['phone'],
                'programme' => $row['programme'],
                'level' => $row['level'],
                'classId' => (int) $row['classId'],
                'status' => $row['status'],
                'face' => (int) $row['face_count'] > 0,
                'registeredAt' => $row['registeredAt'],
                'enrolled' => $courseIds,
                'courseCodes' => $codes,
                'class' => ['id' => (int) $row['classId'], 'name' => $row['class_name']],
                'rate' => $rec ? (int) round(($att / count($rec)) * 100) : null,
            ];
        }
        json_response($out);
    }

    public static function one(): void
    {
        $lec = AuthMiddleware::requireLecturer();
        $id = (int) ($_GET['id'] ?? 0);
        $reg = Database::fetch(
            'SELECT r.*, s.full_name, s.index_number, s.email, s.phone, s.programme, s.level, s.id AS sid
             FROM student_registrations r JOIN students s ON s.id = r.student_id
             WHERE r.student_id = ? AND r.lecturer_id = ? LIMIT 1',
            [$id, (int) $lec['id']]
        );
        if (!$reg) {
            json_error('Student not found.', 404);
            return;
        }
        $class = Database::fetch('SELECT * FROM lecturer_classes WHERE id = ?', [(int) $reg['class_id']]);
        $records = Database::fetchAll(
            'SELECT r.*, s.opened_at, c.code, c.title FROM attendance_records r
             JOIN attendance_sessions s ON s.id = r.session_id
             JOIN courses c ON c.id = s.course_id
             WHERE r.student_id = ? AND r.lecturer_id = ?
             ORDER BY s.opened_at DESC',
            [$id, (int) $lec['id']]
        );
        $enrolled = array_map(
            static fn ($e) => (int) $e['course_id'],
            Database::fetchAll('SELECT course_id FROM course_enrollments WHERE student_id = ? AND lecturer_id = ?', [$id, (int) $lec['id']])
        );
        json_response([
            'student' => [
                'id' => (int) $reg['sid'],
                'name' => $reg['full_name'],
                'index' => $reg['index_number'],
                'email' => $reg['email'],
                'phone' => $reg['phone'],
                'programme' => $reg['programme'],
                'level' => $reg['level'],
                'classId' => (int) $reg['class_id'],
                'status' => $reg['status'],
                'enrolled' => $enrolled,
            ],
            'class' => $class ? ['id' => (int) $class['id'], 'name' => $class['name']] : null,
            'records' => array_map(static function ($r) {
                return [
                    'id' => (int) $r['id'],
                    'status' => $r['status'],
                    'time' => $r['marked_at'],
                    'session' => ['id' => (int) $r['session_id'], 'date' => $r['opened_at']],
                    'course' => ['code' => $r['code'], 'title' => $r['title']],
                ];
            }, $records),
        ]);
    }

    public static function setEnrollment(): void
    {
        $lec = AuthMiddleware::requireLecturer();
        CsrfMiddleware::requireValid();
        $body = request_json();
        $studentId = (int) ($body['studentId'] ?? 0);
        $courseIds = array_map('intval', $body['courseIds'] ?? []);
        $lid = (int) $lec['id'];
        $reg = Database::fetch(
            "SELECT * FROM student_registrations WHERE student_id = ? AND lecturer_id = ? AND status = 'approved' LIMIT 1",
            [$studentId, $lid]
        );
        if (!$reg) {
            json_error('Approved registration not found.', 404);
            return;
        }
        Database::begin();
        try {
            Database::exec('DELETE FROM course_enrollments WHERE student_id = ? AND lecturer_id = ?', [$studentId, $lid]);
            foreach ($courseIds as $cid) {
                LecturerScope::assertOwns('courses', $cid, $lid);
                $course = Database::fetch('SELECT class_id FROM courses WHERE id = ?', [$cid]);
                if ((int) $course['class_id'] !== (int) $reg['class_id']) {
                    continue;
                }
                Database::insert(
                    'INSERT INTO course_enrollments (lecturer_id, course_id, registration_id, student_id, enrolled_at)
                     VALUES (?, ?, ?, ?, ?)',
                    [$lid, $cid, (int) $reg['id'], $studentId, now_sql()]
                );
            }
            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
        json_response(['ok' => true]);
    }

    public static function requests(): void
    {
        $lec = AuthMiddleware::requireLecturer();
        $rows = Database::fetchAll(
            "SELECT r.id, r.created_at AS registeredAt, r.status, r.class_id AS classId,
                    s.id AS student_id, s.full_name AS name, s.index_number AS `index`, s.email, s.phone, s.programme, s.level,
                    cl.name AS class_name
             FROM student_registrations r
             JOIN students s ON s.id = r.student_id
             JOIN lecturer_classes cl ON cl.id = r.class_id
             WHERE r.lecturer_id = ? AND r.status = 'pending'
             ORDER BY r.created_at ASC",
            [(int) $lec['id']]
        );
        json_response(array_map(static function ($r) {
            return [
                'id' => (int) $r['student_id'],
                'regId' => (int) $r['id'],
                'name' => $r['name'],
                'index' => $r['index'],
                'email' => $r['email'],
                'phone' => $r['phone'],
                'programme' => $r['programme'],
                'level' => $r['level'],
                'classId' => (int) $r['classId'],
                'status' => $r['status'],
                'registeredAt' => $r['registeredAt'],
                'class' => ['id' => (int) $r['classId'], 'name' => $r['class_name']],
            ];
        }, $rows));
    }

    public static function decideRequest(): void
    {
        $lec = AuthMiddleware::requireLecturer();
        CsrfMiddleware::requireValid();
        $body = request_json();
        $studentId = (int) ($body['id'] ?? 0);
        $decision = (string) ($body['decision'] ?? '');
        $reason = trim((string) ($body['reason'] ?? ''));
        if (!in_array($decision, ['approved', 'rejected'], true)) {
            json_error('Decision must be approved or rejected.');
            return;
        }
        $reg = Database::fetch(
            "SELECT r.*, s.full_name FROM student_registrations r JOIN students s ON s.id = r.student_id
             WHERE r.student_id = ? AND r.lecturer_id = ? AND r.status = 'pending' LIMIT 1",
            [$studentId, (int) $lec['id']]
        );
        if (!$reg) {
            json_error('Request not found.', 404);
            return;
        }
        Database::exec(
            'UPDATE student_registrations SET status=?, reject_reason=?, decided_at=? WHERE id=?',
            [$decision, $decision === 'rejected' ? $reason : null, now_sql(), (int) $reg['id']]
        );
        $class = Database::fetch('SELECT name FROM lecturer_classes WHERE id = ?', [(int) $reg['class_id']]);
        AuditService::log(
            (int) $lec['id'],
            'Registration ' . $decision,
            $reg['full_name'] . ' · ' . ($class['name'] ?? ''),
            $reason,
            'student_registration',
            (int) $reg['id'],
            ['status' => 'pending'],
            ['status' => $decision],
            'lecturer',
            (int) $lec['id'],
            $lec['name']
        );
        // Auto-enrol in all class courses when approved
        if ($decision === 'approved') {
            $courses = Database::fetchAll(
                'SELECT id FROM courses WHERE class_id = ? AND lecturer_id = ?',
                [(int) $reg['class_id'], (int) $lec['id']]
            );
            foreach ($courses as $c) {
                Database::query(
                    'INSERT IGNORE INTO course_enrollments (lecturer_id, course_id, registration_id, student_id, enrolled_at)
                     VALUES (?, ?, ?, ?, ?)',
                    [(int) $lec['id'], (int) $c['id'], (int) $reg['id'], $studentId, now_sql()]
                );
            }
        }
        json_response(['id' => $studentId, 'status' => $decision]);
    }

    public static function inviteInfo(): void
    {
        $token = (string) ($_GET['token'] ?? '');
        $inv = Database::fetch('SELECT * FROM class_invitations WHERE token = ? LIMIT 1', [$token]);
        if (!$inv) {
            // soft fallback for empty token in UI preview paths
            json_response(['valid' => false, 'reason' => 'Invitation not found.']);
            return;
        }
        $valid = $inv['status'] === 'active'
            && strtotime((string) $inv['expires_at']) >= time()
            && (int) $inv['use_count'] < (int) $inv['max_uses'];
        $class = Database::fetch('SELECT * FROM lecturer_classes WHERE id = ?', [(int) $inv['class_id']]);
        $lecturer = Database::fetch('SELECT id, name, title, department, institution FROM lecturers WHERE id = ?', [(int) $inv['lecturer_id']]);
        json_response([
            'valid' => $valid,
            'token' => $inv['token'],
            'class' => $class ? [
                'id' => (int) $class['id'],
                'name' => $class['name'],
                'programme' => $class['programme'],
                'level' => $class['level'],
                'year' => $class['academic_year'],
            ] : null,
            'lecturer' => $lecturer ? [
                'id' => (int) $lecturer['id'],
                'name' => $lecturer['name'],
                'title' => $lecturer['title'],
                'department' => $lecturer['department'],
                'institution' => $lecturer['institution'],
            ] : null,
        ]);
    }

    public static function submitRegistration(): void
    {
        \Attender\Middleware\CsrfMiddleware::requireValid();
        $body = request_json();
        $token = (string) ($body['token'] ?? '');
        $inv = Database::fetch('SELECT * FROM class_invitations WHERE token = ? LIMIT 1', [$token]);
        if (!$inv || $inv['status'] !== 'active' || strtotime((string) $inv['expires_at']) < time()) {
            json_error('Invitation is invalid or expired.');
            return;
        }
        if ((int) $inv['use_count'] >= (int) $inv['max_uses']) {
            json_error('Invitation has reached its maximum uses.');
            return;
        }
        $name = trim((string) ($body['name'] ?? ''));
        $index = strtoupper(trim((string) ($body['index'] ?? '')));
        $email = strtolower(trim((string) ($body['email'] ?? '')));
        $phone = trim((string) ($body['phone'] ?? ''));
        $programme = trim((string) ($body['programme'] ?? ''));
        $level = trim((string) ($body['level'] ?? ''));
        if ($name === '' || $index === '') {
            json_error('Name and index number are required.');
            return;
        }
        $classId = (int) $inv['class_id'];
        $lecturerId = (int) $inv['lecturer_id'];

        $student = Database::fetch('SELECT * FROM students WHERE index_number = ?', [$index]);
        if ($student) {
            $dup = Database::fetch(
                'SELECT * FROM student_registrations WHERE class_id = ? AND student_id = ?',
                [$classId, (int) $student['id']]
            );
            if ($dup) {
                json_response(['ok' => false, 'existing' => true, 'status' => $dup['status'], 'name' => $student['full_name']]);
                return;
            }
            $studentId = (int) $student['id'];
            Database::exec(
                'UPDATE students SET full_name=?, email=?, phone=?, programme=?, level=? WHERE id=?',
                [$name, $email ?: null, $phone ?: null, $programme ?: null, $level ?: null, $studentId]
            );
        } else {
            $studentId = Database::insert(
                'INSERT INTO students (full_name, index_number, email, phone, programme, level, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$name, $index, $email ?: null, $phone ?: null, $programme ?: null, $level ?: null, now_sql()]
            );
        }

        $images = $body['faceImages'] ?? [];
        if (!is_array($images) || count($images) < 1) {
            json_error('Face capture is required. Take the enrolment photos and try again.');
            return;
        }

        $face = FaceService::make();
        // http/onnx always require a real embedding; null never claims enrollment success.
        $requireEmbedding = FaceService::requiresEmbedding() || in_array($face->providerName(), ['http', 'onnx'], true);
        $status = !empty($inv['auto_approve']) ? 'approved' : 'pending';
        $faceEnrolled = false;
        $enrollReason = null;

        Database::begin();
        try {
            $enroll = $face->enroll($studentId, $lecturerId, $images);
            $faceEnrolled = !empty($enroll['face_enrolled']) && !empty($enroll['ok']);
            $enrollReason = $enroll['reason'] ?? null;

            if ($requireEmbedding && !$faceEnrolled) {
                Database::rollBack();
                json_response([
                    'ok' => false,
                    'registration' => false,
                    'face_enrolled' => false,
                    'reason' => $enrollReason ?? 'Face enrollment failed. Retake photos and try again.',
                    'retry_face' => true,
                ], 422);
                return;
            }

            $regId = Database::insert(
                'INSERT INTO student_registrations
                 (lecturer_id, class_id, student_id, status, face_enrolled, consented_at, decided_at, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $lecturerId, $classId, $studentId, $status, $faceEnrolled ? 1 : 0, now_sql(),
                    $status === 'approved' ? now_sql() : null, now_sql(),
                ]
            );
            Database::exec('UPDATE class_invitations SET use_count = use_count + 1 WHERE id = ?', [(int) $inv['id']]);

            if ($status === 'approved') {
                $courses = Database::fetchAll('SELECT id FROM courses WHERE class_id = ? AND lecturer_id = ?', [$classId, $lecturerId]);
                foreach ($courses as $c) {
                    Database::insert(
                        'INSERT INTO course_enrollments (lecturer_id, course_id, registration_id, student_id, enrolled_at) VALUES (?, ?, ?, ?, ?)',
                        [$lecturerId, (int) $c['id'], $regId, $studentId, now_sql()]
                    );
                }
            }
            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }

        json_response([
            'ok' => true,
            'registration' => true,
            'status' => $status,
            'face_enrolled' => $faceEnrolled,
            'face_reason' => $faceEnrolled ? null : ($enrollReason ?? 'Face embedding not created.'),
        ]);
    }

    /**
     * Lecturer-scoped biometric delete:
     * ownership → remote HTTP+JSON ok===true+absent → local templates/images/DB → audit.
     * Never silent success; remote/local fail → pending_deletion + retry.
     */
    public static function deleteFace(): void
    {
        $lec = \Attender\Middleware\AuthMiddleware::requireLecturer();
        \Attender\Middleware\CsrfMiddleware::requireValid();
        $body = request_json();
        $studentId = (int) ($body['studentId'] ?? 0);
        $reg = Database::fetch(
            'SELECT id FROM student_registrations WHERE student_id = ? AND lecturer_id = ? LIMIT 1',
            [$studentId, (int) $lec['id']]
        );
        if (!$reg) {
            json_error('Student not found in your classes.', 404);
            return;
        }

        $result = FaceService::make()->deleteEnrollment($studentId, (int) $lec['id']);
        if (empty($result['ok'])) {
            AuditService::log(
                (int) $lec['id'],
                'face.delete.failed',
                'Biometric delete not confirmed',
                (string) ($result['reason'] ?? 'delete failed'),
                'student',
                $studentId,
                null,
                ['pending_deletion' => !empty($result['pending_deletion'])],
                'lecturer',
                (int) $lec['id'],
                (string) ($lec['full_name'] ?? '')
            );
            json_response([
                'ok' => false,
                'face_enrolled' => true,
                'pending_deletion' => !empty($result['pending_deletion']),
                'reason' => $result['reason'] ?? 'Face biometric delete failed. Retry.',
                'retry' => true,
            ], 502);
            return;
        }

        Database::exec(
            'UPDATE student_registrations SET face_enrolled = 0 WHERE student_id = ? AND lecturer_id = ?',
            [$studentId, (int) $lec['id']]
        );
        AuditService::log(
            (int) $lec['id'],
            'face.delete',
            'Biometric enrollment deleted (remote absent + local cleared)',
            '',
            'student',
            $studentId,
            ['face_enrolled' => true],
            ['face_enrolled' => false],
            'lecturer',
            (int) $lec['id'],
            (string) ($lec['full_name'] ?? '')
        );
        json_response([
            'ok' => true,
            'face_enrolled' => false,
            'pending_deletion' => false,
            'remote_absent' => !empty($result['remote_absent']),
        ]);
    }
}
