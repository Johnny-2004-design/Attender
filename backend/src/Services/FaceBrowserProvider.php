<?php
declare(strict_types=1);

namespace Attender\Services;

use Attender\Database;
use function Attender\Support\now_sql;

/**
 * Face matching for hosts that cannot run a Python face service (e.g. InfinityFree).
 *
 * The student's BROWSER (face-api.js) turns a camera frame into a 128-number face descriptor;
 * this class stores the enrolment descriptors and compares later descriptors on the SERVER.
 * No photos are stored or sent.
 *
 * Honest limits (also in docs/FACE_BROWSER.md):
 *  - The descriptor is computed on the student's device, so a determined cheater could post a
 *    descriptor made from a photo of someone else. It stops casual proxy attendance, not a
 *    technical attack. The motion challenge is a soft check, not certified anti-spoofing.
 *  - A student's descriptors are never sent back to any browser.
 */
final class FaceBrowserProvider implements FaceServiceInterface
{
    public const DIM = 128;
    /** Euclidean distance at/under which two descriptors are the same person (face-api.js standard). */
    public const MAX_DISTANCE = 0.6;
    /** Enrolment photos of one person must agree with each other at least this well. */
    private const ENROL_CONSISTENCY = 0.5;
    private const CHALLENGES = ['blink', 'left', 'right', 'smile'];

    public function providerName(): string
    {
        return 'browser';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    /** distance 0 → 1.00, 0.6 → 0.80 (the default slider position), 1.2 → 0.60 */
    public static function similarityFromDistance(float $d): float
    {
        return max(-1.0, min(1.0, 1.0 - $d / 3.0));
    }

    public static function distanceFromSimilarity(float $s): float
    {
        return 3.0 * (1.0 - $s);
    }

    public function hasReadyEmbedding(int $studentId): bool
    {
        return $this->loadDescriptors($studentId) !== [];
    }

    /**
     * @param list<mixed> $raw
     * @return list<list<float>>|null null when any descriptor is malformed
     */
    public static function cleanDescriptors(mixed $raw, int $min, int $max): ?array
    {
        if (!is_array($raw) || count($raw) < $min || count($raw) > $max) {
            return null;
        }
        $out = [];
        foreach ($raw as $d) {
            if (!is_array($d) || count($d) !== self::DIM) {
                return null;
            }
            $vec = [];
            $norm = 0.0;
            foreach ($d as $v) {
                if (is_bool($v) || (!is_int($v) && !is_float($v) && !(is_string($v) && is_numeric($v)))) {
                    return null;
                }
                $f = (float) $v;
                if (!is_finite($f) || abs($f) > 5.0) {
                    return null;
                }
                $vec[] = $f;
                $norm += $f * $f;
            }
            if ($norm < 0.01) { // all-zero / empty vector
                return null;
            }
            $out[] = $vec;
        }
        return $out;
    }

    public static function distance(array $a, array $b): float
    {
        $s = 0.0;
        for ($i = 0; $i < self::DIM; $i++) {
            $d = $a[$i] - $b[$i];
            $s += $d * $d;
        }
        return sqrt($s);
    }

    public function enroll(int $studentId, ?int $lecturerId, array $imageDataUrls, array $extra = []): array
    {
        $desc = self::cleanDescriptors($extra['descriptors'] ?? null, 3, 8);
        if ($desc === null) {
            return $this->enrollFail('Face data was missing or invalid. Reload the page and capture again.');
        }
        // All captures must be the same person, otherwise the stored template would be garbage.
        $n = count($desc);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                if (self::distance($desc[$i], $desc[$j]) > self::ENROL_CONSISTENCY) {
                    return $this->enrollFail('The captures did not look like the same face. Face the camera in good light, remove glasses/hat if possible, and try again.');
                }
            }
        }

        Database::exec('UPDATE facial_templates SET is_active = 0, embedding_ready = 0 WHERE student_id = ?', [$studentId]);
        $id = Database::insert(
            'INSERT INTO facial_templates
             (student_id, lecturer_id, provider, template_path, embedding_meta, is_active, embedding_ready, created_at)
             VALUES (?, ?, ?, ?, ?, 1, 1, ?)',
            [
                $studentId,
                $lecturerId,
                $this->providerName(),
                'browser-descriptor',
                json_encode(['model' => 'face-api.js/faceRecognitionNet', 'dim' => self::DIM, 'descriptors' => $desc]),
                now_sql(),
            ]
        );
        return [
            'ok' => true,
            'face_enrolled' => true,
            'embedding_ready' => true,
            'template_id' => $id,
            'provider' => $this->providerName(),
        ];
    }

    public function verify(int $studentId, array $imageDataUrls, array $liveness = []): array
    {
        $enrolled = $this->loadDescriptors($studentId);
        if ($enrolled === []) {
            return $this->verifyFail('Face enrollment incomplete. Re-enrol your face before marking attendance.');
        }
        $probes = self::cleanDescriptors($liveness['descriptors'] ?? null, 3, 6);
        if ($probes === null) {
            return $this->verifyFail('Face data was missing or invalid. Reload the page and try again.');
        }

        // Nearest enrolled descriptor for each probe; the MEDIAN probe decides (one bad frame can't fail you).
        $best = [];
        foreach ($probes as $p) {
            $m = INF;
            foreach ($enrolled as $e) {
                $m = min($m, self::distance($p, $e));
            }
            $best[] = $m;
        }
        sort($best);
        $median = $best[intdiv(count($best), 2)];
        $similarity = self::similarityFromDistance($median);
        $identityOk = $median <= self::MAX_DISTANCE;

        // Soft liveness: the page must report a passed random challenge, and the probes
        // must not be byte-identical copies of one frame.
        $client = is_array($liveness['client'] ?? null) ? $liveness['client'] : [];
        $challenge = (string) ($client['challenge'] ?? $liveness['challenge'] ?? '');
        $passed = ($client['passed'] ?? null) === true;
        $spread = 0.0;
        $np = count($probes);
        for ($i = 0; $i < $np; $i++) {
            for ($j = $i + 1; $j < $np; $j++) {
                $spread = max($spread, self::distance($probes[$i], $probes[$j]));
            }
        }
        $livenessOk = $passed && in_array($challenge, self::CHALLENGES, true) && $spread > 0.0005;

        $reason = null;
        if (!$identityOk) {
            $reason = 'Face did not match your registration.';
        } elseif (!$livenessOk) {
            $reason = 'Liveness check was not completed. Follow the on-screen instruction (blink, turn or smile) and try again.';
        }
        return [
            'ok' => true,
            'pending' => false,
            'similarity' => $similarity,
            'identity_ok' => $identityOk,
            'liveness_ok' => $livenessOk,
            'reason' => $reason,
            'provider' => $this->providerName(),
        ];
    }

    public function deleteEnrollment(int $studentId, ?int $lecturerId = null): array
    {
        // Biometric data is deleted outright, not just flagged.
        Database::exec('DELETE FROM facial_templates WHERE student_id = ?', [$studentId]);
        return ['ok' => true, 'provider' => $this->providerName()];
    }

    /** @return list<list<float>> */
    private function loadDescriptors(int $studentId): array
    {
        $row = Database::fetch(
            "SELECT embedding_meta FROM facial_templates
             WHERE student_id = ? AND is_active = 1 AND embedding_ready = 1 AND provider = 'browser'
             ORDER BY id DESC LIMIT 1",
            [$studentId]
        );
        if (!$row || empty($row['embedding_meta'])) {
            return [];
        }
        $meta = json_decode((string) $row['embedding_meta'], true);
        $d = is_array($meta) ? self::cleanDescriptors($meta['descriptors'] ?? null, 1, 12) : null;
        return $d ?? [];
    }

    private function enrollFail(string $reason): array
    {
        return ['ok' => false, 'face_enrolled' => false, 'embedding_ready' => false, 'reason' => $reason, 'provider' => $this->providerName()];
    }

    private function verifyFail(string $reason): array
    {
        return ['ok' => false, 'pending' => false, 'similarity' => null, 'liveness_ok' => null, 'identity_ok' => false, 'reason' => $reason, 'provider' => $this->providerName()];
    }
}
