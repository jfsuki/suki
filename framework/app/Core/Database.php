<?php
// app/Core/Database.php

namespace App\Core;

use PDO;
use PDOException;
use RuntimeException;

class Database
{
    private static ?PDO $pdo = null;

    public static function setConnection(PDO $pdo): void
    {
        self::$pdo = $pdo;
    }

    public static function connection(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $driver = getenv('DB_DRIVER') ?: ($_ENV['DB_DRIVER'] ?? ($_SERVER['DB_DRIVER'] ?? 'mysql'));
        $host = getenv('DB_HOST') ?: ($_ENV['DB_HOST'] ?? ($_SERVER['DB_HOST'] ?? 'localhost'));
        $port = getenv('DB_PORT') ?: ($_ENV['DB_PORT'] ?? ($_SERVER['DB_PORT'] ?? ''));
        $db = getenv('DB_NAME') ?: ($_ENV['DB_NAME'] ?? ($_SERVER['DB_NAME'] ?? ''));
        $user = getenv('DB_USER') ?: ($_ENV['DB_USER'] ?? ($_SERVER['DB_USER'] ?? ''));
        $pass = getenv('DB_PASS') ?: ($_ENV['DB_PASS'] ?? ($_SERVER['DB_PASS'] ?? ''));
        $charset = getenv('DB_CHARSET') ?: ($_ENV['DB_CHARSET'] ?? ($_SERVER['DB_CHARSET'] ?? 'utf8mb4'));
        $path = getenv('DB_PATH') ?: ($_ENV['DB_PATH'] ?? ($_SERVER['DB_PATH'] ?? ''));

        try {
            $dsn = self::buildDsn($driver, $host, $port, $db, $charset, $path);
            self::$pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            throw new RuntimeException('Error de conexion DB: ' . $e->getMessage(), 0, $e);
        }

        return self::$pdo;
    }

    /**
     * Instance version of connection() for dependency injection.
     */
    public function getPdo(): PDO
    {
        return self::connection();
    }

    private static function buildDsn(
        string $driver,
        string $host,
        string $port,
        string $db,
        string $charset,
        string $path
    ): string {
        $driver = strtolower(trim($driver));

        if ($driver === 'sqlite') {
            $sqlitePath = $path ?: $db;
            if ($sqlitePath === '') {
                throw new RuntimeException('DB_PATH o DB_NAME requerido para sqlite.');
            }
            return "sqlite:{$sqlitePath}";
        }

        if ($driver === 'pgsql') {
            $dsn = "pgsql:host={$host}";
            if ($port !== '') {
                $dsn .= ";port={$port}";
            }
            if ($db !== '') {
                $dsn .= ";dbname={$db}";
            }
            return $dsn;
        }

        $dsn = "mysql:host={$host}";
        if ($port !== '') {
            $dsn .= ";port={$port}";
        }
        if ($db !== '') {
            $dsn .= ";dbname={$db}";
        }
        if ($charset !== '') {
            $dsn .= ";charset={$charset}";
        }
        return $dsn;
    }
}
