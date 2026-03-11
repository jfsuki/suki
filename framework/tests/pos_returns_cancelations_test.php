<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\AuditLogger;
use App\Core\ContractRegistry;
use App\Core\Database;
use App\Core\EntityRegistry;
use App\Core\EntitySearchEventLogger;
use App\Core\EntitySearchRepository;
use App\Core\EntitySearchService;
use App\Core\POSEventLogger;
use App\Core\POSRepository;
use App\Core\POSService;
use App\Core\SkillRegistry;
use App\Core\SkillResolver;

$failures = [];
$tmpDir = FRAMEWORK_ROOT . '/tests/tmp/pos_returns_cancelations_' . time() . '_' . random_int(1000, 9999);
$tmpProjectRoot = $tmpDir . '/project_root';
@mkdir($tmpProjectRoot . '/contracts/entities', 0777, true);
@mkdir($tmpProjectRoot . '/storage/cache', 0777, true);
@mkdir($tmpProjectRoot . '/storage/meta', 0777, true);

$previous = [
    'APP_ENV' => getenv('APP_ENV'),
    'ALLOW_RUNTIME_SCHEMA' => getenv('ALLOW_RUNTIME_SCHEMA'),
    'PROJECT_REGISTRY_DB_PATH' => getenv('PROJECT_REGISTRY_DB_PATH'),
    'DB_DRIVER' => getenv('DB_DRIVER'),
    'DB_PATH' => getenv('DB_PATH'),
    'DB_NAMESPACE_BY_PROJECT' => getenv('DB_NAMESPACE_BY_PROJECT'),
    'PROJECT_STORAGE_MODEL' => getenv('PROJECT_STORAGE_MODEL'),
    'DB_STORAGE_MODEL' => getenv('DB_STORAGE_MODEL'),
];

putenv('APP_ENV=local');
putenv('ALLOW_RUNTIME_SCHEMA=1');
putenv('PROJECT_REGISTRY_DB_PATH=' . $tmpDir . '/project_registry.sqlite');
putenv('DB_DRIVER=sqlite');
putenv('DB_PATH=' . $tmpDir . '/pos_returns_cancelations.sqlite');
putenv('DB_NAMESPACE_BY_PROJECT=0');
putenv('PROJECT_STORAGE_MODEL=legacy');
putenv('DB_STORAGE_MODEL=legacy');

$pdo = new PDO('sqlite:' . $tmpDir . '/pos_returns_cancelations.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
Database::setConnection($pdo);

writeEntityContract($tmpProjectRoot, 'products', 'productos', 'products', [
    field('id', 'int', ['primary' => true, 'source' => 'system']),
    field('tenant_id', 'int'),
    field('sku', 'string'),
    field('barcode', 'string'),
    field('nombre', 'string'),
    field('precio_venta', 'decimal'),
    field('iva_rate', 'decimal'),
    field('activo', 'bool'),
]);
writeEntityContract($tmpProjectRoot, 'customers', 'clientes', 'customers', [
    field('id', 'int', ['primary' => true, 'source' => 'system']),
    field('tenant_id', 'int'),
    field('documento', 'string'),
    field('nombre', 'string'),
    field('activo', 'bool'),
]);

seedSearchTables($pdo);

$tenantAlpha = 'tenant_alpha';
$tenantBeta = 'tenant_beta';
$tenantAlphaInt = stableTenantInt($tenantAlpha);
$tenantBetaInt = stableTenantInt($tenantBeta);
$now = new DateTimeImmutable('2026-03-10 13:00:00');

$productStmt = $pdo->prepare(
    'INSERT INTO products (id, tenant_id, sku, barcode, nombre, precio_venta, iva_rate, activo, created_at, updated_at)
     VALUES (:id, :tenant_id, :sku, :barcode, :nombre, :precio_venta, :iva_rate, :activo, :created_at, :updated_at)'
);
foreach ([
    ['id' => 1, 'tenant_id' => $tenantAlphaInt, 'sku' => 'SKU-COCA-350', 'barcode' => '7701111111111', 'nombre' => 'Coca Cola 350', 'precio_venta' => 3500, 'iva_rate' => 19, 'activo' => 1, 'created_at' => $now->format('Y-m-d H:i:s')],
    ['id' => 2, 'tenant_id' => $tenantAlphaInt, 'sku' => 'SKU-ARROZ-GR', 'barcode' => '7701111111113', 'nombre' => 'Arroz Grande Premium', 'precio_venta' => 4200, 'iva_rate' => 5, 'activo' => 1, 'created_at' => $now->format('Y-m-d H:i:s')],
    ['id' => 3, 'tenant_id' => $tenantBetaInt, 'sku' => 'SKU-BETA-1', 'barcode' => '7709999999991', 'nombre' => 'Producto Beta', 'precio_venta' => 9999, 'iva_rate' => 0, 'activo' => 1, 'created_at' => $now->format('Y-m-d H:i:s')],
] as $row) {
    $productStmt->execute($row + ['updated_at' => $row['created_at']]);
}

$registry = new EntityRegistry(null, $tmpProjectRoot);
$entitySearch = new EntitySearchService(
    new EntitySearchRepository($pdo, $registry),
    new AuditLogger($pdo),
    new EntitySearchEventLogger($tmpProjectRoot)
);
$service = new POSService(
    new POSRepository($pdo),
    $entitySearch,
    $registry,
    new AuditLogger($pdo),
    new POSEventLogger($tmpProjectRoot)
);

try {
    $cancelSale = createSale($service, $tenantAlpha, 'pos_app', '7701111111111', 1, 'cashier_cancel');
    $cancelSaleId = (string) ($cancelSale['id'] ?? '');
    $cancelSaleNumber = (string) ($cancelSale['sale_number'] ?? '');
    $cancelResult = $service->cancelSale([
        'tenant_id' => $tenantAlpha,
        'app_id' => 'pos_app',
        'sale_id' => $cancelSaleId,
        'reason' => 'cliente se retracta',
        'requested_by_user_id' => 'cashier_cancel',
    ]);
    $canceledSale = is_array($cancelResult['sale'] ?? null) ? (array) $cancelResult['sale'] : [];
    $cancelReceipt = is_array($cancelResult['receipt'] ?? null) ? (array) $cancelResult['receipt'] : [];

    if ((string) ($canceledSale['status'] ?? '') !== 'canceled' || (string) ($cancelReceipt['kind'] ?? '') !== 'cancelation' || trim((string) ($cancelReceipt['printable_text'] ?? '')) === '') {
        $failures[] = 'Debe cancelar una venta POS valida y preparar payload de cancelacion.';
    }

    try {
        $service->cancelSale([
            'tenant_id' => $tenantAlpha,
            'app_id' => 'pos_app',
            'sale_id' => $cancelSaleId,
        ]);
        $failures[] = 'Debe bloquear la doble cancelacion de una venta POS.';
    } catch (Throwable $e) {
        if ((string) $e->getMessage() !== 'POS_SALE_ALREADY_CANCELED') {
            $failures[] = 'La doble cancelacion devolvio un error inesperado: ' . $e->getMessage();
        }
    }

    $fullReturnSale = createSale($service, $tenantAlpha, 'pos_app', '7701111111111', 2, 'cashier_return_full');
    $fullReturnSaleId = (string) ($fullReturnSale['id'] ?? '');
    $fullReturnResult = $service->createReturnFromSale([
        'tenant_id' => $tenantAlpha,
        'app_id' => 'pos_app',
        'sale_id' => $fullReturnSaleId,
        'reason' => 'producto defectuoso',
        'requested_by_user_id' => 'cashier_return_full',
    ]);
    $fullReturn = is_array($fullReturnResult['return'] ?? null) ? (array) $fullReturnResult['return'] : [];
    $fullReturnId = (string) ($fullReturn['id'] ?? '');
    $fullReturnNumber = (string) ($fullReturn['return_number'] ?? '');
    $fullReturnLines = is_array($fullReturn['lines'] ?? null) ? (array) $fullReturn['lines'] : [];

    if ($fullReturnId === '' || $fullReturnNumber === '' || count($fullReturnLines) !== 1 || (float) ($fullReturn['total'] ?? 0) !== 8330.0) {
        $failures[] = 'Debe crear devolucion POS completa con numeracion y snapshot de lineas.';
    }

    $loadedReturn = $service->getReturn($tenantAlpha, $fullReturnId, 'pos_app');
    $listedReturns = $service->listReturns($tenantAlpha, ['sale_id' => $fullReturnSaleId, 'limit' => 10], 'pos_app');
    $returnReceipt = $service->buildReturnReceiptPayload($tenantAlpha, $fullReturnId, 'pos_app');
    if ((string) ($loadedReturn['id'] ?? '') !== $fullReturnId || count($listedReturns) < 1 || (string) ($returnReceipt['kind'] ?? '') !== 'return' || trim((string) ($returnReceipt['printable_text'] ?? '')) === '') {
        $failures[] = 'Debe consultar/listar devoluciones POS y preparar su ticket.';
    }

    try {
        $service->createReturnFromSale([
            'tenant_id' => $tenantAlpha,
            'app_id' => 'pos_app',
            'sale_id' => $fullReturnSaleId,
        ]);
        $failures[] = 'Debe bloquear una nueva devolucion cuando ya no queda cantidad disponible.';
    } catch (Throwable $e) {
        if ((string) $e->getMessage() !== 'POS_RETURN_NO_REMAINING_QTY') {
            $failures[] = 'La devolucion extra devolvio un error inesperado: ' . $e->getMessage();
        }
    }

    $partialSale = createSale($service, $tenantAlpha, 'pos_app', '7701111111111', 3, 'cashier_return_partial');
    $partialSaleId = (string) ($partialSale['id'] ?? '');
    $partialSaleLines = is_array($partialSale['lines'] ?? null) ? (array) $partialSale['lines'] : [];
    $partialSaleLine = is_array($partialSaleLines[0] ?? null) ? (array) $partialSaleLines[0] : [];
    $partialSaleLineId = (string) ($partialSaleLine['id'] ?? '');

    $partialReturnOne = $service->createReturnFromSale([
        'tenant_id' => $tenantAlpha,
        'app_id' => 'pos_app',
        'sale_id' => $partialSaleId,
        'sale_line_id' => $partialSaleLineId,
        'qty' => 1,
        'reason' => 'cliente cambia producto',
        'requested_by_user_id' => 'cashier_return_partial',
    ]);
    $partialReturnOneData = is_array($partialReturnOne['return'] ?? null) ? (array) $partialReturnOne['return'] : [];
    $partialReturnOneId = (string) ($partialReturnOneData['id'] ?? '');

    $partialReturnTwo = $service->createReturnFromSale([
        'tenant_id' => $tenantAlpha,
        'app_id' => 'pos_app',
        'sale_id' => $partialSaleId,
        'sale_line_id' => $partialSaleLineId,
        'qty' => 1,
        'reason' => 'cliente insiste',
        'requested_by_user_id' => 'cashier_return_partial',
    ]);
    $partialReturnTwoData = is_array($partialReturnTwo['return'] ?? null) ? (array) $partialReturnTwo['return'] : [];

    if ((float) ($partialReturnOneData['total'] ?? 0) !== 4165.0 || (string) ($partialReturnOneData['return_number'] ?? '') === '' || (string) ($partialReturnTwoData['return_number'] ?? '') === '' || (string) ($partialReturnOneData['return_number'] ?? '') === (string) ($partialReturnTwoData['return_number'] ?? '')) {
        $failures[] = 'Debe permitir devoluciones parciales POS con total proporcional y numeracion unica.';
    }

    try {
        $service->createReturnFromSale([
            'tenant_id' => $tenantAlpha,
            'app_id' => 'pos_app',
            'sale_id' => $partialSaleId,
            'sale_line_id' => $partialSaleLineId,
            'qty' => 2,
            'reason' => 'exceso',
        ]);
        $failures[] = 'Debe bloquear qty devuelta superior al remanente vendido.';
    } catch (Throwable $e) {
        if ((string) $e->getMessage() !== 'POS_RETURN_QTY_EXCEEDED') {
            $failures[] = 'La validacion de qty devuelta excedida devolvio un error inesperado: ' . $e->getMessage();
        }
    }

    try {
        $service->cancelSale([
            'tenant_id' => $tenantAlpha,
            'app_id' => 'pos_app',
            'sale_id' => $partialSaleId,
        ]);
        $failures[] = 'Debe bloquear la cancelacion de una venta POS que ya tiene devoluciones.';
    } catch (Throwable $e) {
        if ((string) $e->getMessage() !== 'POS_SALE_HAS_RETURNS') {
            $failures[] = 'La cancelacion con devoluciones devolvio un error inesperado: ' . $e->getMessage();
        }
    }

    try {
        $service->getReturn($tenantBeta, $partialReturnOneId, 'pos_app');
        $failures[] = 'No debe permitir leer devoluciones POS de otro tenant.';
    } catch (Throwable $e) {
        if ((string) $e->getMessage() !== 'POS_RETURN_NOT_FOUND') {
            $failures[] = 'Tenant isolation de devoluciones POS devolvio un error inesperado: ' . $e->getMessage();
        }
    }

    try {
        $service->cancelSale([
            'tenant_id' => $tenantBeta,
            'app_id' => 'pos_app',
            'sale_id' => $partialSaleId,
        ]);
        $failures[] = 'No debe permitir cancelar ventas POS de otro tenant.';
    } catch (Throwable $e) {
        if ((string) $e->getMessage() !== 'POS_SALE_NOT_FOUND') {
            $failures[] = 'Tenant isolation en cancelacion POS devolvio un error inesperado: ' . $e->getMessage();
        }
    }

    $catalog = (new ContractRegistry())->getSkillsCatalog();
    $resolver = new SkillResolver();
    $skillRegistry = new SkillRegistry($catalog);
    $cancelSkill = $resolver->resolve('cancelar venta pos sale_number=' . $cancelSaleNumber, $skillRegistry, []);
    $returnSkill = $resolver->resolve('crear devolucion pos sale_id=' . $partialSaleId . ' sale_line_id=' . $partialSaleLineId . ' qty=1', $skillRegistry, []);
    $receiptSkill = $resolver->resolve('ticket devolucion pos return_id=' . $partialReturnOneId, $skillRegistry, []);
    if ((string) (($cancelSkill['selected']['name'] ?? '') ?: '') !== 'pos_cancel_sale') {
        $failures[] = 'SkillResolver debe detectar pos_cancel_sale.';
    }
    if ((string) (($returnSkill['selected']['name'] ?? '') ?: '') !== 'pos_create_return') {
        $failures[] = 'SkillResolver debe detectar pos_create_return.';
    }
    if ((string) (($receiptSkill['selected']['name'] ?? '') ?: '') !== 'pos_build_return_receipt') {
        $failures[] = 'SkillResolver debe detectar pos_build_return_receipt.';
    }

    $sharedEnv = [
        'APP_ENV' => 'local',
        'ALLOW_RUNTIME_SCHEMA' => '1',
        'PROJECT_REGISTRY_DB_PATH' => $tmpDir . '/project_registry.sqlite',
        'DB_DRIVER' => 'sqlite',
        'DB_PATH' => $tmpDir . '/pos_returns_cancelations.sqlite',
        'DB_NAMESPACE_BY_PROJECT' => '0',
        'PROJECT_STORAGE_MODEL' => 'legacy',
        'DB_STORAGE_MODEL' => 'legacy',
    ];
    $sharedEnv['SUKI_RUNTIME_ENV_OVERRIDES_JSON'] = json_encode($sharedEnv, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $chatCancelSale = createSale($service, $tenantAlpha, 'pos_app', '7701111111111', 1, 'chat_cancel');
    $chatCancel = runChatTurn([
        'project_root' => $tmpProjectRoot,
        'env' => $sharedEnv,
        'payload' => [
            'tenant_id' => $tenantAlpha,
            'project_id' => 'pos_app',
            'session_id' => 'pos_returns_' . time() . '_cancel',
            'user_id' => 'operator_pos',
            'role' => 'admin',
            'is_authenticated' => true,
            'mode' => 'app',
            'channel' => 'test',
            'message' => 'cancelar venta pos sale_number=' . (string) ($chatCancelSale['sale_number'] ?? '') . ' reason=error_operador',
        ],
    ]);
    $chatCancelData = is_array($chatCancel['data'] ?? null) ? (array) $chatCancel['data'] : [];
    if ((string) ($chatCancel['status'] ?? '') !== 'success' || (string) ($chatCancelData['pos_action'] ?? '') !== 'cancel_sale' || (string) (($chatCancelData['sale']['status'] ?? '') ?: '') !== 'canceled') {
        $failures[] = 'ChatAgent debe cancelar ventas POS por skill + CommandBus.';
    }

    $chatReturnSale = createSale($service, $tenantAlpha, 'pos_app', '7701111111111', 2, 'chat_return');
    $chatReturnSaleLines = is_array($chatReturnSale['lines'] ?? null) ? (array) $chatReturnSale['lines'] : [];
    $chatReturnLineId = (string) (($chatReturnSaleLines[0]['id'] ?? '') ?: '');
    $chatReturn = runChatTurn([
        'project_root' => $tmpProjectRoot,
        'env' => $sharedEnv,
        'payload' => [
            'tenant_id' => $tenantAlpha,
            'project_id' => 'pos_app',
            'session_id' => 'pos_returns_' . time() . '_return',
            'user_id' => 'operator_pos',
            'role' => 'admin',
            'is_authenticated' => true,
            'mode' => 'app',
            'channel' => 'test',
            'message' => 'crear devolucion pos sale_number=' . (string) ($chatReturnSale['sale_number'] ?? '') . ' sale_line_id=' . $chatReturnLineId . ' qty=1 reason=defecto',
        ],
    ]);
    $chatReturnData = is_array($chatReturn['data'] ?? null) ? (array) $chatReturn['data'] : [];
    $chatReturnRecord = is_array($chatReturnData['return'] ?? null) ? (array) $chatReturnData['return'] : [];
    $chatReturnId = (string) ($chatReturnRecord['id'] ?? '');
    if ((string) ($chatReturn['status'] ?? '') !== 'success' || (string) ($chatReturnData['pos_action'] ?? '') !== 'create_return' || $chatReturnId === '') {
        $failures[] = 'ChatAgent debe crear devoluciones POS por skill.';
    }

    $chatReturnReceipt = runChatTurn([
        'project_root' => $tmpProjectRoot,
        'env' => $sharedEnv,
        'payload' => [
            'tenant_id' => $tenantAlpha,
            'project_id' => 'pos_app',
            'session_id' => 'pos_returns_' . time() . '_receipt',
            'user_id' => 'operator_pos',
            'role' => 'admin',
            'is_authenticated' => true,
            'mode' => 'app',
            'channel' => 'test',
            'message' => 'ticket devolucion pos return_id=' . $chatReturnId,
        ],
    ]);
    $chatReturnReceiptData = is_array($chatReturnReceipt['data'] ?? null) ? (array) $chatReturnReceipt['data'] : [];
    $chatReturnReceiptPayload = is_array($chatReturnReceiptData['receipt'] ?? null) ? (array) $chatReturnReceiptData['receipt'] : [];
    if ((string) ($chatReturnReceipt['status'] ?? '') !== 'success' || (string) ($chatReturnReceiptData['pos_action'] ?? '') !== 'build_return_receipt' || trim((string) ($chatReturnReceiptPayload['printable_text'] ?? '')) === '') {
        $failures[] = 'ChatAgent debe preparar tickets de devolucion POS por skill.';
    }

    $session = [
        'auth_user' => [
            'id' => 'api_pos_user',
            'tenant_id' => $tenantAlpha,
            'project_id' => 'pos_app',
            'role' => 'admin',
            'label' => 'API POS',
        ],
    ];

    $apiCancelSale = createSale($service, $tenantAlpha, 'pos_app', '7701111111111', 1, 'api_cancel');
    $apiCancel = runApiRoute([
        'route' => 'pos/cancel-sale',
        'method' => 'POST',
        'payload' => [
            'sale_id' => (string) ($apiCancelSale['id'] ?? ''),
            'project_id' => 'pos_app',
            'reason' => 'api_cancel',
        ],
        'session' => $session,
        'env' => $sharedEnv,
    ]);
    $apiCancelJson = $apiCancel['json'];
    if (!is_array($apiCancelJson) || (string) ($apiCancelJson['status'] ?? '') !== 'success' || (string) (($apiCancelJson['data']['sale']['status'] ?? '') ?: '') !== 'canceled') {
        $failures[] = 'API pos/cancel-sale debe cancelar ventas POS.';
    }

    $apiReturnSale = createSale($service, $tenantAlpha, 'pos_app', '7701111111111', 2, 'api_return');
    $apiReturnSaleLines = is_array($apiReturnSale['lines'] ?? null) ? (array) $apiReturnSale['lines'] : [];
    $apiReturnLineId = (string) (($apiReturnSaleLines[0]['id'] ?? '') ?: '');
    $apiReturn = runApiRoute([
        'route' => 'pos/create-return',
        'method' => 'POST',
        'payload' => [
            'sale_id' => (string) ($apiReturnSale['id'] ?? ''),
            'sale_line_id' => $apiReturnLineId,
            'qty' => 1,
            'project_id' => 'pos_app',
            'reason' => 'api_return',
        ],
        'session' => $session,
        'env' => $sharedEnv,
    ]);
    $apiReturnJson = $apiReturn['json'];
    $apiReturnId = (string) (($apiReturnJson['data']['return']['id'] ?? '') ?: '');
    if (!is_array($apiReturnJson) || (string) ($apiReturnJson['status'] ?? '') !== 'success' || $apiReturnId === '') {
        $failures[] = 'API pos/create-return debe crear devoluciones POS.';
    }

    $apiGetReturn = runApiRoute([
        'route' => 'pos/get-return',
        'method' => 'GET',
        'query' => [
            'return_id' => $apiReturnId,
            'project_id' => 'pos_app',
        ],
        'session' => $session,
        'env' => $sharedEnv,
    ]);
    $apiGetReturnJson = $apiGetReturn['json'];
    if (!is_array($apiGetReturnJson) || (string) ($apiGetReturnJson['status'] ?? '') !== 'success' || (string) (($apiGetReturnJson['data']['return']['id'] ?? '') ?: '') !== $apiReturnId) {
        $failures[] = 'API pos/get-return debe cargar devoluciones POS.';
    }

    $apiReturnReceipt = runApiRoute([
        'route' => 'pos/build-return-receipt',
        'method' => 'GET',
        'query' => [
            'return_id' => $apiReturnId,
            'project_id' => 'pos_app',
        ],
        'session' => $session,
        'env' => $sharedEnv,
    ]);
    $apiReturnReceiptJson = $apiReturnReceipt['json'];
    if (!is_array($apiReturnReceiptJson) || (string) ($apiReturnReceiptJson['status'] ?? '') !== 'success' || trim((string) (($apiReturnReceiptJson['data']['receipt']['printable_text'] ?? '') ?: '')) === '') {
        $failures[] = 'API pos/build-return-receipt debe devolver ticket de devolucion POS imprimible.';
    }
} catch (Throwable $e) {
    $failures[] = 'El flujo POS de cancelaciones/devoluciones debe funcionar extremo a extremo: ' . $e->getMessage();
}

foreach ($previous as $key => $value) {
    restoreEnvValue($key, $value);
}

$result = ['ok' => $failures === [], 'failures' => $failures];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($result['ok'] ? 0 : 1);

function createSale(POSService $service, string $tenantId, string $appId, string $barcode, int $qty, string $userId): array
{
    $draft = $service->createDraft([
        'tenant_id' => $tenantId,
        'app_id' => $appId,
        'currency' => 'COP',
    ]);
    $draft = $service->addLineByProductReference([
        'tenant_id' => $tenantId,
        'app_id' => $appId,
        'draft_id' => (string) ($draft['id'] ?? ''),
        'barcode' => $barcode,
        'qty' => $qty,
    ]);
    $result = $service->finalizeDraftSale([
        'tenant_id' => $tenantId,
        'app_id' => $appId,
        'draft_id' => (string) ($draft['id'] ?? ''),
        'requested_by_user_id' => $userId,
    ]);

    return is_array($result['sale'] ?? null) ? (array) $result['sale'] : [];
}

function field(string $name, string $type, array $extra = []): array
{
    return array_merge([
        'name' => $name,
        'type' => $type,
        'required' => false,
        'source' => 'form',
    ], $extra);
}

function writeEntityContract(string $projectRoot, string $name, string $label, string $tableName, array $fields): void
{
    $payload = [
        'type' => 'entity',
        'name' => $name,
        'label' => ucfirst($label),
        'version' => '1.0',
        'table' => [
            'name' => $tableName,
            'primaryKey' => 'id',
            'timestamps' => true,
            'softDelete' => false,
            'tenantScoped' => true,
        ],
        'fields' => $fields,
        'grids' => [],
        'relations' => [],
        'rules' => [],
        'permissions' => [
            'read' => ['admin', 'seller'],
            'create' => ['admin'],
            'update' => ['admin'],
            'delete' => ['admin'],
        ],
    ];

    $path = $projectRoot . '/contracts/entities/' . $name . '.entity.json';
    file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function seedSearchTables(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE products (
        id INTEGER PRIMARY KEY,
        tenant_id INTEGER NOT NULL,
        sku TEXT,
        barcode TEXT,
        nombre TEXT,
        precio_venta REAL,
        iva_rate REAL,
        activo INTEGER,
        created_at TEXT,
        updated_at TEXT
    )');
    $pdo->exec('CREATE INDEX idx_products_tenant_sku ON products (tenant_id, sku)');
    $pdo->exec('CREATE INDEX idx_products_tenant_nombre ON products (tenant_id, nombre)');

    $pdo->exec('CREATE TABLE customers (
        id INTEGER PRIMARY KEY,
        tenant_id INTEGER NOT NULL,
        documento TEXT,
        nombre TEXT,
        activo INTEGER,
        created_at TEXT,
        updated_at TEXT
    )');
    $pdo->exec('CREATE INDEX idx_customers_tenant_doc ON customers (tenant_id, documento)');
    $pdo->exec('CREATE INDEX idx_customers_tenant_nombre ON customers (tenant_id, nombre)');
}

function stableTenantInt(string $tenantId): int
{
    $hash = crc32((string) $tenantId);
    $unsigned = (int) sprintf('%u', $hash);
    $max = 2147483647;
    $value = $unsigned % $max;
    return $value > 0 ? $value : 1;
}

function runChatTurn(array $request): array
{
    $helper = __DIR__ . '/entity_search_chat_turn.php';
    $encoded = base64_encode((string) json_encode($request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($helper) . ' ' . escapeshellarg($encoded);
    $raw = (string) shell_exec($cmd);
    $json = json_decode($raw, true);
    if (!is_array($json)) {
        throw new RuntimeException('Respuesta invalida del helper de chat: ' . $raw);
    }

    return $json;
}

/**
 * @return array{raw:string,json:array<string,mixed>|null}
 */
function runApiRoute(array $request): array
{
    $helper = __DIR__ . '/api_route_turn.php';
    $encoded = base64_encode((string) json_encode($request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($helper) . ' ' . escapeshellarg($encoded);
    $raw = (string) shell_exec($cmd);
    $json = json_decode($raw, true);

    return [
        'raw' => $raw,
        'json' => is_array($json) ? $json : null,
    ];
}

function restoreEnvValue(string $key, $value): void
{
    if ($value === false || $value === null) {
        putenv($key);
        unset($_ENV[$key], $_SERVER[$key]);
        return;
    }

    putenv($key . '=' . $value);
    $_ENV[$key] = (string) $value;
    $_SERVER[$key] = (string) $value;
}
