<?php
declare(strict_types=1);

namespace Attender\Services;

use Attender\Database;
use function Attender\Support\config;
use function Attender\Support\now_sql;
use function Attender\Support\random_token;

/**
 * Stores enrolment captures for audit only. NEVER claims face_enrolled=true
 * and NEVER claims a face match succeeded.
 */
final class FaceNullProvider implements FaceServiceInterface
{
    public function providerName(): string
    {
        return 'null';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function hasReadyEmbedding(int $studentId): bool
    {
        $row = Database::fetch(
            'SELECT id FROM facial_templates WHERE student_id = ? AND is_active = 1 AND embedding_ready = 1 LIMIT 1',
            [$studentId]
        );
        return $row !== null;
    }

    public function enroll(int $studentId, ?int $lecturerId, array $imageDataUrls, array $extra = []): array
    {
        if (count($imageDataUrls) < 1) {
            return [
                'ok' => false,
                'face_enrolled' => false,
                'embedding_ready' => false,
                'reason' => 'No face images provided.',
                'provider' => $this->providerName(),
            ];
        }
        $saved = $this->storeAuditImages($studentId, $imageDataUrls);
        if (!$saved) {
            return [
                'ok' => false,
                'face_enrolled' => false,
                'embedding_ready' => false,
                'reason' => 'Invalid face image data.',
                'provider' => $this->providerName(),
            ];
        }
        Database::exec('UPDATE facial_templates SET is_active = 0 WHERE student_id = ?', [$studentId]);
        $id = Database::insert(
            'INSERT INTO facial_templates
             (student_id, lecturer_id, provider, template_path, embedding_meta, is_active, embedding_ready, created_at)
             VALUES (?, ?, ?, ?, ?, 1, 0, ?)',
            [
                $studentId,
                $lecturerId,
                $this->providerName(),
                $saved[0],
                json_encode([
                    'files' => $saved,
                    'note' => 'Audit images only — no embedding. Configure face-service for enrollment.',
                ], JSON_UNESCAPED_SLASHES),
                now_sql(),
            ]
        );
        // Images may be saved, but this is NOT a successful face enrollment.
        return [
            'ok' => false,
            'face_enrolled' => false,
            'embedding_ready' => false,
            'template_id' => $id,
            'reason' => 'Face engine not configured. Images stored for audit only; embedding was not created.',
            'provider' => $this->providerName(),
        ];
    }

    public function verify(int $studentId, array $imageDataUrls, array $liveness = []): array
    {
        if (!$this->hasReadyEmbedding($studentId)) {
            return [
                'ok' => false,
                'pending' => false,
                'similarity' => null,
                'liveness_ok' => null,
                'identity_ok' => false,
                'reason' => 'Face enrollment incomplete. No embedding on file.',
                'provider' => $this->providerName(),
            ];
        }
        // Should not happen for null provider (embedding_ready never set), but fail closed.
        return [
            'ok' => false,
            'pending' => true,
            'similarity' => null,
            'liveness_ok' => null,
            'reason' => 'Face engine not configured. Capture stored as pending review.',
            'provider' => $this->providerName(),
        ];
    }

    public function deleteEnrollment(int $studentId, ?int $lecturerId = null): array
    {
        if ($lecturerId) {
            Database::exec(
                'UPDATE facial_templates SET is_active = 0, embedding_ready = 0 WHERE student_id = ? AND (lecturer_id = ? OR lecturer_id IS NULL)',
                [$studentId, $lecturerId]
            );
        } else {
            Database::exec(
                'UPDATE facial_templates SET is_active = 0, embedding_ready = 0 WHERE student_id = ?',
                [$studentId]
            );
        }
        $this->purgeAuditImages($studentId);
        return ['ok' => true, 'provider' => $this->providerName()];
    }

    /** Remove on-disk enrolment/probe JPEGs for a student (storage/templates/{id}). */
    public function purgeAuditImages(int $studentId): void
    {
        $dir = config('app')['template_path'] . '/' . $studentId;
        if (!is_dir($dir)) {
            return;
        }
        $files = @scandir($dir);
        if (!is_array($files)) {
            return;
        }
        foreach ($files as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            $path = $dir . '/' . $f;
            if (is_file($path)) {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    /** @param list<string> $imageDataUrls @return list<string> */
    public function storeAuditImages(int $studentId, array $imageDataUrls): array
    {
        $dir = config('app')['template_path'] . '/' . $studentId;
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            return [];
        }
        $saved = [];
        foreach (array_slice($imageDataUrls, 0, 5) as $i => $dataUrl) {
            $bin = $this->decodeDataUrl($dataUrl);
            if ($bin === null) {
                continue;
            }
            // ~2.5MB hard cap per image
            if (strlen($bin) > 2_500_000) {
                continue;
            }
            $name = 'enrol_' . date('YmdHis') . '_' . $i . '_' . random_token(4) . '.jpg';
            $path = $dir . '/' . $name;
            if (file_put_contents($path, $bin) === false) {
                continue;
            }
            @chmod($path, 0640);
            $saved[] = 'templates/' . $studentId . '/' . $name;
        }
        return $saved;
    }

    private function decodeDataUrl(string $dataUrl): ?string
    {
        if (!preg_match('#^data:image/(jpeg|jpg|png|webp);base64,#i', $dataUrl, $m)) {
            return null; // reject raw/unknown — require explicit image MIME
        }
        $raw = base64_decode(substr($dataUrl, strlen($m[0])), true);
        return $raw === false ? null : $raw;
    }
}
