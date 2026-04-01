<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\ProjectRegistry;
use App\Core\ControlTowerService;

echo "--- TEST: SUKI CONTROL TOWER ---\n";

$registry = new ProjectRegistry();
$tower = new ControlTowerService($registry);

// 1. Probar Master Key (Safe Fallback)
$testKey = 'SUKI_DEV_TOWER_2026';
echo "Verificando Master Key ($testKey): " . ($tower->verifyAccess($testKey) ? "EXITO" : "FALLO") . "\n";

$badKey = 'WRONG_KEY';
echo "Verificando Key Incorrecta ($badKey): " . (!$tower->verifyAccess($badKey) ? "EXITO (Rechazado)" : "FALLO (Aceptado incorrectamente)") . "\n";

// 2. Listar pendientes
$pending = $tower->getPendingRegistrations();
echo "Empresas pendientes encontradas: " . count($pending) . "\n";

foreach ($pending as $user) {
    echo "  - [" . $user['id'] . "] " . $user['full_name'] . " (NIT: " . $user['nit'] . ")\n";
}

// 3. Simular activación (si hay pendientes)
if (!empty($pending)) {
    $target = $pending[0];
    echo "Simulando activación de: " . $target['full_name'] . "\n";
    $activated = $tower->activateCompany((string)$target['id']);
    echo "Resultado de activación: " . ($activated ? "EXITO" : "FALLO") . "\n";
    
    // Verificar estado actual
    $check = $tower->getCompanyDetail((string)$target['id']);
    echo "Nuevo estado en DB: " . ($check['is_active'] == 1 ? "ACTIVO" : "INACTIVO") . "\n";
    
    // Revertir para mantener el estado de prueba
    $tower->deactivateCompany((string)$target['id']);
    echo "Estado revertido para pruebas manuales posteriores.\n";
}

echo "---------------------------------\n";
echo "Prueba de la Torre de Control finalizada.\n";
