<?php
declare(strict_types=1);

namespace Attender\Services;

use function Attender\Support\config;

final class FaceService
{
    public static function make(): FaceServiceInterface
    {
        $provider = strtolower((string) (config('app')['face_provider'] ?? 'null'));
        return match ($provider) {
            'http', 'onnx' => new FaceHttpProvider(),
            default => new FaceNullProvider(),
        };
    }

    /** True when PHP is configured to require a real embedding for registration. */
    public static function requiresEmbedding(): bool
    {
        $provider = strtolower((string) (config('app')['face_provider'] ?? 'null'));
        if (in_array($provider, ['http', 'onnx'], true)) {
            return true;
        }
        return (bool) (config('app')['face_require_embedding'] ?? false);
    }
}
