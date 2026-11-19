<?php
declare(strict_types=1);

namespace Attender\Controllers;

use Attender\Database;
use Attender\Middleware\CsrfMiddleware;
use Attender\Services\AuditService;
use Attender\Support\Csrf;
use Attender\Support\Session;
use function Attender\Support\config;
use function Attender\Support\json_error;
use function Attender\Support\json_response;
use function Attender\Support\now_sql;
use function Attender\Support\request_json;

final class AuthController
{
    public static function csrf(): void
    {
        Session::start();
        json_response(['ok' => true, 'csrf' => Csrf::token()]);
    }

    public static function login(): void
    {
        Session::start();
        CsrfMiddleware::requireValid();
        $body = request_json();
        $email = strtolower(trim((string) ($body['email'] ?? '')));
        $password = (string) ($body['password'] ?? '');
        $sec = config('security');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < (int) $sec['password_min']) {
            json_error('Enter a valid email and a password of at least ' . $sec['password_min'] . ' characters.');
            return;
        }
        $row = Database::fetch('SELECT * FROM lecturers WHERE email = ? LIMIT 1', [$email]);
        if (!$row || !(int) $row['is_active'] || !password_verify($password, $row['password_hash'])) {
            json_error('Invalid email or password.', 401);
            return;
        }
        session_regenerate_id(true);
        $lecturer = self::publicLecturer($row);
        Session::set('lecturer', $lecturer);
        AuditService::log((int) $row['id'], 'Login', $row['email'], '', 'lecturer', (int) $row['id'], null, null, 'lecturer', (int) $row['id'], $row['name']);
        json_response(['ok' => true, 'lecturer' => $lecturer, 'csrf' => Csrf::token()]);
    }

    public static function register(): void
    {
        Session::start();
        CsrfMiddleware::requireValid();
        $body = request_json();
        $sec = config('security');
        $name = trim((string) ($body['name'] ?? ''));
        $email = strtolower(trim((string) ($body['email'] ?? '')));
        $password = (string) ($body['password'] ?? '');
        $title = trim((string) ($body['title'] ?? ''));
        $department = trim((string) ($body['department'] ?? ''));
        $institution = trim((string) ($body['institution'] ?? ''));
        $phone = trim((string) ($body['phone'] ?? ''));

        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < (int) $sec['password_min']) {
            json_error('Name, valid email and password (8+) are required.');
            return;
        }
        $exists = Database::fetch('SELECT id FROM lecturers WHERE email = ?', [$email]);
        if ($exists) {
            json_error('An account with that email already exists.', 409);
            return;
        }
        $hash = password_hash($password, $sec['password_algo']);
        $id = Database::insert(
            'INSERT INTO lecturers (name, title, email, phone, department, institution, password_hash, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$name, $title ?: null, $email, $phone ?: null, $department ?: null, $institution ?: null, $hash, now_sql()]
        );
        $row = Database::fetch('SELECT * FROM lecturers WHERE id = ?', [$id]);
        session_regenerate_id(true);
        $lecturer = self::publicLecturer($row);
        Session::set('lecturer', $lecturer);
        AuditService::log($id, 'Registered', $email, '', 'lecturer', $id, null, null, 'lecturer', $id, $name);
        json_response(['ok' => true, 'lecturer' => $lecturer, 'csrf' => Csrf::token()], 201);
    }

    public static function logout(): void
    {
        Session::start();
        CsrfMiddleware::requireValid();
        $lecturer = Session::get('lecturer');
        if (is_array($lecturer)) {
            AuditService::log((int) $lecturer['id'], 'Logout', '', '', 'lecturer', (int) $lecturer['id'], null, null, 'lecturer', (int) $lecturer['id'], $lecturer['name'] ?? null);
        }
        Session::destroy();
        json_response(['ok' => true]);
    }

    public static function me(): void
    {
        $lecturer = \Attender\Middleware\AuthMiddleware::requireLecturer();
        $row = Database::fetch('SELECT * FROM lecturers WHERE id = ?', [(int) $lecturer['id']]);
        json_response(self::publicLecturer($row ?: $lecturer));
    }

    public static function updateMe(): void
    {
        $lecturer = \Attender\Middleware\AuthMiddleware::requireLecturer();
        CsrfMiddleware::requireValid();
        $body = request_json();
        $fields = ['name', 'title', 'phone', 'department', 'institution'];
        $sets = [];
        $params = [];
        foreach ($fields as $f) {
            if (array_key_exists($f, $body)) {
                $sets[] = "$f = ?";
                $params[] = trim((string) $body[$f]);
            }
        }
        if (isset($body['password']) && $body['password'] !== '') {
            $pw = (string) $body['password'];
            if (strlen($pw) < (int) config('security')['password_min']) {
                json_error('Password must be at least 8 characters.');
                return;
            }
            $sets[] = 'password_hash = ?';
            $params[] = password_hash($pw, config('security')['password_algo']);
        }
        if (!$sets) {
            json_response(self::publicLecturer(Database::fetch('SELECT * FROM lecturers WHERE id = ?', [(int) $lecturer['id']])));
            return;
        }
        $params[] = (int) $lecturer['id'];
        Database::exec('UPDATE lecturers SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);
        $row = Database::fetch('SELECT * FROM lecturers WHERE id = ?', [(int) $lecturer['id']]);
        $pub = self::publicLecturer($row);
        Session::set('lecturer', $pub);
        json_response($pub);
    }

    private static function publicLecturer(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'title' => $row['title'] ?? '',
            'email' => $row['email'],
            'phone' => $row['phone'] ?? '',
            'department' => $row['department'] ?? '',
            'institution' => $row['institution'] ?? '',
        ];
    }
}
