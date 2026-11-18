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
            'lat' => 5.1153,
            'lng' => -1.2786,
            'locName' => 'Lecture Hall A',
        ];
    }

    public static function get(int $lecturerId): array
    {
        $settings = self::defaults();
        $rows = Database::fetchAll(
            'SELECT setting_key, setting_value FROM system_settings WHERE lecturer_id = ?',
            [$lecturerId]
        );
        foreach ($rows as $r) {
            $decoded = json_decode((string) $r['setting_value'], true);
            $settings[$r['setting_key']] = $decoded === null && json_last_error() !== JSON_ERROR_NONE
                ? $r['setting_value']
                : $decoded;
        }
        return $settings;
    }

    public static function save(int $lecturerId, array $patch): array
    {
        $current = self::get($lecturerId);
        $allowed = array_keys(self::defaults());
        foreach ($allowed as $key) {
            if (array_key_exists($key, $patch)) {
                $current[$key] = $patch[$key];
                Database::query(
                    'INSERT INTO system_settings (lecturer_id, setting_key, setting_value)
                     VALUES (?, ?, ?)
                     ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
                    [$lecturerId, $key, json_encode($current[$key])]
                );
            }
        }
        return $current;
    }
}
