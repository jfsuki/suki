<?php
// config/db.php
require_once 'env_loader.php';

if (class_exists('App\\Core\\Database')) {
    $pdo = App\Core\Database::connection();
} else {
    $driver = getenv('DB_DRIVER') ?: 'mysql';
    $host = getenv('DB_HOST') ?: 'localhost';
    $port = getenv('DB_PORT') ?: '';
    $db   = getenv('DB_NAME') ?: '';
    $user = getenv('DB_USER') ?: '';
    $pass = getenv('DB_PASS') ?: '';
    $charset = getenv('DB_CHARSET') ?: 'utf8mb4';
    $path = getenv('DB_PATH') ?: '';

    if ($driver === 'sqlite') {
        $sqlitePath = $path ?: $db;
        $dsn = "sqlite:$sqlitePath";
    } elseif ($driver === 'pgsql') {
        $dsn = "pgsql:host=$host" . ($port !== '' ? ";port=$port" : '') . ($db !== '' ? ";dbname=$db" : '');
    } else {
        $dsn = "mysql:host=$host" . ($port !== '' ? ";port=$port" : '') . ($db !== '' ? ";dbname=$db" : '') . ";charset=$charset";
    }

    try {
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]);
    } catch (PDOException $e) {
        die("Error de conexion: " . $e->getMessage());
    }
}

function db(): PDO
{
    global $pdo;
    return $pdo;
}
