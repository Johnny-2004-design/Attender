<?php
declare(strict_types=1);

namespace Attender\Controllers;

use Attender\Database;
use Attender\Middleware\AuthMiddleware;
use Attender\Middleware\CsrfMiddleware;
use Attender\Middleware\LecturerScope;
use function Attender\Support\json_error;
use function Attender\Support\json_response;
use function Attender\Support\now_sql;
use function Attender\Support\request_json;

final class CourseController
{
    public static function list(): void
    {
        $lec = AuthMiddleware::requireLecturer();
        $rows = Database::fetchAll(
            'SELECT co.*, cl.name AS class_name,
                (SELECT COUNT(*) FROM course_enrollments e WHERE e.course_id = co.id) AS enrolled,
                (SELECT COUNT(*) FROM attendance_sessions s WHERE s.course_id = co.id) AS sessions
             FROM courses co
             JOIN lecturer_classes cl ON cl.id = co.class_id
             WHERE co.lecturer_id = ? ORDER BY co.code',
            [(int) $lec['id']]
        );
        json_response(array_map(static function ($c) {
            return [
                'id' => (int) $c['id'],
                'code' => $c['code'],
                'title' => $c['title'],
                'classId' => (int) $c['class_id'],
                'semester' => $c['semester'],
                'year' => $c['academic_year'],
                'description' => $c['description'],
                'enrolled' => (int) $c['enrolled'],
                'sessions' => (int) $c['sessions'],
                'class' => ['id' => (int) $c['class_id'], 'name' => $c['class_name']],
            ];
        }, $rows));
    }

    public static function save(): void
    {
        $lec = AuthMiddleware::requireLecturer();
        CsrfMiddleware::requireValid();
        $body = request_json();
        $lid = (int) $lec['id'];
        $code = trim((string) ($body['code'] ?? ''));
        $title = trim((string) ($body['title'] ?? ''));
        $classId = (int) ($body['classId'] ?? 0);
        if ($code === '' || $title === '' || !$classId) {
            json_error('Code, title and class are required.');
            return;
        }
        LecturerScope::assertOwns('lecturer_classes', $classId, $lid);
        $semester = trim((string) ($body['semester'] ?? ''));
        $year = trim((string) ($body['year'] ?? $body['academic_year'] ?? ''));
        $description = trim((string) ($body['description'] ?? ''));
        if (!empty($body['id'])) {
            LecturerScope::assertOwns('courses', (int) $body['id'], $lid);
            Database::exec(
                'UPDATE courses SET class_id=?, code=?, title=?, semester=?, academic_year=?, description=? WHERE id=? AND lecturer_id=?',
                [$classId, $code, $title, $semester, $year, $description, (int) $body['id'], $lid]
            );
            $id = (int) $body['id'];
        } else {
            $id = Database::insert(
                'INSERT INTO courses (lecturer_id, class_id, code, title, semester, academic_year, description, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$lid, $classId, $code, $title, $semester, $year, $description, now_sql()]
            );
        }
        $row = Database::fetch('SELECT * FROM courses WHERE id = ?', [$id]);
        json_response([
            'id' => (int) $row['id'],
            'code' => $row['code'],
            'title' => $row['title'],
            'classId' => (int) $row['class_id'],
            'semester' => $row['semester'],
            'year' => $row['academic_year'],
            'description' => $row['description'],
        ]);
    }

    public static function delete(): void
    {
        $lec = AuthMiddleware::requireLecturer();
        CsrfMiddleware::requireValid();
        $body = request_json();
        $id = (int) ($body['id'] ?? 0);
        LecturerScope::assertOwns('courses', $id, (int) $lec['id']);
        Database::exec('DELETE FROM courses WHERE id = ? AND lecturer_id = ?', [$id, (int) $lec['id']]);
        json_response(['ok' => true]);
    }
}
