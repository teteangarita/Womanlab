<?php
declare(strict_types=1);

namespace WomanLab\Support;

use PDO;
use RuntimeException;

final class Database
{
    private static ?PDO $connection = null;
    private static array $configuration = [];

    public static function configure(array $configuration): void
    {
        self::$configuration = $configuration;
        self::$connection = null;
    }

    public static function connection(): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        foreach (['host', 'name', 'user'] as $required) {
            if (empty(self::$configuration[$required])) {
                throw new RuntimeException('Falta configuración de base de datos.');
            }
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            self::$configuration['host'],
            self::$configuration['port'] ?: '3306',
            self::$configuration['name'],
            self::$configuration['charset'] ?: 'utf8mb4'
        );

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ];
        $sslCa = getenv('DB_SSL_CA');
        if (is_string($sslCa) && $sslCa !== '') {
            $options[PDO::MYSQL_ATTR_SSL_CA] = $sslCa;
            $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
        }

        self::$connection = new PDO($dsn, self::$configuration['user'], self::$configuration['password'], $options);

        return self::$connection;
    }
}