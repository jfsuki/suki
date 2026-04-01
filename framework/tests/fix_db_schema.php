<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\ProjectRegistry;

$registry = new ProjectRegistry();
$db = $registry->db();

echo "Re-inicializando esquema de autenticación...\n";

// Como vimos que hay 0 usuarios, lo más limpio es dropear y recrear
$db->exec("DROP TABLE IF EXISTS auth_users");

// Llamar al método oficial para crearla con todas las nuevas columnas
$registry->initializeAuthSchema();

echo "Tabla auth_users recreada exitosamente con el nuevo schema.\n";

// Verificar columnas
$stmt = $db->query("PRAGMA table_info(auth_users)");
$cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "Columnas detectadas:\n";
foreach ($cols as $col) {
    echo "  - " . $col['name'] . " (" . $col['type'] . ")\n";
}
