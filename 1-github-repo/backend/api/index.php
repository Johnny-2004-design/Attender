<?php
declare(strict_types=1);

/**
 * ATTENDER API front controller.
 * Routes: /api/index.php?r=auth/login  or  /api/auth/login (with rewrite)
 */

$app = require dirname(__DIR__) . '/bootstrap/app.php';

use Attender\Controllers\AttendanceController;
use Attender\Controllers\AuthController;
use Attender\Controllers\ClassController;
use Attender\Controllers\CourseController;
use Attender\Controllers\DashboardController;
use Attender\Controllers\RecordController;
use Attender\Controllers\SessionController;
use Attender\Controllers\SettingsController;
use Attender\Controllers\StudentController;
use function Attender\Support\json_error;

// ---- CORS (split deployment: static frontend on Netlify, API here) ----------------
// Only origins listed in ATTENDER_ALLOWED_ORIGINS get CORS headers. Never "*": the
// session cookie is sent with credentials, which browsers refuse to combine with "*".
$origin = rtrim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''), '/');
header('Vary: Origin');
if ($origin !== '' && in_array($origin, (array) ($app['allowed_origins'] ?? []), true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token, Accept');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Max-Age: 600');
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$route = (string) ($_GET['r'] ?? '');
if ($route === '') {
    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    // strip .../api or .../api/index.php
    if (preg_match('#/api(?:/index\.php)?/(.+)$#', $uri, $m)) {
        $route = $m[1];
    }
}
$route = trim($route, '/');
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

$routes = [
    'GET csrf' => [AuthController::class, 'csrf'],
    'POST auth/login' => [AuthController::class, 'login'],
    'POST auth/register' => [AuthController::class, 'register'],
    'POST auth/logout' => [AuthController::class, 'logout'],
    'GET me' => [AuthController::class, 'me'],
    'POST me' => [AuthController::class, 'updateMe'],
    'GET dashboard' => [DashboardController::class, 'dashboard'],
    'GET classes' => [ClassController::class, 'list'],
    'POST classes/save' => [ClassController::class, 'save'],
    'POST classes/delete' => [ClassController::class, 'delete'],
    'GET invitations' => [ClassController::class, 'invitations'],
    'POST invitations/create' => [ClassController::class, 'createInvitation'],
    'POST invitations/revoke' => [ClassController::class, 'revokeInvitation'],
    'GET courses' => [CourseController::class, 'list'],
    'POST courses/save' => [CourseController::class, 'save'],
    'POST courses/delete' => [CourseController::class, 'delete'],
    'GET students' => [StudentController::class, 'list'],
    'GET student' => [StudentController::class, 'one'],
    'POST students/enrollment' => [StudentController::class, 'setEnrollment'],
    'GET requests' => [StudentController::class, 'requests'],
    'POST requests/decide' => [StudentController::class, 'decideRequest'],
    'GET invite' => [StudentController::class, 'inviteInfo'],
    'POST registration' => [StudentController::class, 'submitRegistration'],
    'POST students/face/delete' => [StudentController::class, 'deleteFace'],
    'GET sessions' => [SessionController::class, 'list'],
    'GET session' => [SessionController::class, 'one'],
    'GET activeSession' => [SessionController::class, 'active'],
    'POST sessions/open' => [SessionController::class, 'open'],
    'POST sessions/close' => [SessionController::class, 'close'],
    'POST sessions/rotateToken' => [SessionController::class, 'rotateToken'],
    'POST sessions/newCode' => [SessionController::class, 'newCode'],
    'POST sessions/patch' => [SessionController::class, 'patchActive'],
    'GET records' => [RecordController::class, 'list'],
    'POST records/correct' => [RecordController::class, 'correct'],
    'GET audit' => [RecordController::class, 'audit'],
    'GET settings' => [SettingsController::class, 'get'],
    'POST settings' => [SettingsController::class, 'save'],
    'GET reports/export' => [SettingsController::class, 'export'],
    'POST attendance/resolve' => [AttendanceController::class, 'resolve'],
    'POST attendance/submit' => [AttendanceController::class, 'submit'],
    'POST attendance/queue' => [AttendanceController::class, 'submitQueue'],
];

$key = $method . ' ' . $route;
if (!isset($routes[$key])) {
    // Also try without distinguishing trailing aliases used by the frontend adapter
    json_error('Not found: ' . $method . ' ' . $route, 404);
    exit;
}

try {
    [$class, $action] = $routes[$key];
    $class::$action();
} catch (Throwable $e) {
    $app = require dirname(__DIR__) . '/config/app.php';
    $msg = !empty($app['debug']) ? $e->getMessage() : 'Server error.';
    error_log('[ATTENDER] ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    json_error($msg, 500);
}
