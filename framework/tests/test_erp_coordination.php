<?php
// framework/tests/test_erp_coordination.php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

// Enable runtime schemas and context for test environment
putenv('ALLOW_RUNTIME_SCHEMA=1');
putenv('APP_ENV=testing');
putenv('SUKI_ENV=testing');
putenv('PROJECT_ID=test_erp_project');
putenv('DB_NAMESPACE_BY_PROJECT=1');

use App\Core\ChatAgent;
use App\Core\ProjectRegistry;
use App\Core\InternalEventBus;
use App\Core\AgentCoordinationService;
use App\Core\InventoryRepository;
use App\Core\AccountingRepository;

echo "--- STARTING ERP COORDINATION E2E TEST ---\n";

// 1. Setup Context
$tenantId = 'test_tenant_' . bin2hex(random_bytes(4));
$userId = 'test_user';
$projectId = 'test_project';

echo "Context: Tenant=$tenantId, Project=$projectId\n";

// 2. Initialize System
AgentCoordinationService::boot();
$agent = new ChatAgent();

// 3. Prepare Test Data: Create a product with stock
$invRepo = new InventoryRepository();
$createdId = $invRepo->createProduct([
    'tenant_id' => $tenantId,
    'sku' => 'SKU-TEST-001',
    'nombre' => 'Producto de Prueba ERP',
    'precio_venta' => 100.0,
    'stock_actual' => 50.0,
    'stock_minimo' => 5.0
]);

echo "Product created: SKU-TEST-001 (ID: $createdId) with stock 50.0\n";

// 4. Execute Sale via Chat (Simulation of Finalize Sale)
echo "Simulating POS sale finalization...\n";

$saleData = [
    'id' => 'sale_test_' . time(),
    'sale_number' => 'POS-TEST-001',
    'total' => 150.0,
    'lines' => [
        ['product_id' => $createdId, 'qty' => 5.0, 'price' => 30.0]
    ]
];

// Directly dispatch the event to simulate POSCommandHandler behavior
InternalEventBus::getInstance()->dispatch('sale.finalized', [
    'tenant_id' => $tenantId,
    'app_id' => $projectId,
    'sale' => $saleData,
    'user_id' => $userId
]);

echo "Event 'sale.finalized' dispatched.\n";

// 5. Verification Phase
echo "\n--- VERIFICATION ---\n";

// A. Check Inventory
$product = $invRepo->findProduct($tenantId, 'SKU-TEST-001');
$stock = (float) ($product['stock_actual'] ?? 0);
echo "New Stock: $stock (Expected: 45.0)\n";
if ($stock == 45.0) {
    echo "✅ Inventory Adjustment: PASS\n";
} else {
    echo "❌ Inventory Adjustment: FAIL\n";
}

// B. Check Accounting & Traces
$registry = new ProjectRegistry();
$events = $registry->getAgentEvents($tenantId, 20);
$foundAccounting = false;
$foundInventory = false;

echo "Agent Events Log:\n";
foreach ($events as $event) {
    echo "  [{$event['status']}] [{$event['agent_id']}] {$event['event_type']}: {$event['details']}\n";
    if ($event['agent_id'] === 'Agent_Finanzas' && $event['event_type'] === 'accounting_recorded') {
        $foundAccounting = true;
    }
    if ($event['agent_id'] === 'Agent_Inventario' && $event['event_type'] === 'stock_adjusted') {
        $foundInventory = true;
    }
}

if ($foundAccounting) {
    echo "✅ Accounting Entry Trace: PASS\n";
} else {
    echo "❌ Accounting Entry Trace: FAIL\n";
}

if ($foundInventory) {
    echo "✅ Inventory Trace: PASS\n";
} else {
    echo "❌ Inventory Trace: FAIL\n";
}

echo "\n--- TEST COMPLETE ---\n";
