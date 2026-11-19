<?php
declare(strict_types=1);

namespace Attender\Services;

use Attender\Database;
use Attender\Support\FaceSecrets;
use Attender\Support\FaceSimilarity;
use function Attender\Support\config;
use function Attender\Support\now_sql;

/**
 * Calls ATTENDER face-service.
 * verify(): PRESENT path requires ok + identity_ok + liveness (when required) + finite similarity in [-1,1] ≥ threshold.
 * delete(): remote must confirm HTTP+JSON ok===true and absent===true before local cleanup.
 */
final class FaceHttpProvider implements FaceServiceInterface
{
    private FaceNullProvider $audit;

    public function __construct()
    {
        $this->audit = new FaceNullProvider();
    }

    public function providerName(): string
    {
        return 'http';
    }

    public function isConfigured(): bool
    {
        $url = config('app')['face_api_url'] ?? '';
        $key = config('app')['face_api_key'] ?? '';
        if (!is_string($url) || $url === '' || !is_string($key)) {
            return false;
        }
        $chk = FaceSecrets::assertUsable($key);
        return !empty($chk['ok']);
    }

    public function hasReadyEmbedding(int $studentId): bool
    {
        return $this->audit->hasReadyEmbedding($studentId);
    }

    public function enroll(int $studentId, ?int $lecturerId, array $imageDataUrls, array $extra = []): array
    {
        if (!$this->isConfigured()) {
            $key = config('app')['face_api_key'] ?? '';
            $chk = FaceSecrets::assertUsable(is_string($key) ? $key : '');
            return [
                'ok' => false,
                'face_enrolled' => false,
                'embedding_ready' => false,
                'reason' => $chk['reason'] ?? 'Face API URL/key not set. Cannot enroll embedding.',
                'provider' => $this->providerName(),
            ];
        }
        if (!$imageDataUrls) {
            return [
                'ok' => false,
                'face_enrolled' => false,
                'embedding_ready' => false,
                'reason' => 'No face images provided.',
                'provider' => $this->providerName(),
            ];
        }

        $res = $this->post('/enroll', [
            'student_id' => $studentId,
            'lecturer_id' => $lecturerId,
            'images' => $imageDataUrls,
        ], 60);

        if (!empty($res['_http_error']) || empty($res['ok'])) {
            $this->audit->storeAuditImages($studentId, $imageDataUrls);
            return [
                'ok' => false,
                'face_enrolled' => false,
                'embedding_ready' => false,
                'reason' => $res['reason'] ?? 'Face enrollment failed on face-service.',
                'provider' => $this->providerName(),
            ];
        }

        $saved = $this->audit->storeAuditImages($studentId, $imageDataUrls);
        Database::exec('UPDATE facial_templates SET is_active = 0 WHERE student_id = ?', [$studentId]);
        $id = Database::insert(
            'INSERT INTO facial_templates
             (student_id, lecturer_id, provider, template_path, embedding_meta, quality_score, is_active, embedding_ready, created_at)
             VALUES (?, ?, ?, ?, ?, ?, 1, 1, ?)',
            [
                $studentId,
                $lecturerId,
                $this->providerName(),
                $saved[0] ?? ('templates/' . $studentId . '/remote-only'),
                json_encode([
                    'files' => $saved,
                    'remote' => [
                        'faces_used' => $res['faces_used'] ?? null,
                        'embedding_dim' => $res['embedding_dim'] ?? null,
                    ],
                    'note' => 'Embedding stored by face-service; PHP keeps audit JPEGs only.',
                    'student_id' => $studentId,
                    'lecturer_id' => $lecturerId,
                ], JSON_UNESCAPED_SLASHES),
                isset($res['quality_score']) ? (float) $res['quality_score'] : null,
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
        if (!$this->isConfigured()) {
            return [
                'ok' => false,
                'pending' => true,
                'similarity' => null,
                'liveness_ok' => null,
                'identity_ok' => null,
                'reason' => 'Face API not configured or production key rejected.',
                'provider' => $this->providerName(),
            ];
        }
        if (!$this->hasReadyEmbedding($studentId)) {
            return [
                'ok' => false,
                'pending' => false,
                'similarity' => null,
                'liveness_ok' => null,
                'identity_ok' => false,
                'reason' => 'Face enrollment incomplete. Re-enrol your face before marking attendance.',
                'provider' => $this->providerName(),
            ];
        }
        $threshold = (float) config('app')['face_match_threshold'];
        $res = $this->post('/verify', [
            'student_id' => $studentId,
            'lecturer_id' => isset($liveness['lecturer_id']) ? (int) $liveness['lecturer_id'] : null,
            'images' => $imageDataUrls,
            'liveness' => [
                'challenge' => $liveness['challenge'] ?? null,
                'frames' => $liveness['frames'] ?? [],
            ],
            'threshold' => $threshold,
        ], 45);

        if (!empty($res['_http_error']) || !array_key_exists('ok', $res)) {
            return [
                'ok' => false,
                'pending' => true,
                'similarity' => null,
                'liveness_ok' => null,
                'identity_ok' => null,
                'reason' => $res['reason'] ?? 'Face API returned an invalid response.',
                'provider' => $this->providerName(),
            ];
        }

        $normalized = $this->normalizeVerifyResponse($res, $threshold);
        $normalized['provider'] = $this->providerName();
        return $normalized;
    }

    /**
     * Fail-closed verify normalizer. Similarity validated before threshold.
     *
     * @param array<string,mixed> $res
     * @return array<string,mixed>
     */
    public function normalizeVerifyResponse(array $res, float $threshold): array
    {
        $livenessRequired = (bool) (config('app')['face_require_liveness'] ?? true);

        if ($res['ok'] !== true && $res['ok'] !== 1 && $res['ok'] !== 'true') {
            $sim = FaceSimilarity::parse($res['similarity'] ?? null);
            return [
                'ok' => false,
                'pending' => !empty($res['pending']),
                'similarity' => $sim['ok'] ? $sim['value'] : null,
                'liveness_ok' => array_key_exists('liveness_ok', $res) ? (bool) $res['liveness_ok'] : null,
                'identity_ok' => array_key_exists('identity_ok', $res) ? (bool) $res['identity_ok'] : null,
                'reason' => $res['reason'] ?? 'Facial verification failed.',
            ];
        }

        if (!array_key_exists('identity_ok', $res) || ($res['identity_ok'] !== true && $res['identity_ok'] !== 1)) {
            return [
                'ok' => false,
                'pending' => true,
                'similarity' => null,
                'liveness_ok' => null,
                'identity_ok' => false,
                'reason' => 'Face API response missing identity_ok=true.',
            ];
        }

        // Similarity: present finite float in [-1,1] BEFORE threshold
        $simCheck = FaceSimilarity::meetsThreshold($res['similarity'] ?? null, $threshold);
        if (!$simCheck['ok']) {
            $pending = empty($simCheck['below_threshold']);
            return [
                'ok' => false,
                'pending' => $pending,
                'similarity' => $simCheck['value'],
                'liveness_ok' => array_key_exists('liveness_ok', $res) ? (bool) $res['liveness_ok'] : null,
                'identity_ok' => !empty($simCheck['below_threshold']) ? false : true,
                'reason' => $simCheck['reason'] ?? 'Invalid similarity.',
            ];
        }
        /** @var float $similarity */
        $similarity = $simCheck['value'];

        if ($livenessRequired) {
            if (!array_key_exists('liveness_ok', $res) || ($res['liveness_ok'] !== true && $res['liveness_ok'] !== 1)) {
                return [
                    'ok' => false,
                    'pending' => false,
                    'similarity' => $similarity,
                    'liveness_ok' => false,
                    'identity_ok' => true,
                    'reason' => 'Liveness verification failed or missing.',
                ];
            }
        }

        return [
            'ok' => true,
            'pending' => false,
            'similarity' => $similarity,
            'liveness_ok' => $livenessRequired ? true : (array_key_exists('liveness_ok', $res) ? (bool) $res['liveness_ok'] : null),
            'identity_ok' => true,
            'reason' => null,
        ];
    }

    /**
     * Lecturer ownership → remote delete confirmed (HTTP + JSON ok===true + absent) → local DB/files.
     * Never silent success.
     */
    public function deleteEnrollment(int $studentId, ?int $lecturerId = null): array
    {
        if ($lecturerId !== null) {
            $owned = Database::fetch(
                'SELECT id FROM student_registrations WHERE student_id = ? AND lecturer_id = ? LIMIT 1',
                [$studentId, $lecturerId]
            );
            if (!$owned) {
                return [
                    'ok' => false,
                    'pending_deletion' => false,
                    'reason' => 'Student not in your classes.',
                    'provider' => $this->providerName(),
                ];
            }
        }

        if ($this->isConfigured()) {
            $remote = $this->request('DELETE', '/enroll/' . $studentId, null, 15);
            $confirmed = $this->remoteDeleteConfirmed($remote);
            if (!$confirmed['ok']) {
                return [
                    'ok' => false,
                    'pending_deletion' => true,
                    'reason' => $confirmed['reason'] . ' Local biometrics were NOT cleared — retry delete.',
                    'provider' => $this->providerName(),
                    'remote' => [
                        'http_code' => $remote['_http_code'] ?? null,
                        'ok' => $remote['ok'] ?? null,
                        'absent' => $remote['absent'] ?? null,
                    ],
                ];
            }
        }

        $local = $this->audit->deleteEnrollment($studentId, $lecturerId);
        $this->audit->purgeAuditImages($studentId);

        $localOk = $this->localBiometricsCleared($studentId);
        if (!$localOk) {
            return [
                'ok' => false,
                'pending_deletion' => true,
                'reason' => 'Remote delete confirmed but local biometric cleanup incomplete — retry.',
                'provider' => $this->providerName(),
            ];
        }

        return [
            'ok' => true,
            'pending_deletion' => false,
            'local_cleared' => !empty($local['ok']),
            'remote_absent' => true,
            'provider' => $this->providerName(),
        ];
    }

    /**
     * Require HTTP 2xx, valid JSON, ok===true, and absent===true (embedding gone).
     *
     * @param array<string,mixed> $remote
     * @return array{ok:bool, reason:string}
     */
    public function remoteDeleteConfirmed(array $remote): array
    {
        if (!empty($remote['_http_error'])) {
            return ['ok' => false, 'reason' => $remote['reason'] ?? 'Face-service delete HTTP failure.'];
        }
        $code = (int) ($remote['_http_code'] ?? 0);
        if ($code < 200 || $code >= 300) {
            return ['ok' => false, 'reason' => 'Face-service delete HTTP ' . $code . ' (need 2xx).'];
        }
        if (!array_key_exists('ok', $remote)) {
            return ['ok' => false, 'reason' => 'Face-service delete response missing ok.'];
        }
        if ($remote['ok'] !== true && $remote['ok'] !== 1) {
            return ['ok' => false, 'reason' => $remote['reason'] ?? 'Face-service delete ok!==true.'];
        }
        if (!array_key_exists('absent', $remote) || ($remote['absent'] !== true && $remote['absent'] !== 1)) {
            return ['ok' => false, 'reason' => 'Face-service did not confirm embedding absent.'];
        }
        return ['ok' => true, 'reason' => ''];
    }

    private function localBiometricsCleared(int $studentId): bool
    {
        if ($this->audit->hasReadyEmbedding($studentId)) {
            return false;
        }
        $dir = config('app')['template_path'] . '/' . $studentId;
        if (is_dir($dir)) {
            $files = @scandir($dir);
            if (is_array($files)) {
                foreach ($files as $f) {
                    if ($f === '.' || $f === '..') {
                        continue;
                    }
                    if (is_file($dir . '/' . $f)) {
                        return false;
                    }
                }
            }
        }
        return true;
    }

    /** @return array<string, mixed> */
    private function post(string $path, array $body, int $timeoutSec = 20): array
    {
        return $this->request('POST', $path, $body, $timeoutSec);
    }

    /** @return array<string, mixed> */
    private function request(string $method, string $path, ?array $body, int $timeoutSec): array
    {
        $base = rtrim((string) config('app')['face_api_url'], '/');
        $url = $base . $path;
        $headers = ['Accept: application/json'];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
        }
        $key = config('app')['face_api_key'] ?? '';
        if ($key !== '') {
            $headers[] = 'Authorization: Bearer ' . $key;
        }
        $ch = curl_init($url);
        $opts = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => $timeoutSec,
        ];
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_SLASHES);
        }
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($raw === false) {
            return ['ok' => false, 'pending' => true, '_http_error' => true, '_http_code' => 0, 'reason' => 'Face API unreachable: ' . $err];
        }
        if ($code === 401 || $code === 403) {
            return ['ok' => false, 'pending' => true, '_http_error' => true, '_http_code' => $code, 'reason' => 'Face API authentication failed (HTTP ' . $code . ').'];
        }
        if ($code >= 500) {
            return ['ok' => false, 'pending' => true, '_http_error' => true, '_http_code' => $code, 'reason' => 'Face API unavailable (HTTP ' . $code . ').'];
        }
        if ($code === 204 || $raw === '') {
            // Empty body is NOT a confirmed biometric delete — require JSON with absent
            return ['ok' => false, '_http_error' => true, '_http_code' => $code, 'reason' => 'Face API returned empty body (JSON with ok+absent required).'];
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return ['ok' => false, 'pending' => true, '_http_error' => true, '_http_code' => $code, 'reason' => 'Face API non-JSON (HTTP ' . $code . ')'];
        }
        $data['_http_code'] = $code;
        return $data;
    }
}
