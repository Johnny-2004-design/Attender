<?php
declare(strict_types=1);

namespace Attender\Models;

use Attender\Database;

final class Lecturer
{
    public static function find(int $id): ?array
    {
        return Database::fetch('SELECT * FROM lecturers WHERE id = ? LIMIT 1', [$id]);
    }

    public static function findByEmail(string $email): ?array
    {
        return Database::fetch('SELECT * FROM lecturers WHERE email = ? LIMIT 1', [strtolower($email)]);
    }
}
