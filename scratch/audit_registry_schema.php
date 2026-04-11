<?php
// scratch/audit_registry_schema.php
require_once __DIR__ . '/../framework/app/autoload.php';
require_once __DIR__ . '/../project/public/api.php';

use App\Core\ProjectRegistry;

try {
    $registry = new ProjectRegistry();
    $db = $registry->db();
    
    echo "--- PROJECT REGISTRY AUDIT ---\n";
    echo "Driver: " . $db->getAttribute(PDO::ATTR_DRIVER_NAME) . "\n";
    
    // Check tables
    $res = $db->query("SELECT name FROM sqlite_master WHERE type='table'");
    echo "Tables found:\n";
    while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
        echo " - " . $row['name'] . "\n";
    }
    
    // Check chat_sessions schema
    echo "\nSchema for chat_sessions:\n";
    $res = $db->query("PRAGMA table_info(chat_sessions)");
    while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
        echo " - Column: {$row['name']} ({$row['type']})\n";
    }

} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
}
