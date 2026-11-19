<?php
declare(strict_types=1);

namespace Attender\Controllers;

use Attender\Middleware\AuthMiddleware;
use Attender\Middleware\CsrfMiddleware;
use Attender\Services\ReportService;
use Attender\Services\SettingsService;
use function Attender\Support\json_response;
use function Attender\Support\request_json;

final class SettingsController
{
    public static function get(): void
    {
        $lec = AuthMiddleware::requireLecturer();
        json_response(SettingsService::get((int) $lec['id']));
    }

    public static function save(): void
    {
        $lec = AuthMiddleware::requireLecturer();
        CsrfMiddleware::requireValid();
        $body = request_json();
        $saved = SettingsService::save((int) $lec['id'], $body);
        try { \Attender\Services\FaceRetention::purge((int) $lec['id']); } catch (\Throwable $e) { error_log('[ATTENDER] retention: ' . $e->getMessage()); }
        json_response($saved);
    }

    public static function export(): void
    {
        $lec = AuthMiddleware::requireLecturer();
        $format = strtolower((string) ($_GET['format'] ?? 'xlsx'));
        $sessionId = isset($_GET['sessionId']) ? (int) $_GET['sessionId'] : null;
        $courseId = isset($_GET['courseId']) ? (int) $_GET['courseId'] : null;
        if ($format === 'pdf') {
            $path = ReportService::exportPdf((int) $lec['id'], $sessionId, $courseId);
            $ext = pathinfo($path, PATHINFO_EXTENSION);
            if ($ext === 'pdf') {
                header('Content-Type: application/pdf');
                header('Content-Disposition: attachment; filename="attender-report.pdf"');
            } else {
                header('Content-Type: text/html; charset=utf-8');
                header('Content-Disposition: attachment; filename="attender-report.html"');
            }
        } else {
            $path = ReportService::exportExcel((int) $lec['id'], $sessionId, $courseId);
            $ext = pathinfo($path, PATHINFO_EXTENSION);
            if ($ext === 'xlsx') {
                header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
                header('Content-Disposition: attachment; filename="attender-report.xlsx"');
            } else {
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="attender-report.csv"');
            }
        }
        header('Content-Length: ' . filesize($path));
        readfile($path);
        @unlink($path);
        exit;
    }
}
