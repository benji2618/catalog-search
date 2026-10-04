<?php
declare(strict_types=1);
namespace App;

use Dotenv\Dotenv;
use RuntimeException;

final class Config
{
    private static bool $loaded = false;

    public static function get(string $key, ?string $default = null): string
    {
        if (!self::$loaded) {
            Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
            self::$loaded = true;
        }
        $value = $_ENV[$key] ?? $default;
        if ($value === null) {
            throw new RuntimeException("Missing config: $key");
        }
        return $value;
    }
}
