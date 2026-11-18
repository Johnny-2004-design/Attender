<?php
declare(strict_types=1);

/**
 * XAMPP defaults: host=127.0.0.1, user=root, password="", db=attender
 * Override with environment variables or edit this file for your machine.
 */
return [
    'driver' => 'mysql',
    'host' => getenv('ATTENDER_DB_HOST') ?: '127.0.0.1',
    'port' => (int) (getenv('ATTENDER_DB_PORT') ?: '3306'),
    'database' => getenv('ATTENDER_DB_NAME') ?: 'attender',
    'username' => getenv('ATTENDER_DB_USER') ?: 'root',
    'password' => getenv('ATTENDER_DB_PASS') !== false ? (string) getenv('ATTENDER_DB_PASS') : '',
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    // Optional unix socket (used in some local/dev setups)
    'unix_socket' => getenv('ATTENDER_DB_SOCKET') ?: '',
];
