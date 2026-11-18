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
use function Attender\Support\random_token;
use function Attender\Support\request_json;

final class ClassController
{
    public static function list(): void
    {
        $lec = AuthMiddleware::requireLecturer();
        $lid = (int) $lec['id'];
        $rows = Database::fetchAll(
            'SELECT c.*,
                (SELECT COUNT(*) FROM student_registrations r WHERE r.class_id = c.id AND r.status = \'approved\') AS students,
                (SELECT COUNT(*) FROM courses co WHERE co.class_id = c.id) AS courses,
                (SELECT COUNT(*) FROM student_registrations r WHERE r.class_id = c.id AND r.status = \'pending\') AS pending
             FROM lecturer_classes c WHERE c.lecturer_id = ? ORDER BY c.name',
            [$lid]
        );
        json_response(array_map(static function ($c) {
            return [
                'id' => (int) $c['id'],
                'name' => $c['name'],
                'programme' => $c['programme'],
                'department' => $c['department'],
                'level' => $c['level'],
                'year' => $c['academic_year'],
                'description' => $c['description'],
                'students' => (int) $c['students'],
                'courses' => (int) $c['courses'],
                'pending' => (int) $c['pending'],
            ];
        }, $rows));
    }

    public static function save(): void
    {
        $lec = AuthMiddleware::requireLecturer();
        CsrfMiddleware::requireValid();
        $body = request_json();
        $lid = (int) $lec['id'];
        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            json_error('Class name is required.');
            return;
        }
        $data = [
            'name' => $name,
            'programme' => trim((string) ($body['programme'] ?? '')),
            'department' => trim((string) ($body['department'] ?? '')),
            'level' => trim((string) ($body['level'] ?? '')),
            'academic_year' => trim((string) ($body['year'] ?? $body['academic_year'] ?? '')),
            'description' => trim((string) ($body['description'] ?? '')),
        ];
        if (!empty($body['id'])) {
            LecturerScope::assertOwns('lecturer_classes', (int) $body['id'], $lid);
            Database::exec(
                'UPDATE lecturer_classes SET name=?, programme=?, department=?, level=?, academic_year=?, description=? WHERE id=? AND lecturer_id=?',
                [$data['name'], $data['programme'], $data['department'], $data['level'], $data['academic_year'], $data['description'], (int) $body['id'], $lid]
            );
            $id = (int) $body['id'];
        } else {
            $id = Database::insert(
                'INSERT INTO lecturer_classes (lecturer_id, name, programme, department, level, academic_year, description, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$lid, $data['name'], $data['programme'], $data['department'], $data['level'], $data['academic_year'], $data['description'], now_sql()]
            );
        }
        $row = Database::fetch('SELECT * FROM lecturer_classes WHERE id = ?', [$id]);
        json_response([
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'programme' => $row['programme'],
            'department' => $row['department'],
            'level' => $row['level'],
            'year' => $row['academic_year'],
            'description' => $row['description'],
        ]);
    }

    public static function delete(): void
    {
        $lec = AuthMiddleware::requireLecturer();
        CsrfMiddleware::requireValid();
        $body = request_json();
        $id = (int) ($body['id'] ?? $_GET['id'] ?? 0);
        LecturerScope::assertOwns('lecturer_classes', $id, (int) $lec['id']);
        Database::exec('DELETE FROM lecturer_classes WHERE id = ? AND lecturer_id = ?', [$id, (int) $lec['id']]);
        json_response(['ok' => true]);
    }

    public static function invitations(): void
    {
        $lec = AuthMiddleware::requireLecturer();
        $rows = Database::fetchAll(
            'SELECT i.*, c.name AS class_name FROM class_invitations i
             JOIN lecturer_classes c ON c.id = i.class_id
             WHERE i.lecturer_id = ? ORDER BY i.id DESC',
            [(int) $lec['id']]
        );
        json_response(array_map(static function ($i) {
            return [
                'id' => (int) $i['id'],
                'classId' => (int) $i['class_id'],
                'token' => $i['token'],
                'expires' => $i['expires_at'],
                'maxUses' => (int) $i['max_uses'],
                'uses' => (int) $i['use_count'],
                'status' => $i['status'],
                'autoApprove' => (int) $i['auto_approve'] === 1,
                'class' => ['id' => (int) $i['class_id'], 'name' => $i['class_name']],
            ];
        }, $rows));
    }

    public static function createInvitation(): void
    {
        $lec = AuthMiddleware::requireLecturer();
        CsrfMiddleware::requireValid();
        $body = request_json();
        $classId = (int) ($body['classId'] ?? 0);
        LecturerScope::assertOwns('lecturer_classes', $classId, (int) $lec['id']);
        $token = 'inv_' . random_token(8);
        $expires = $body['expires'] ?? (new \DateTimeImmutable('+14 days'))->format('Y-m-d H:i:s');
        if (is_numeric($expires)) {
            // ignore
        }
        $expires = date('Y-m-d H:i:s', strtotime((string) $expires) ?: strtotime('+14 days'));
        $id = Database::insert(
            'INSERT INTO class_invitations (lecturer_id, class_id, token, expires_at, max_uses, use_count, status, auto_approve, created_at)
             VALUES (?, ?, ?, ?, ?, 0, \'active\', ?, ?)',
            [
                (int) $lec['id'], $classId, $token, $expires,
                (int) ($body['maxUses'] ?? 100),
                !empty($body['autoApprove']) ? 1 : 0,
                now_sql(),
            ]
        );
        $class = Database::fetch('SELECT * FROM lecturer_classes WHERE id = ?', [$classId]);
        json_response([
            'id' => $id,
            'classId' => $classId,
            'token' => $token,
            'expires' => $expires,
            'maxUses' => (int) ($body['maxUses'] ?? 100),
            'uses' => 0,
            'status' => 'active',
            'autoApprove' => !empty($body['autoApprove']),
            'class' => ['id' => $classId, 'name' => $class['name']],
        ]);
    }

    public static function revokeInvitation(): void
    {
        $lec = AuthMiddleware::requireLecturer();
        CsrfMiddleware::requireValid();
        $body = request_json();
        $id = (int) ($body['id'] ?? 0);
        LecturerScope::assertOwns('class_invitations', $id, (int) $lec['id']);
        Database::exec(
            "UPDATE class_invitations SET status = 'revoked' WHERE id = ? AND lecturer_id = ?",
            [$id, (int) $lec['id']]
        );
        json_response(['ok' => true]);
    }
}
