<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../app/autoload.php';

$pdo = \App\Core\Database::connection();
$sql = file_get_contents(__DIR__ . '/../db/migrations/mysql/20260521_028_tenant_auth.sql');
try {
    $pdo->exec($sql);
    echo "otp_tokens: CREADA OK" . PHP_EOL;
    $cols = $pdo->query('DESCRIBE otp_tokens')->fetchAll(PDO::FETCH_COLUMN);
    echo "Columnas otp_tokens: " . implode(', ', $cols) . PHP_EOL;
    $cols2 = $pdo->query('DESCRIBE otp_pending_registrations')->fetchAll(PDO::FETCH_COLUMN);
    echo "Columnas otp_pending_registrations: " . implode(', ', $cols2) . PHP_EOL;
} catch (\Exception $e) {
    echo "ERROR: " . $e->getMessage() . PHP_EOL;
    exit(1);
}
