<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../app/autoload.php';

$pdo = \App\Core\Database::connection();
$sql = file_get_contents(__DIR__ . '/../db/migrations/mysql/20260521_025_ai_agents_specialist_personas.sql');
try {
    $pdo->exec($sql);
    echo "ai_agents: CREADA OK" . PHP_EOL;
    $r = $pdo->query('DESCRIBE ai_agents')->fetchAll(PDO::FETCH_COLUMN);
    echo "Columnas: " . implode(', ', $r) . PHP_EOL;
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . PHP_EOL;
    exit(1);
}
