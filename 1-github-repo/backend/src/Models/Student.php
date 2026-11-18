<?php
declare(strict_types=1);

namespace Attender\Models;

use Attender\Database;

final class Student
{
    public static function find(int $id): ?array
    {
        return Database::fetch('SELECT * FROM students WHERE id = ? LIMIT 1', [$id]);
    }

    public static function findByIndex(string $index): ?array
    {
        return Database::fetch('SELECT * FROM students WHERE index_number = ? LIMIT 1', [strtoupper(trim($index))]);
    }
}
