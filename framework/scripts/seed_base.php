<?php
// framework/scripts/seed_base.php

require_once __DIR__ . '/../app/autoload.php';
// basic env load if needed (now handled by autoload.php if PROJECT_ROOT is correct)

use App\Core\Database;

try {
    $db = Database::connection();
    $sqlFile = __DIR__ . '/../../project/storage/sql/seed_puc_colombia.sql';

    if (!file_exists($sqlFile)) {
        die("❌ Error: No se encuentra el archivo SQL en $sqlFile\n");
    }

    $sql = file_get_contents($sqlFile);
    
    // Split by semicolon ignoring quotes is hard with regex, 
    // but for this simple seeder we can split by ;\n
    $queries = explode(";\n", $sql);

    echo "🚀 Iniciando carga de PUC Colombia...\n";
    $count = 0;
    foreach ($queries as $query) {
        $query = trim($query);
        if ($query === '') continue;
        try {
            $db->exec($query);
            $count++;
        } catch (Exception $e) {
            echo "⚠️  Salto consulta (posible duplicado): " . substr($query, 0, 50) . "...\n";
        }
    }

    echo "✅ Éxito: Se cargaron $count cuentas de base al tenant 'system'.\n";

} catch (Exception $e) {
    echo "❌ Error fatal: " . $e->getMessage() . "\n";
}
