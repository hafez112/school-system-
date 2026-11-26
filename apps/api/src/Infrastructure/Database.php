<?php

declare(strict_types=1);

namespace SchoolSystem\Infrastructure;

use PDO;
use PDOException;

final class Database
{
    public static function connect(): PDO
    {
        $host = getenv('DB_HOST') ?: 'mysql';
        $port = getenv('DB_PORT') ?: '3306';
        $name = getenv('DB_DATABASE') ?: 'school_system';
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $name);

        return new PDO($dsn, getenv('DB_USERNAME') ?: 'school', getenv('DB_PASSWORD') ?: '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
}
