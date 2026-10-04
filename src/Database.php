<?php
declare(strict_types=1);
namespace App;

use PDO;

final class Database
{
    private static ?PDO $pdo = null;

    public static function get(): PDO
    {
        return self::$pdo ??= new PDO(
            sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                Config::get('DB_HOST'), Config::get('DB_PORT'), Config::get('DB_NAME')),
            Config::get('DB_USER'),
            Config::get('DB_PASS'),
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
    }
}
