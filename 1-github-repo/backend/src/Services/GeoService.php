<?php
declare(strict_types=1);

namespace Attender\Services;

/** Haversine distance in metres. */
final class GeoService
{
    public static function distanceMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $R = 6371000.0;
        $φ1 = deg2rad($lat1);
        $φ2 = deg2rad($lat2);
        $Δφ = deg2rad($lat2 - $lat1);
        $Δλ = deg2rad($lng2 - $lng1);
        $a = sin($Δφ / 2) ** 2 + cos($φ1) * cos($φ2) * sin($Δλ / 2) ** 2;
        return 2 * $R * asin(min(1.0, sqrt($a)));
    }

    public static function withinRadius(
        float $lat1,
        float $lng1,
        float $lat2,
        float $lng2,
        float $radiusM,
        ?float $accuracyM = null,
        ?float $maxAccuracyM = null
    ): array {
        $distance = self::distanceMeters($lat1, $lng1, $lat2, $lng2);
        if ($maxAccuracyM !== null && $accuracyM !== null && $accuracyM > $maxAccuracyM) {
            return [
                'ok' => false,
                'distance' => round($distance, 2),
                'reason' => 'GPS accuracy too low.',
            ];
        }
        if ($distance > $radiusM) {
            return [
                'ok' => false,
                'distance' => round($distance, 2),
                'reason' => 'Outside classroom radius.',
            ];
        }
        return ['ok' => true, 'distance' => round($distance, 2), 'reason' => null];
    }
}
