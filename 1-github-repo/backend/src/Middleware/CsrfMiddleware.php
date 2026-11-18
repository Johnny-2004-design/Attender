<?php
declare(strict_types=1);

namespace Attender\Middleware;

use Attender\Support\Csrf;
use function Attender\Support\json_error;

final class CsrfMiddleware
{
    public static function requireValid(): void
    {
        $token = $_SERVER['HTTP_X_CSRF_TOKEN']
            ?? $_POST['_csrf']
            ?? null;
        if ($token === null) {
            $json = \Attender\Support\request_json();
            $token = $json['_csrf'] ?? null;
        }
        if (!Csrf::validate(is_string($token) ? $token : null)) {
            json_error('Invalid or missing CSRF token.', 419);
            exit;
        }
    }
}
