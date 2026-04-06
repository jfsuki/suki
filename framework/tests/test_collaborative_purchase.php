<?php
// framework/tests/test_collaborative_purchase.php
declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../framework/app/autoload.php';

putenv('ALLOW_RUNTIME_SCHEMA=1');
putenv('APP_ENV=local');

use App\Core\ProjectRegistry;

echo "🚀 Iniciando Simulación de Compra Multi-Agente (Realism Audit)...\n";

$registry = new ProjectRegistry();
$tenantId = 'default';

// 1. Arquitecto recibe el requerimiento
$registry->logAgentEvent(
    'agnt_07f76a58', 
    $tenantId, 
    'TASK_RECEIVED', 
    'Usuario solicita compra de Laptop Gamer para diseño 3D.', 
    'INFO'
);
sleep(1);

// 2. Arquitecto delega a Ventas para buscar stock
$registry->logAgentEvent(
    'agnt_07f76a58', 
    $tenantId, 
    'HANDOVER', 
    'Delegando a AGENTE DE VENTAS para validación de catálogo.', 
    'SUCCESS'
);
sleep(1);

// 3. Agente de Ventas confirma stock
$registry->logAgentEvent(
    'agnt_9b86947e', 
    $tenantId, 
    'STOCK_CHECK', 
    'Confirmado: Razer Blade 15 disponible en bodega central.', 
    'SUCCESS'
);
sleep(1);

// 4. Agente de Ventas delega a Finanzas para aprobación
$registry->logAgentEvent(
    'agnt_9b86947e', 
    $tenantId, 
    'HANDOVER', 
    'Delegando a AGENTE DE FINANZAS para validación de presupuesto ($2,500 USD).', 
    'INFO'
);
sleep(1);

// 5. Agente de Finanzas valida presupuesto y reglas fiscales
$registry->logAgentEvent(
    'agnt_44e20753', 
    $tenantId, 
    'BUDGET_APPROVED', 
    'Presupuesto aprobado. Se aplica IVA del 19% segun estatuto tributario.', 
    'SUCCESS'
);
sleep(1);

// 6. Fin de la cadena - Notificación al usuario
$registry->logAgentEvent(
    'agnt_07f76a58', 
    $tenantId, 
    'TASK_COMPLETED', 
    'Flujo finalizado. Laptop Gamer lista para facturación.', 
    'SUCCESS'
);

echo "✅ Simulación completada. Revisa la Torre de Control (Misión Control) para ver los resultados reales.\n";
