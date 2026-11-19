<?php
declare(strict_types=1);

namespace Attender\Services;

use Attender\Database;
use Attender\Support\FaceSimilarity;
use PDOException;
use function Attender\Support\client_ip;
use function Attender\Support\config;
use function Attender\Support\now_sql;
use function Attender\Support\user_agent;

/**
 * Shared verification engine for QR and one-time codes.
 * Order: session → registration/enrollment → GPS → face+liveness → duplicate → decision.
 */
final class VerificationEngine
{
    public function resolveInput(string $input): array
    {
        $input = trim($input);
        if ($input === '') {
            return ['ok' => false, 'reason' => 'Missing attendance token or code.'];
        }

        // QR may be a full URL containing ?t=TOKEN
        $token = $input;
        if (str_contains($input, '://') || str_contains($input, '?')) {
            $parts = parse_url($input);
            if (!empty($parts['query'])) {
                parse_str($parts['query'], $q);
                if (!empty($q['t'])) {
                    $token = (string) $q['t'];
                }
            }
        }

        $tok = QrTokenService::findActiveByPlain($token);
        if ($tok) {
            if ($tok['session_status'] !== 'open') {
                return ['ok' => false, 'reason' => 'Attendance session closed.'];
            }
            if (strtotime((string) $tok['expires_at']) < time()) {
                return ['ok' => false, 'reason' => 'Expired QR code.'];
            }
            return $this->sessionPayload((int) $tok['session_id'], 'qr');
        }

        $code = CodeService::findActiveByPlain($input);
        if ($code) {
            if ($code['session_status'] !== 'open') {
                return ['ok' => false, 'reason' => 'Attendance session closed.'];
            }
            if (strtotime((string) $code['expires_at']) < time()) {
                return ['ok' => false, 'reason' => 'Expired attendance code.'];
            }
            return $this->sessionPayload((int) $code['session_id'], 'code');
        }

        return ['ok' => false, 'reason' => strlen($input) > 12 ? 'Expired QR code.' : 'Invalid attendance code.'];
    }

    public function submit(array $payload): array
    {
        $input = (string) ($payload['input'] ?? '');
        $index = strtoupper(trim((string) ($payload['index'] ?? '')));
        $isCodeInput = !(str_contains($input, 't=') || strlen($input) > 12);
        $inputType = $isCodeInput ? 'code' : 'qr';

        // "Max code attempts per student" (Settings): too many wrong codes in the code's lifetime → locked out.
        $knownStudent = $index !== '' ? Database::fetch('SELECT id FROM students WHERE index_number = ? LIMIT 1', [$index]) : null;
        if ($isCodeInput && $knownStudent) {
            $limit = $this->codeAttemptLimit((int) $knownStudent['id']);
            $window = date('Y-m-d H:i:s', time() - ((int) (config('security')['code_ttl_minutes'] ?? 10)) * 60);
            $wrong = (int) (Database::fetch(
                "SELECT COUNT(*) c FROM verification_attempts
                 WHERE student_id = ? AND input_type = 'code' AND stage = 'session' AND outcome = 'fail'
                   AND reason = 'Invalid attendance code.' AND created_at > ?",
                [(int) $knownStudent['id'], $window]
            )['c'] ?? 0);
            if ($wrong >= $limit) {
                return ['ok' => false, 'reason' => 'Too many wrong codes. Ask your lecturer for a new code, then try again in a few minutes.'];
            }
        }

        $resolved = $this->resolveInput($input);
        if (!$resolved['ok']) {
            $this->attempt(null, $knownStudent ? (int) $knownStudent['id'] : null, null, $inputType, 'session', 'fail', $resolved['reason']);
            return $resolved;
        }

        $session = $resolved['session'];
        $method = $resolved['method_used'];
        $lecturerId = (int) $session['lecturer_id'];
        $sessionId = (int) $session['id'];

        $student = Database::fetch('SELECT * FROM students WHERE index_number = ? LIMIT 1', [$index]);
        if (!$student) {
            $this->attempt($sessionId, null, $lecturerId, $method, 'registration', 'fail', 'Student not found.');
            return ['ok' => false, 'reason' => 'Student not found.'];
        }
        $studentId = (int) $student['id'];

        $reg = Database::fetch(
            'SELECT * FROM student_registrations
             WHERE student_id = ? AND class_id = ? AND lecturer_id = ? LIMIT 1',
            [$studentId, (int) $session['class_id'], $lecturerId]
        );
        if (!$reg || $reg['status'] !== 'approved') {
            $this->attempt($sessionId, $studentId, $lecturerId, $method, 'registration', 'fail', 'Student not approved.');
            return ['ok' => false, 'reason' => 'Student not approved.'];
        }

        $enroll = Database::fetch(
            'SELECT id FROM course_enrollments WHERE course_id = ? AND student_id = ? LIMIT 1',
            [(int) $session['course_id'], $studentId]
        );
        if (!$enroll) {
            $this->attempt($sessionId, $studentId, $lecturerId, $method, 'enrollment', 'fail', 'Course enrollment not found.');
            return ['ok' => false, 'reason' => 'Course enrollment not found.'];
        }
        $this->attempt($sessionId, $studentId, $lecturerId, $method, 'enrollment', 'pass', null);

        // Duplicate check early (also enforced by UNIQUE constraint)
        $existing = Database::fetch(
            'SELECT id, status FROM attendance_records WHERE session_id = ? AND student_id = ? LIMIT 1',
            [$sessionId, $studentId]
        );
        if ($existing && in_array($existing['status'], ['present', 'late', 'pending', 'review'], true)) {
            $this->attempt($sessionId, $studentId, $lecturerId, $method, 'duplicate', 'fail', 'Attendance already recorded.');
            return ['ok' => false, 'reason' => 'Attendance already recorded.'];
        }

        $distance = null;
        $accuracy = isset($payload['accuracy']) ? (float) $payload['accuracy'] : null;
        $lat = isset($payload['lat']) ? (float) $payload['lat'] : null;
        $lng = isset($payload['lng']) ? (float) $payload['lng'] : null;

        if ((int) $session['require_gps'] === 1) {
            if ($lat === null || $lng === null || $session['latitude'] === null || $session['longitude'] === null) {
                $this->attempt($sessionId, $studentId, $lecturerId, $method, 'gps', 'fail', 'Location could not be verified.');
                return ['ok' => false, 'reason' => 'Location could not be verified.'];
            }
            $settings = SettingsService::get($lecturerId);
            $geo = GeoService::withinRadius(
                (float) $session['latitude'],
                (float) $session['longitude'],
                $lat,
                $lng,
                (float) $session['radius_m'],
                $accuracy,
                (float) ($settings['minAccuracy'] ?? 50)
            );
            $distance = $geo['distance'];
            if (!$geo['ok']) {
                $this->attempt($sessionId, $studentId, $lecturerId, $method, 'gps', 'fail', $geo['reason'], [
                    'distance' => $distance, 'accuracy' => $accuracy,
                ]);
                $this->upsertFailedRecord($session, $student, $method, $geo['reason'], $distance, $accuracy, $lat, $lng);
                return ['ok' => false, 'reason' => $geo['reason'], 'distance' => $distance];
            }
            $this->attempt($sessionId, $studentId, $lecturerId, $method, 'gps', 'pass', null, ['distance' => $distance]);
        }

        $similarity = null;
        $facePending = false;
        if ((int) $session['require_face'] === 1) {
            $faceSvc = FaceService::make();
            if (!$faceSvc->hasReadyEmbedding($studentId)) {
                $reason = 'Face enrollment incomplete. Re-enrol your face before marking attendance.';
                $this->attempt($sessionId, $studentId, $lecturerId, $method, 'face', 'fail', $reason);
                $this->upsertFailedRecord($session, $student, $method, $reason, $distance, $accuracy, $lat, $lng);
                return ['ok' => false, 'reason' => $reason];
            }
            $images = $payload['faceImages'] ?? $payload['images'] ?? [];
            $descriptors = $payload['faceDescriptors'] ?? [];
            if (!is_array($images)) {
                $images = [];
            }
            if (!is_array($descriptors)) {
                $descriptors = [];
            }
            if (!$images && !$descriptors) {
                $reason = 'Face capture required for this session.';
                $this->attempt($sessionId, $studentId, $lecturerId, $method, 'face', 'fail', $reason);
                return ['ok' => false, 'reason' => $reason];
            }
            $liveness = [
                'challenge' => $payload['livenessChallenge'] ?? null,
                'frames' => $payload['livenessFrames'] ?? [],
                'lecturer_id' => $lecturerId,
                // browser face engine: descriptors + the result of the on-screen challenge
                'descriptors' => $descriptors,
                'client' => [
                    'challenge' => $payload['livenessChallenge'] ?? null,
                    'passed' => ($payload['livenessPassed'] ?? null) === true,
                ],
            ];
            $face = $faceSvc->verify($studentId, $images, $liveness);
            $unavailablePolicy = (string) (config('app')['face_unavailable_policy'] ?? 'pending');
            $livenessRequired = (bool) (config('app')['face_require_liveness'] ?? true);
            // The lecturer's Settings slider is the live policy; the env value is only a fallback.
            $threshold = (float) (SettingsService::get($lecturerId)['faceThreshold']
                ?? (config('app')['face_match_threshold'] ?? 0.80));

            // Fail-closed: invalid / incomplete face payloads NEVER become PRESENT/LATE.
            if (!empty($face['pending']) || empty($face['ok'])) {
                if (!empty($face['pending']) && $unavailablePolicy !== 'fail' && empty($face['ok'])) {
                    $facePending = true;
                    $this->attempt($sessionId, $studentId, $lecturerId, $method, 'face', 'pending', $face['reason'] ?? 'Face pending');
                } else {
                    $reason = $face['reason'] ?? 'Facial verification failed.';
                    $this->attempt($sessionId, $studentId, $lecturerId, $method, 'face', 'fail', $reason);
                    $this->upsertFailedRecord($session, $student, $method, $reason, $distance, $accuracy, $lat, $lng);
                    return ['ok' => false, 'reason' => $reason];
                }
            } else {
                if (!array_key_exists('identity_ok', $face) || $face['identity_ok'] !== true) {
                    $reason = $face['reason'] ?? 'Facial verification incomplete (identity_ok required).';
                    $this->attempt($sessionId, $studentId, $lecturerId, $method, 'face', 'fail', $reason);
                    $this->upsertFailedRecord($session, $student, $method, $reason, $distance, $accuracy, $lat, $lng);
                    return ['ok' => false, 'reason' => $reason];
                }
                // Validate finite similarity in [-1,1] BEFORE threshold compare
                $simCheck = FaceSimilarity::meetsThreshold($face['similarity'] ?? null, $threshold);
                if (!$simCheck['ok']) {
                    $reason = $simCheck['reason'] ?? 'Invalid similarity.';
                    $this->attempt($sessionId, $studentId, $lecturerId, $method, 'face', 'fail', $reason);
                    $this->upsertFailedRecord($session, $student, $method, $reason, $distance, $accuracy, $lat, $lng, $simCheck['value']);
                    return ['ok' => false, 'reason' => $reason];
                }
                $similarity = $simCheck['value'];
                if ($livenessRequired) {
                    if (!array_key_exists('liveness_ok', $face) || $face['liveness_ok'] !== true) {
                        $reason = $face['reason'] ?? 'Liveness verification failed or missing.';
                        $this->attempt($sessionId, $studentId, $lecturerId, $method, 'liveness', 'fail', $reason);
                        $this->upsertFailedRecord($session, $student, $method, $reason, $distance, $accuracy, $lat, $lng, $similarity);
                        return ['ok' => false, 'reason' => $reason];
                    }
                }
                $this->attempt($sessionId, $studentId, $lecturerId, $method, 'face', 'pass', null, ['similarity' => $similarity]);
            }
        }

        $opened = strtotime((string) $session['opened_at']);
        $minutes = max(0, (time() - $opened) / 60);
        $lateAfter = (int) $session['late_after_min'];
        // pending is never PRESENT/late
        $status = $facePending ? 'pending' : (($lateAfter > 0 && $minutes > $lateAfter) ? 'late' : 'present');

        try {
            Database::begin();
            if ($existing) {
                Database::exec(
                    'UPDATE attendance_records SET status=?, method_used=?, marked_at=?, distance_m=?, gps_accuracy_m=?,
                     latitude=?, longitude=?, face_similarity=?, fail_reason=?, updated_at=? WHERE id=?',
                    [
                        $status, $method, now_sql(), $distance, $accuracy, $lat, $lng, $similarity,
                        $facePending ? 'Face check pending review' : null, now_sql(), (int) $existing['id'],
                    ]
                );
            } else {
                Database::insert(
                    'INSERT INTO attendance_records
                     (session_id, lecturer_id, student_id, status, method_used, marked_at, distance_m, gps_accuracy_m,
                      latitude, longitude, face_similarity, fail_reason, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $sessionId, $lecturerId, $studentId, $status, $method, now_sql(),
                        $distance, $accuracy, $lat, $lng, $similarity,
                        $facePending ? 'Face check pending review' : null, now_sql(),
                    ]
                );
            }
            Database::commit();
        } catch (PDOException $e) {
            Database::rollBack();
            if (str_contains($e->getMessage(), 'uq_record_session_student') || (int) $e->getCode() === 23000) {
                $this->attempt($sessionId, $studentId, $lecturerId, $method, 'duplicate', 'fail', 'Attendance already recorded.');
                return ['ok' => false, 'reason' => 'Attendance already recorded.'];
            }
            throw $e;
        }

        $course = Database::fetch('SELECT code, title FROM courses WHERE id = ?', [(int) $session['course_id']]);
        $this->attempt($sessionId, $studentId, $lecturerId, $method, 'decision', $facePending ? 'pending' : 'pass', null, ['status' => $status]);

        return [
            'ok' => true,
            'pending' => $facePending,
            'status' => $status,
            'course' => $course ? ['code' => $course['code'], 'title' => $course['title']] : null,
            'name' => $student['full_name'],
            'distance' => $distance,
            'reason' => $facePending ? 'Face check pending review' : null,
        ];
    }

    /** Strictest "max code attempts" among the lecturers this student is registered with (default 5). */
    private function codeAttemptLimit(int $studentId): int
    {
        $rows = Database::fetchAll(
            "SELECT DISTINCT lecturer_id FROM student_registrations WHERE student_id = ? AND status = 'approved'",
            [$studentId]
        );
        $limit = null;
        foreach ($rows as $r) {
            $v = (int) (SettingsService::get((int) $r['lecturer_id'])['maxCodeAttempts'] ?? 5);
            $limit = $limit === null ? $v : min($limit, $v);
        }
        return max(1, $limit ?? 5);
    }

    private function sessionPayload(int $sessionId, string $method): array
    {
        $session = Database::fetch('SELECT * FROM attendance_sessions WHERE id = ?', [$sessionId]);
        if (!$session || $session['status'] !== 'open') {
            return ['ok' => false, 'reason' => 'Attendance session closed.'];
        }
        $course = Database::fetch('SELECT id, code, title FROM courses WHERE id = ?', [(int) $session['course_id']]);
        return [
            'ok' => true,
            'method_used' => $method,
            'session' => $session,
            'course' => $course,
            'radius' => (int) $session['radius_m'],
            'faceMode' => FaceService::make()->providerName(),
            'minAccuracy' => (int) (SettingsService::get((int) $session['lecturer_id'])['minAccuracy'] ?? 50),
            'gps' => (int) $session['require_gps'] === 1,
            'face' => (int) $session['require_face'] === 1,
        ];
    }

    private function upsertFailedRecord(
        array $session,
        array $student,
        string $method,
        string $reason,
        ?float $distance,
        ?float $accuracy,
        ?float $lat,
        ?float $lng,
        ?float $similarity = null
    ): void {
        $existing = Database::fetch(
            'SELECT id FROM attendance_records WHERE session_id = ? AND student_id = ?',
            [(int) $session['id'], (int) $student['id']]
        );
        if ($existing) {
            return; // do not overwrite a successful mark with a later failure noise
        }
        try {
            Database::insert(
                'INSERT INTO attendance_records
                 (session_id, lecturer_id, student_id, status, method_used, marked_at, distance_m, gps_accuracy_m,
                  latitude, longitude, face_similarity, fail_reason, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    (int) $session['id'], (int) $session['lecturer_id'], (int) $student['id'],
                    'failed', $method, now_sql(), $distance, $accuracy, $lat, $lng, $similarity, $reason, now_sql(),
                ]
            );
        } catch (PDOException $e) {
            // ignore race
        }
    }

    private function attempt(
        ?int $sessionId,
        ?int $studentId,
        ?int $lecturerId,
        ?string $inputType,
        string $stage,
        string $outcome,
        ?string $reason,
        ?array $meta = null
    ): void {
        Database::insert(
            'INSERT INTO verification_attempts
             (session_id, student_id, lecturer_id, input_type, stage, outcome, reason, ip_address, user_agent, meta_json, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $sessionId, $studentId, $lecturerId, $inputType, $stage, $outcome, $reason,
                client_ip(), user_agent(), $meta ? json_encode($meta) : null, now_sql(),
            ]
        );
    }
}
