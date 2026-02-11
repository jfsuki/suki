<?php
// CLI runner: migrate entities to DB (create-if-missing)

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only";
    exit;
}

require_once __DIR__ . '/../../framework/app/autoload.php';
require_once __DIR__ . '/../config/env_loader.php';

use App\Core\EntityMigrator;

$apply = in_array('--apply', $argv, true);
$dry = in_array('--dry', $argv, true) || !$apply;

try {
    $migrator = new EntityMigrator();
    $results = $migrator->migrateAll(!$dry);

    if ($dry) {
        echo "DRY RUN - SQL generado (sin ejecutar)" . PHP_EOL;
    } else {
        echo "MIGRACION EJECUTADA" . PHP_EOL;
    }

    foreach ($results as $res) {
        echo "- " . $res['entity'] . PHP_EOL;
        foreach ($res['sql'] as $sql) {
            echo "  " . str_replace("\n", " ", $sql) . PHP_EOL;
        }
    }

    echo PHP_EOL;
    echo "Uso: php project/bin/migrate.php --apply (ejecuta)" . PHP_EOL;
    echo "Uso: php project/bin/migrate.php --dry (solo SQL)" . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . PHP_EOL);
    exit(1);
}
