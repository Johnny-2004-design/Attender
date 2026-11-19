<?php
declare(strict_types=1);

namespace Attender\Support;

/**
 * Cosine similarity must be a present, finite float in [-1.0, 1.0].
 * Reject missing / non-numeric / NaN / ±Inf / out-of-range before any threshold compare.
 */
final class FaceSimilarity
{
    /**
     * @return array{ok:bool, value:?float, reason:?string}
     */
    public static function parse(mixed $raw): array
    {
        if ($raw === null || $raw === '') {
            return ['ok' => false, 'value' => null, 'reason' => 'Similarity missing.'];
        }
        if (is_bool($raw) || is_array($raw) || is_object($raw)) {
            return ['ok' => false, 'value' => null, 'reason' => 'Similarity non-numeric.'];
        }
        if (is_string($raw)) {
            $trim = trim($raw);
            if ($trim === '' || !is_numeric($trim)) {
                return ['ok' => false, 'value' => null, 'reason' => 'Similarity non-numeric.'];
            }
            // Reject explicit non-finite tokens even if is_numeric accepts them on some builds
            if (preg_match('/^(nan|inf|\+inf|-inf|infinity|\+infinity|-infinity)$/i', $trim)) {
                return ['ok' => false, 'value' => null, 'reason' => 'Similarity not finite.'];
            }
            $raw = $trim;
        } elseif (!is_int($raw) && !is_float($raw)) {
            return ['ok' => false, 'value' => null, 'reason' => 'Similarity non-numeric.'];
        }

        $v = (float) $raw;
        if (!is_finite($v)) {
            return ['ok' => false, 'value' => null, 'reason' => 'Similarity not finite.'];
        }
        if ($v < -1.0 || $v > 1.0) {
            return ['ok' => false, 'value' => null, 'reason' => 'Similarity out of range [-1.0, 1.0].'];
        }
        return ['ok' => true, 'value' => $v, 'reason' => null];
    }

    /**
     * Validate then compare to threshold. Never "passes" on invalid similarity.
     *
     * @return array{ok:bool, value:?float, reason:?string, below_threshold?:bool}
     */
    public static function meetsThreshold(mixed $raw, float $threshold): array
    {
        $parsed = self::parse($raw);
        if (!$parsed['ok']) {
            return $parsed;
        }
        /** @var float $v */
        $v = $parsed['value'];
        if ($v < $threshold) {
            return [
                'ok' => false,
                'value' => $v,
                'reason' => 'Facial verification failed (below threshold).',
                'below_threshold' => true,
            ];
        }
        return ['ok' => true, 'value' => $v, 'reason' => null, 'below_threshold' => false];
    }
}
