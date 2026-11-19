<?php
declare(strict_types=1);

namespace Attender\Services;

use Attender\Database;

final class SettingsService
{
    public static function defaults(): array
    {
        return [
            'defaultRadius' => 30,
            'minAccuracy' => 50,
            'faceThreshold' => 0.8,
            'maxCodeAttempts' => 5,
            'qrRotateSeconds' => 30,
            'requireFace' => true,
            'requireGps' => true,
            'allowDisableFace' => false,
            'retention' => '12',
            'lat' => 5.1153,
            'lng' => -1.2786,
            'locName' => 'Lecture Hall A',
        ];
    }

    public static function get(int $lecturerId): array
    {
        $rows = Database::fetchAll(
            'SELECT setting_key, setting_value FROM system_settings WHERE lecturer_id = ?',
            [$lecturerId]
        );
        $stored = [];
        foreach ($rows as $r) {
            $decoded = json_decode((string) $r['setting_value'], true);
            $stored[$r['setting_key']] = $decoded === null && json_last_error() !== JSON_ERROR_NONE
                ? $r['setting_value']
                : $decoded;
        }
        // Clamp on the way out too, so values saved before validation existed
        // (e.g. radius 5000) are corrected instead of lingering in the database.
        return array_merge(self::defaults(), self::sanitize($stored));
    }

    /**
     * Keep only known keys and force every value into a safe range, so a typo such as
     * radius 5000 or accuracy 50000 can never be stored (the UI promises 10–500 m).
     * Unknown / unparsable values are dropped, never guessed.
     */
    public static function sanitize(array $patch): array
    {
        $out = [];
        $clampInt = static fn (mixed $v, int $min, int $max): ?int =>
            is_numeric($v) ? (int) max($min, min($max, round((float) $v))) : null;
        $clampFloat = static fn (mixed $v, float $min, float $max): ?float =>
            is_numeric($v) ? max($min, min($max, (float) $v)) : null;
        $bool = static fn (mixed $v): bool =>
            is_bool($v) ? $v : in_array(strtolower((string) $v), ['1', 'true', 'yes', 'on'], true);

        foreach ($patch as $key => $value) {
            switch ($key) {
                case 'defaultRadius':
                    $v = $clampInt($value, 10, 500);
                    break;
                case 'minAccuracy':
                    $v = $clampInt($value, 10, 500);
                    break;
                case 'faceThreshold':
                    $v = $clampFloat($value, 0.6, 0.95);
                    break;
                case 'maxCodeAttempts':
                    $v = $clampInt($value, 1, 10);
                    break;
                case 'qrRotateSeconds':
                    $v = $clampInt($value, 10, 120);
                    break;
                case 'requireFace':
                case 'requireGps':
                case 'allowDisableFace':
                    $v = $bool($value);
                    break;
                case 'retention':
                    $v = in_array((string) $value, ['12', '6', '0'], true) ? (string) $value : null;
                    break;
                case 'lat':
                    $v = $clampFloat($value, -90.0, 90.0);
                    break;
                case 'lng':
                    $v = $clampFloat($value, -180.0, 180.0);
                    break;
                case 'locName':
                    $v = is_string($value) ? mb_substr(trim($value), 0, 120) : null;
                    break;
                default:
                    $v = null; // unknown key
            }
            if ($v !== null) {
                $out[$key] = $v;
            }
        }
        return $out;
    }

    public static function save(int $lecturerId, array $patch): array
    {
        $current = self::get($lecturerId);
        $clean = self::sanitize($patch);
        foreach ($clean as $key => $value) {
            $current[$key] = $value;
            Database::query(
                'INSERT INTO system_settings (lecturer_id, setting_key, setting_value)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
                [$lecturerId, $key, json_encode($value)]
            );
        }
        return $current;
    }
}
