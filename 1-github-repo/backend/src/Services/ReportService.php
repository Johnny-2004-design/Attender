<?php
declare(strict_types=1);

namespace Attender\Services;

use Attender\Database;
use function Attender\Support\config;

final class ReportService
{
    public static function sessionRows(int $lecturerId, ?int $sessionId = null, ?int $courseId = null): array
    {
        $sql = 'SELECT r.status, r.marked_at, r.distance_m, r.face_similarity,
                       st.full_name, st.index_number,
                       c.code AS course_code, c.title AS course_title,
                       s.opened_at, s.closed_at, s.id AS session_id
                FROM attendance_records r
                JOIN students st ON st.id = r.student_id
                JOIN attendance_sessions s ON s.id = r.session_id
                JOIN courses c ON c.id = s.course_id
                WHERE r.lecturer_id = ?';
        $params = [$lecturerId];
        if ($sessionId) {
            $sql .= ' AND r.session_id = ?';
            $params[] = $sessionId;
        }
        if ($courseId) {
            $sql .= ' AND s.course_id = ?';
            $params[] = $courseId;
        }
        $sql .= ' ORDER BY s.opened_at DESC, st.full_name ASC';
        return Database::fetchAll($sql, $params);
    }

    public static function exportExcel(int $lecturerId, ?int $sessionId = null, ?int $courseId = null): string
    {
        $rows = self::sessionRows($lecturerId, $sessionId, $courseId);
        $dir = config('app')['storage_path'] . '/exports';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        if (class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
            $sheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $ws = $sheet->getActiveSheet();
            $ws->setTitle('Attendance');
            $headers = ['Index', 'Student', 'Course', 'Session opened', 'Status', 'Marked at', 'Distance m', 'Face similarity'];
            foreach ($headers as $i => $h) {
                $ws->setCellValue([$i + 1, 1], $h);
            }
            $r = 2;
            foreach ($rows as $row) {
                $ws->setCellValue([1, $r], $row['index_number']);
                $ws->setCellValue([2, $r], $row['full_name']);
                $ws->setCellValue([3, $r], $row['course_code'] . ' — ' . $row['course_title']);
                $ws->setCellValue([4, $r], $row['opened_at']);
                $ws->setCellValue([5, $r], strtoupper((string) $row['status']));
                $ws->setCellValue([6, $r], $row['marked_at']);
                $ws->setCellValue([7, $r], $row['distance_m']);
                $ws->setCellValue([8, $r], $row['face_similarity']);
                $r++;
            }
            $path = $dir . '/attendance_' . $lecturerId . '_' . time() . '.xlsx';
            (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($sheet))->save($path);
            return $path;
        }

        // Fallback CSV (opens in Excel) when Composer packages are not installed yet
        $path = $dir . '/attendance_' . $lecturerId . '_' . time() . '.csv';
        $fh = fopen($path, 'w');
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, ['Index', 'Student', 'Course', 'Session opened', 'Status', 'Marked at', 'Distance m', 'Face similarity']);
        foreach ($rows as $row) {
            fputcsv($fh, [
                $row['index_number'], $row['full_name'],
                $row['course_code'] . ' — ' . $row['course_title'],
                $row['opened_at'], strtoupper((string) $row['status']),
                $row['marked_at'], $row['distance_m'], $row['face_similarity'],
            ]);
        }
        fclose($fh);
        return $path;
    }

    public static function exportPdf(int $lecturerId, ?int $sessionId = null, ?int $courseId = null): string
    {
        $rows = self::sessionRows($lecturerId, $sessionId, $courseId);
        $dir = config('app')['storage_path'] . '/exports';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $html = '<html><head><style>
            body{font-family: DejaVu Sans, sans-serif; font-size:12px; color:#111}
            h1{font-size:18px; margin:0 0 4px} .sub{color:#555;margin-bottom:16px}
            table{width:100%; border-collapse:collapse} th,td{border:1px solid #ccc; padding:6px; text-align:left}
            th{background:#0A66C2; color:#fff}
          </style></head><body>';
        $html .= '<h1>ATTENDER Attendance Report</h1>';
        $html .= '<div class="sub">Smart Attendance, Verified Presence · Generated ' . date('Y-m-d H:i') . '</div>';
        $html .= '<table><thead><tr><th>Index</th><th>Student</th><th>Course</th><th>Status</th><th>Marked</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $html .= '<tr><td>' . htmlspecialchars((string) $row['index_number']) . '</td>'
                . '<td>' . htmlspecialchars((string) $row['full_name']) . '</td>'
                . '<td>' . htmlspecialchars((string) $row['course_code']) . '</td>'
                . '<td>' . htmlspecialchars(strtoupper((string) $row['status'])) . '</td>'
                . '<td>' . htmlspecialchars((string) ($row['marked_at'] ?? '—')) . '</td></tr>';
        }
        $html .= '</tbody></table></body></html>';

        if (class_exists(\Dompdf\Dompdf::class)) {
            $options = new \Dompdf\Options();
            $options->set('isRemoteEnabled', false);
            $dompdf = new \Dompdf\Dompdf($options);
            $dompdf->loadHtml($html);
            $dompdf->setPaper('A4', 'landscape');
            $dompdf->render();
            $path = $dir . '/attendance_' . $lecturerId . '_' . time() . '.pdf';
            file_put_contents($path, $dompdf->output());
            return $path;
        }

        $path = $dir . '/attendance_' . $lecturerId . '_' . time() . '.html';
        file_put_contents($path, $html);
        return $path;
    }
}
