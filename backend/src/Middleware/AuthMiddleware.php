<?php
declare(strict_types=1);

namespace Attender\Middleware;

use Attender\Support\Session;
use function Attender\Support\json_error;

final class AuthMiddleware
{
    public static function requireLecturer(): array
    {
        Session::start();
        $lecturer = Session::get('lecturer');
        if (!is_array($lecturer) || empty($lecturer['id'])) {
            json_error('Authentication required.', 401);
            exit;
        }
        return $lecturer;
    }

    public static function optionalLecturer(): ?array
    {
        Session::start();
        $lecturer = Session::get('lecturer');
        return is_array($lecturer) && !empty($lecturer['id']) ? $lecturer : null;
    }
}
