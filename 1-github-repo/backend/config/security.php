<?php
declare(strict_types=1);

return [
    'password_algo' => PASSWORD_DEFAULT,
    'password_min' => 8,
    'session_lifetime' => 60 * 60 * 8, // 8 hours
    'csrf_ttl' => 60 * 60 * 8,
    'rate_limit' => [
        'login' => ['max' => 10, 'window' => 300],
        'attendance' => ['max' => 30, 'window' => 60],
        'registration' => ['max' => 20, 'window' => 600],
    ],
    'token_bytes' => 24,
    'code_alphabet' => 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789',
    'code_length' => 6,
    'code_ttl_minutes' => 10,
    'qr_token_ttl_seconds' => 30,
    'max_code_attempts' => 5,
    'default_radius_m' => 30,
    'min_gps_accuracy_m' => 50,
];
