<?php
/**
 * Apply Schema Migrations — Fix Runtime Schema Guard Blocker
 *
 * Run ONCE to enable all POS, Accounting, Purchases modules
 *
 * Usage:
 *   php framework/scripts/apply_schema_migrations.php
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\Database;
use App\Core\EntityMigrator;

echo "=== SUKI Schema Migration Applier ===\n\n";

putenv('ALLOW_RUNTIME_SCHEMA=1');

try {
    $db = Database::connection();
    echo "✅ Database connected\n";

    // Create migrations table if missing
    $db->exec("
        CREATE TABLE IF NOT EXISTS suki_migrations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            migration_key VARCHAR(255) UNIQUE NOT NULL,
            checksum VARCHAR(64) NOT NULL,
            applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");
    echo "✅ Migrations table ensured\n\n";

    // Apply entity migrations
    $migrator = new EntityMigrator();
    echo "Running entity migrations...\n";
    $results = $migrator->migrateAll(apply: true);

    $applied = 0;
    $skipped = 0;
    $errors = [];

    foreach ($results as $result) {
        if (isset($result['applied']) && $result['applied']) {
            $applied++;
            echo "  ✅ " . $result['entity'] . "\n";
        } else {
            $skipped++;
        }

        if (!empty($result['error'])) {
            $errors[] = $result['error'];
        }
    }

    echo "\n=== Results ===\n";
    echo "Applied: $applied migrations\n";
    echo "Skipped: $skipped (already up-to-date)\n";

    if (!empty($errors)) {
        echo "\n❌ Errors:\n";
        foreach ($errors as $err) {
            echo "  - $err\n";
        }
        exit(1);
    } else {
        echo "\n✅ All migrations applied successfully!\n";
        echo "\nYou can now use:\n";
        echo "  - POS module (pos_sessions, sale_drafts, pos_sales, etc.)\n";
        echo "  - Accounting module (cuentas_contables, asientos_contables)\n";
        echo "  - Purchases module (compras, ordenes_compra)\n";
        exit(0);
    }

} catch (Throwable $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    exit(1);
}
