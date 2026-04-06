<?php
// framework/scripts/cleanup_registry.php
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\ProjectRegistry;

$registry = new ProjectRegistry();
$db = $registry->db();

echo "--- SUKI REGISTRY CLEANUP (PHASE GREENFIELD) ---\n";

try {
    // 1. Proyectos (Mantener solo default)
    $stmt = $db->prepare("DELETE FROM projects WHERE id != 'default'");
    $stmt->execute();
    echo "[OK] Proyectos obsoletos eliminados.\n";

    // 2. Usuarios Creadores y Auth
    $db->exec("DELETE FROM users");
    $db->exec("DELETE FROM auth_users");
    echo "[OK] Todos los usuarios eliminados.\n";

    // 3. Tablas de Relación y Estado
    $db->exec("DELETE FROM project_users");
    $db->exec("DELETE FROM entities");
    $db->exec("DELETE FROM chat_sessions");
    $db->exec("DELETE FROM login_attempts");
    echo "[OK] Sesiones y entidades limpias.\n";

    // 4. Asegurar proyecto base
    $registry->ensureProject('default', 'Suki ERP', 'draft', 'shared', '', 'legacy');
    echo "[OK] Proyecto 'default' restaurado.\n";

} catch (\Exception $e) {
    echo "[ERROR] Fallo en la limpieza: " . $e->getMessage() . "\n";
    exit(1);
}

echo "--- LIMPIEZA COMPLETADA ---\n";
