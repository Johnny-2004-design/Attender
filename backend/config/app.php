<?php
declare(strict_types=1);

// Note: getenv('ATTENDER_DEBUG') ?: '1' is WRONG — PHP treats string "0" as falsy.
$debugEnv = getenv('ATTENDER_DEBUG');
$debug = $debugEnv === false
    ? true
    : in_array(strtolower((string) $debugEnv), ['1', 'true', 'yes', 'on'], true);

return [
    'name' => 'ATTENDER',
    'slogan' => 'Smart Attendance, Verified Presence',
    'env' => getenv('ATTENDER_ENV') ?: 'local',
    'debug' => $debug,
    'url' => rtrim(getenv('ATTENDER_URL') ?: '', '/'),
    // Split deployment: comma-separated list of EXACT frontend origins allowed to call
    // this API with cookies, e.g. https://attender.netlify.app  (no trailing slash, no wildcards).
    // Empty = same-origin only (XAMPP / single-host installs behave exactly as before).
    'allowed_origins' => array_values(array_filter(array_map(
        static fn (string $o): string => rtrim(trim($o), '/'),
        explode(',', (string) (getenv('ATTENDER_ALLOWED_ORIGINS') ?: ''))
    ))),
    'timezone' => 'Africa/Accra',
    'session_name' => 'ATTENDERSESSID',
    'csrf_key' => '_csrf',
    // Face: "null" = no embeddings (never PRESENT for face-required). "http"/"onnx" = face-service.
    // Example: ATTENDER_FACE_API_URL=http://127.0.0.1:8090  (no /v1 suffix). Key required for http.
    'face_provider' => getenv('ATTENDER_FACE_PROVIDER') ?: 'null',
    'face_api_url' => getenv('ATTENDER_FACE_API_URL') ?: '',
    'face_api_key' => getenv('ATTENDER_FACE_API_KEY') ?: '',
    'face_match_threshold' => (float) (getenv('ATTENDER_FACE_THRESHOLD') ?: '0.80'),
    // When true (or provider is http/onnx), registration fails unless embedding was saved.
    'face_require_embedding' => (getenv('ATTENDER_FACE_REQUIRE_EMBEDDING') ?: '0') === '1',
    // Soft motion liveness required for PRESENT/LATE when face is required (default true).
    'face_require_liveness' => (getenv('ATTENDER_FACE_REQUIRE_LIVENESS') ?: '1') === '1',
    // If face service is down during attendance: "pending" (default) or "fail" (stricter).
    'face_unavailable_policy' => getenv('ATTENDER_FACE_UNAVAILABLE_POLICY') ?: 'pending',
    'storage_path' => dirname(__DIR__) . '/storage',
    'template_path' => dirname(__DIR__) . '/storage/templates',
];
