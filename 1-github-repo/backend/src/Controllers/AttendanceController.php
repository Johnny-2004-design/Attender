<?php
declare(strict_types=1);

namespace Attender\Controllers;

use Attender\Services\VerificationEngine;
use Attender\Support\Csrf;
use Attender\Support\Session;
use function Attender\Support\json_response;
use function Attender\Support\request_json;

final class AttendanceController
{
    public static function resolve(): void
    {
        Session::start();
        $body = request_json();
        $input = (string) ($body['input'] ?? $_GET['input'] ?? '');
        $engine = new VerificationEngine();
        $res = $engine->resolveInput($input);
        if (!$res['ok']) {
            json_response($res);
            return;
        }
        json_response([
            'ok' => true,
            'course' => $res['course'],
            'radius' => $res['radius'],
            'minAccuracy' => $res['minAccuracy'],
            'faceMode' => $res['faceMode'],
            'gps' => $res['gps'],
            'face' => $res['face'],
            'csrf' => Csrf::token(),
        ]);
    }

    public static function submit(): void
    {
        Session::start();
        // Student attendance submissions use CSRF from resolve/csrf endpoint
        \Attender\Middleware\CsrfMiddleware::requireValid();
        $body = request_json();
        $engine = new VerificationEngine();
        json_response($engine->submit($body));
    }

    /** Face step check (enrolled? / matches?) — records nothing; submit() still verifies again. */
    public static function faceCheck(): void
    {
        Session::start();
        \Attender\Middleware\CsrfMiddleware::requireValid();
        json_response((new VerificationEngine())->checkFace(request_json()));
    }

    /** Flush offline queue items one-by-one (same verification rules). */
    public static function submitQueue(): void
    {
        Session::start();
        \Attender\Middleware\CsrfMiddleware::requireValid();
        $body = request_json();
        $items = $body['items'] ?? [];
        if (!is_array($items)) {
            json_response(['ok' => false, 'reason' => 'Invalid queue payload.']);
            return;
        }
        $engine = new VerificationEngine();
        $results = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                $results[] = ['ok' => false, 'reason' => 'Invalid item'];
                continue;
            }
            $results[] = $engine->submit($item);
        }
        json_response(['ok' => true, 'results' => $results]);
    }
}
