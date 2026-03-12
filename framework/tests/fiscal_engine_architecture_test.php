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
use App\Core\FiscalEngineEventLogger;
use App\Core\FiscalEngineRepository;
use App\Core\FiscalEngineService;
use App\Core\POSEventLogger;
use App\Core\POSRepository;
use App\Core\POSService;
use App\Core\PurchasesEventLogger;
use App\Core\PurchasesRepository;
use App\Core\PurchasesService;
use App\Core\SkillRegistry;
use App\Core\SkillResolver;

$failures = [];
$tmpDir = FRAMEWORK_ROOT . '/tests/tmp/fiscal_engine_architecture_' . time() . '_' . random_int(1000, 9999);
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
putenv('DB_PATH=' . $tmpDir . '/fiscal_engine.sqlite');
putenv('DB_NAMESPACE_BY_PROJECT=0');
putenv('PROJECT_STORAGE_MODEL=legacy');
putenv('DB_STORAGE_MODEL=legacy');

$pdo = new PDO('sqlite:' . $tmpDir . '/fiscal_engine.sqlite');
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
writeEntityContract($tmpProjectRoot, 'suppliers', 'proveedores', 'suppliers', [
    field('id', 'int', ['primary' => true, 'source' => 'system']),
    field('tenant_id', 'int'),
    field('nit', 'string'),
    field('nombre', 'string'),
    field('activo', 'bool'),
]);
seedSearchTables($pdo);

$tenantAlpha = 'tenant_alpha';
$tenantBeta = 'tenant_beta';
$tenantAlphaInt = stableTenantInt($tenantAlpha);
$tenantBetaInt = stableTenantInt($tenantBeta);
$now = new DateTimeImmutable('2026-03-11 14:30:00');

$productStmt = $pdo->prepare(
    'INSERT INTO products (id, tenant_id, sku, barcode, nombre, precio_venta, iva_rate, activo, created_at, updated_at)
     VALUES (:id, :tenant_id, :sku, :barcode, :nombre, :precio_venta, :iva_rate, :activo, :created_at, :updated_at)'
);
foreach ([
    ['id' => 1, 'tenant_id' => $tenantAlphaInt, 'sku' => 'SKU-FISCAL-ARROZ', 'barcode' => '7701234500011', 'nombre' => 'Arroz Premium Alpha', 'precio_venta' => 3500, 'iva_rate' => 19, 'activo' => 1],
    ['id' => 2, 'tenant_id' => $tenantAlphaInt, 'sku' => 'SKU-FISCAL-ACEITE', 'barcode' => '7701234500012', 'nombre' => 'Aceite Hogar Uno', 'precio_venta' => 12000, 'iva_rate' => 5, 'activo' => 1],
    ['id' => 3, 'tenant_id' => $tenantBetaInt, 'sku' => 'SKU-BETA-1', 'barcode' => '7709990000001', 'nombre' => 'Producto Beta', 'precio_venta' => 9000, 'iva_rate' => 0, 'activo' => 1],
] as $row) {
    $productStmt->execute($row + ['created_at' => $now->format('Y-m-d H:i:s'), 'updated_at' => $now->format('Y-m-d H:i:s')]);
}

$customerStmt = $pdo->prepare(
    'INSERT INTO customers (id, tenant_id, documento, nombre, activo, created_at, updated_at)
     VALUES (:id, :tenant_id, :documento, :nombre, :activo, :created_at, :updated_at)'
);
foreach ([
    ['id' => 11, 'tenant_id' => $tenantAlphaInt, 'documento' => 'CC-101', 'nombre' => 'Ana Uno', 'activo' => 1],
    ['id' => 21, 'tenant_id' => $tenantBetaInt, 'documento' => 'CC-BETA', 'nombre' => 'Cliente Beta', 'activo' => 1],
] as $row) {
    $customerStmt->execute($row + ['created_at' => $now->format('Y-m-d H:i:s'), 'updated_at' => $now->format('Y-m-d H:i:s')]);
}

$supplierStmt = $pdo->prepare(
    'INSERT INTO suppliers (id, tenant_id, nit, nombre, activo, created_at, updated_at)
     VALUES (:id, :tenant_id, :nit, :nombre, :activo, :created_at, :updated_at)'
);
foreach ([
    ['id' => 10, 'tenant_id' => $tenantAlphaInt, 'nit' => '900111222', 'nombre' => 'Distribuidora Norte', 'activo' => 1],
    ['id' => 20, 'tenant_id' => $tenantBetaInt, 'nit' => '800999000', 'nombre' => 'Proveedor Beta', 'activo' => 1],
] as $row) {
    $supplierStmt->execute($row + ['created_at' => $now->format('Y-m-d H:i:s'), 'updated_at' => $now->format('Y-m-d H:i:s')]);
}

$auditLogger = new AuditLogger($pdo);
$registry = new EntityRegistry(null, $tmpProjectRoot);
$entitySearch = new EntitySearchService(
    new EntitySearchRepository($pdo, $registry),
    $auditLogger,
    new EntitySearchEventLogger($tmpProjectRoot)
);
$posService = new POSService(
    new POSRepository($pdo),
    $entitySearch,
    $registry,
    $auditLogger,
    new POSEventLogger($tmpProjectRoot)
);
$purchasesService = new PurchasesService(
    new PurchasesRepository($pdo),
    $entitySearch,
    $auditLogger,
    new PurchasesEventLogger($tmpProjectRoot)
);
$service = new FiscalEngineService(
    new FiscalEngineRepository($pdo),
    $posService,
    $purchasesService,
    $entitySearch,
    $auditLogger,
    new FiscalEngineEventLogger($tmpProjectRoot)
);

$posSaleId = '';
$purchaseId = '';
$posDocumentId = '';
$purchaseDocumentId = '';

try {
    $posSale = createPosSale($posService, $tenantAlpha, 'pos_app', '7701234500011', 2, 'ana uno', 'cashier_alpha');
    $posSaleId = (string) ($posSale['id'] ?? '');
    if ($posSaleId === '') {
        $failures[] = 'Debe crear una venta POS origen para el documento fiscal.';
    }

    $purchase = createPurchase($purchasesService, $tenantAlpha, 'purchase_app', 'premium alpha', 3, 2800, 5, 'distribuidora norte', 'buyer_alpha');
    $purchaseId = (string) ($purchase['id'] ?? '');
    if ($purchaseId === '') {
        $failures[] = 'Debe crear una compra origen para el documento fiscal.';
    }

    $posDocument = $service->createDocumentFromSource([
        'tenant_id' => $tenantAlpha,
        'app_id' => 'pos_app',
        'source_module' => 'pos',
        'source_entity_type' => 'sale',
        'source_entity_id' => $posSaleId,
        'document_type' => 'sales_invoice',
    ]);
    $posDocumentId = (string) ($posDocument['id'] ?? '');
    if ($posDocumentId === '' || (string) ($posDocument['document_type'] ?? '') !== 'sales_invoice' || (string) ($posDocument['status'] ?? '') !== 'prepared') {
        $failures[] = 'Debe crear un documento fiscal de venta con estado preparado.';
    }
    if (count((array) ($posDocument['lines'] ?? [])) !== 1 || (float) ($posDocument['subtotal'] ?? 0) !== 7000.0 || (float) ($posDocument['tax_total'] ?? 0) !== 1330.0 || (float) ($posDocument['total'] ?? 0) !== 8330.0) {
        $failures[] = 'Debe mapear lineas y totales de la venta POS al documento fiscal.';
    }
    if ((string) ($posDocument['receiver_party_id'] ?? '') !== '11') {
        $failures[] = 'Debe reutilizar el customer_id de la venta como receptor fiscal cuando exista.';
    }

    $purchaseDocument = $service->createDocumentFromSource([
        'tenant_id' => $tenantAlpha,
        'app_id' => 'purchase_app',
        'source_module' => 'purchases',
        'source_entity_type' => 'purchase',
        'source_entity_id' => $purchaseId,
        'document_type' => 'support_document',
        'status' => 'pending',
    ]);
    $purchaseDocumentId = (string) ($purchaseDocument['id'] ?? '');
    if ($purchaseDocumentId === '' || (string) ($purchaseDocument['issuer_party_id'] ?? '') !== '10') {
        $failures[] = 'Debe crear el documento fiscal de compra enlazado al proveedor.';
    }

    $purchaseDocument = $service->replaceDocumentLines([
        'tenant_id' => $tenantAlpha,
        'app_id' => 'purchase_app',
        'fiscal_document_id' => $purchaseDocumentId,
        'lines' => [
            ['description' => 'Servicio logistica', 'qty' => 2, 'unit_amount' => 10000, 'tax_rate' => 19],
            ['description' => 'Material POP', 'qty' => 1, 'unit_amount' => 5000, 'tax_rate' => 0],
        ],
    ]);
    if ((float) ($purchaseDocument['subtotal'] ?? 0) !== 25000.0 || (float) ($purchaseDocument['tax_total'] ?? 0) !== 3800.0 || (float) ($purchaseDocument['total'] ?? 0) !== 28800.0 || count((array) ($purchaseDocument['lines'] ?? [])) !== 2) {
        $failures[] = 'Debe permitir actualizar lineas fiscales y recalcular totales.';
    }

    $loaded = $service->getDocument($tenantAlpha, $posDocumentId, 'pos_app');
    if ((string) ($loaded['id'] ?? '') !== $posDocumentId) {
        $failures[] = 'Debe cargar documentos fiscales por id.';
    }

    $bySource = $service->getDocumentBySource(
        $tenantAlpha,
        'purchases',
        'purchase',
        $purchaseId,
        ['document_type' => 'support_document', 'app_id' => 'purchase_app'],
        'purchase_app'
    );
    if ((string) ($bySource['id'] ?? '') !== $purchaseDocumentId) {
        $failures[] = 'Debe cargar documentos fiscales por origen sin ambiguedad.';
    }

    $listed = $service->listDocuments($tenantAlpha, ['limit' => 10]);
    if (count($listed) < 2) {
        $failures[] = 'Debe listar documentos fiscales del tenant actual.';
    }

    $event = $service->recordEvent([
        'tenant_id' => $tenantAlpha,
        'app_id' => 'pos_app',
        'fiscal_document_id' => $posDocumentId,
        'event_type' => 'provider_stub_ready',
        'event_status' => 'recorded',
        'payload' => ['provider' => 'pending'],
    ]);
    if ((string) ($event['fiscal_document_id'] ?? '') !== $posDocumentId || (string) ($event['event_type'] ?? '') !== 'provider_stub_ready') {
        $failures[] = 'Debe registrar eventos fiscales ligados al documento.';
    }

    $updated = $service->updateStatus([
        'tenant_id' => $tenantAlpha,
        'app_id' => 'pos_app',
        'fiscal_document_id' => $posDocumentId,
        'status' => 'submitted',
        'reason' => 'ready_for_provider',
    ]);
    if ((string) ($updated['status'] ?? '') !== 'submitted') {
        $failures[] = 'Debe actualizar el estado fiscal con transicion valida.';
    }

    try {
        $service->updateStatus([
            'tenant_id' => $tenantAlpha,
            'app_id' => 'pos_app',
            'fiscal_document_id' => $posDocumentId,
            'status' => 'draft',
        ]);
        $failures[] = 'Debe bloquear transiciones invalidas de estado fiscal.';
    } catch (Throwable $e) {
        if ((string) $e->getMessage() !== 'FISCAL_STATUS_TRANSITION_INVALID') {
            $failures[] = 'La transicion fiscal invalida devolvio un error inesperado: ' . $e->getMessage();
        }
    }

    try {
        $service->getDocument($tenantBeta, $posDocumentId, 'pos_app');
        $failures[] = 'No debe permitir leer documentos fiscales desde otro tenant.';
    } catch (Throwable $e) {
        if ((string) $e->getMessage() !== 'FISCAL_DOCUMENT_NOT_FOUND') {
            $failures[] = 'Tenant isolation fiscal devolvio un error inesperado: ' . $e->getMessage();
        }
    }
} catch (Throwable $e) {
    $failures[] = 'El flujo fiscal de servicio debe pasar: ' . $e->getMessage();
}

try {
    $catalog = (new ContractRegistry())->getSkillsCatalog();
    $resolver = new SkillResolver();
    $skillRegistry = new SkillRegistry($catalog);
    $createSkill = $resolver->resolve(
        'crear documento fiscal source_module=pos source_entity_type=sale source_entity_id=' . $posSaleId . ' document_type=sales_invoice',
        $skillRegistry,
        []
    );
    $listSkill = $resolver->resolve('listar documentos fiscales status=submitted', $skillRegistry, []);
    $sourceSkill = $resolver->resolve(
        'buscar documento fiscal por origen source_module=purchases source_entity_type=purchase source_entity_id=' . $purchaseId,
        $skillRegistry,
        []
    );
    $statusSkill = $resolver->resolve('actualizar estado fiscal fiscal_document_id=' . $posDocumentId . ' status=submitted', $skillRegistry, []);

    if ((string) (($createSkill['selected']['name'] ?? '') ?: '') !== 'fiscal_create_document') {
        $failures[] = 'SkillResolver debe detectar fiscal_create_document.';
    }
    if ((string) (($listSkill['selected']['name'] ?? '') ?: '') !== 'fiscal_list_documents') {
        $failures[] = 'SkillResolver debe detectar fiscal_list_documents.';
    }
    if ((string) (($sourceSkill['selected']['name'] ?? '') ?: '') !== 'fiscal_get_by_source') {
        $failures[] = 'SkillResolver debe detectar fiscal_get_by_source.';
    }
    if ((string) (($statusSkill['selected']['name'] ?? '') ?: '') !== 'fiscal_update_status') {
        $failures[] = 'SkillResolver debe detectar fiscal_update_status.';
    }
} catch (Throwable $e) {
    $failures[] = 'El catalogo fiscal debe resolver skills correctamente: ' . $e->getMessage();
}

try {
    $chatSale = createPosSale($posService, $tenantAlpha, 'pos_app', '7701234500012', 1, 'ana uno', 'cashier_chat');
    $chatSaleId = (string) ($chatSale['id'] ?? '');

    $sharedEnv = [
        'APP_ENV' => 'local',
        'ALLOW_RUNTIME_SCHEMA' => '1',
        'PROJECT_REGISTRY_DB_PATH' => $tmpDir . '/project_registry.sqlite',
        'DB_DRIVER' => 'sqlite',
        'DB_PATH' => $tmpDir . '/fiscal_engine.sqlite',
        'DB_NAMESPACE_BY_PROJECT' => '0',
        'PROJECT_STORAGE_MODEL' => 'legacy',
        'DB_STORAGE_MODEL' => 'legacy',
    ];
    $sharedEnv['SUKI_RUNTIME_ENV_OVERRIDES_JSON'] = json_encode($sharedEnv, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $chatCreate = runChatTurn([
        'project_root' => $tmpProjectRoot,
        'env' => $sharedEnv,
        'payload' => [
            'tenant_id' => $tenantAlpha,
            'project_id' => 'pos_app',
            'session_id' => 'fiscal_chat_' . time() . '_create',
            'user_id' => 'fiscal_chat',
            'role' => 'admin',
            'is_authenticated' => true,
            'mode' => 'app',
            'channel' => 'test',
            'message' => 'crear documento fiscal source_module=pos source_entity_type=sale source_entity_id=' . $chatSaleId . ' document_type=sales_invoice',
        ],
    ]);
    $chatCreateData = is_array($chatCreate['data'] ?? null) ? (array) $chatCreate['data'] : [];
    $chatDocument = is_array($chatCreateData['document'] ?? null) ? (array) $chatCreateData['document'] : [];
    $chatDocumentId = (string) ($chatDocument['id'] ?? '');
    if ((string) ($chatCreate['status'] ?? '') !== 'success' || (string) (($chatCreateData['module_used'] ?? '') ?: '') !== 'fiscal' || $chatDocumentId === '') {
        $failures[] = 'ChatAgent debe crear documentos fiscales via skill + CommandBus.';
    }

    $chatStatus = runChatTurn([
        'project_root' => $tmpProjectRoot,
        'env' => $sharedEnv,
        'payload' => [
            'tenant_id' => $tenantAlpha,
            'project_id' => 'pos_app',
            'session_id' => 'fiscal_chat_' . time() . '_status',
            'user_id' => 'fiscal_chat',
            'role' => 'admin',
            'is_authenticated' => true,
            'mode' => 'app',
            'channel' => 'test',
            'message' => 'actualizar estado fiscal fiscal_document_id=' . $chatDocumentId . ' status=submitted',
        ],
    ]);
    if ((string) ($chatStatus['status'] ?? '') !== 'success' || (string) (($chatStatus['data']['fiscal_status'] ?? '') ?: '') !== 'submitted') {
        $failures[] = 'ChatAgent debe actualizar estados fiscales.';
    }
} catch (Throwable $e) {
    $failures[] = 'El flujo chat/skills fiscal debe pasar: ' . $e->getMessage();
}

try {
    $apiSale = createPosSale($posService, $tenantAlpha, 'pos_app', '7701234500011', 1, 'ana uno', 'cashier_api');
    $apiSaleId = (string) ($apiSale['id'] ?? '');
    $env = [
        'APP_ENV' => 'local',
        'ALLOW_RUNTIME_SCHEMA' => '1',
        'PROJECT_REGISTRY_DB_PATH' => $tmpDir . '/project_registry.sqlite',
        'DB_DRIVER' => 'sqlite',
        'DB_PATH' => $tmpDir . '/fiscal_engine.sqlite',
        'DB_NAMESPACE_BY_PROJECT' => '0',
        'PROJECT_STORAGE_MODEL' => 'legacy',
        'DB_STORAGE_MODEL' => 'legacy',
    ];
    $env['SUKI_RUNTIME_ENV_OVERRIDES_JSON'] = json_encode($env, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $session = [
        'auth_user' => [
            'id' => 'api_fiscal_user',
            'tenant_id' => $tenantAlpha,
            'project_id' => 'pos_app',
            'role' => 'admin',
            'label' => 'API Fiscal',
        ],
    ];

    $create = runApiRoute([
        'route' => 'fiscal/create-document',
        'method' => 'POST',
        'payload' => [
            'project_id' => 'pos_app',
            'source_module' => 'pos',
            'source_entity_type' => 'sale',
            'source_entity_id' => $apiSaleId,
            'document_type' => 'sales_invoice',
        ],
        'session' => $session,
        'env' => $env,
    ]);
    $createJson = $create['json'];
    $apiDocumentId = (string) (($createJson['data']['document']['id'] ?? '') ?: '');
    if (!is_array($createJson) || (string) ($createJson['status'] ?? '') !== 'success' || $apiDocumentId === '') {
        $failures[] = 'API fiscal/create-document debe crear el documento fiscal.';
    }

    $get = runApiRoute([
        'route' => 'fiscal/get-document',
        'method' => 'GET',
        'query' => [
            'project_id' => 'pos_app',
            'fiscal_document_id' => $apiDocumentId,
        ],
        'session' => $session,
        'env' => $env,
    ]);
    $getJson = $get['json'];
    if (!is_array($getJson) || (string) ($getJson['status'] ?? '') !== 'success' || (string) (($getJson['data']['document']['id'] ?? '') ?: '') !== $apiDocumentId) {
        $failures[] = 'API fiscal/get-document debe devolver el documento fiscal exacto.';
    }
} catch (Throwable $e) {
    $failures[] = 'Las rutas API fiscales deben pasar: ' . $e->getMessage();
}

foreach ($previous as $key => $value) {
    restoreEnvValue($key, $value);
}

if ($failures !== []) {
    echo json_encode(['ok' => false, 'failures' => $failures], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(1);
}

echo json_encode(['ok' => true], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit(0);

function createPosSale(POSService $service, string $tenantId, string $appId, string $barcode, float $qty, string $customerQuery, string $userId): array
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
    $draft = $service->attachCustomerToDraft([
        'tenant_id' => $tenantId,
        'app_id' => $appId,
        'draft_id' => (string) ($draft['id'] ?? ''),
        'query' => $customerQuery,
    ]);
    $result = $service->finalizeDraftSale([
        'tenant_id' => $tenantId,
        'app_id' => $appId,
        'draft_id' => (string) ($draft['id'] ?? ''),
        'requested_by_user_id' => $userId,
    ]);

    return is_array($result['sale'] ?? null) ? (array) $result['sale'] : [];
}

function createPurchase(PurchasesService $service, string $tenantId, string $appId, string $productQuery, float $qty, float $unitCost, float $taxRate, string $supplierQuery, string $userId): array
{
    $draft = $service->createDraft([
        'tenant_id' => $tenantId,
        'app_id' => $appId,
        'currency' => 'COP',
    ]);
    $draft = $service->addLineToDraft([
        'tenant_id' => $tenantId,
        'app_id' => $appId,
        'draft_id' => (string) ($draft['id'] ?? ''),
        'query' => $productQuery,
        'qty' => $qty,
        'unit_cost' => $unitCost,
        'tax_rate' => $taxRate,
    ]);
    $draft = $service->attachSupplierToDraft([
        'tenant_id' => $tenantId,
        'app_id' => $appId,
        'draft_id' => (string) ($draft['id'] ?? ''),
        'query' => $supplierQuery,
    ]);
    $result = $service->finalizeDraft([
        'tenant_id' => $tenantId,
        'app_id' => $appId,
        'draft_id' => (string) ($draft['id'] ?? ''),
        'created_by_user_id' => $userId,
    ]);

    return is_array($result['purchase'] ?? null) ? (array) $result['purchase'] : [];
}

function field(string $name, string $type, array $extra = []): array
{
    return array_merge([
        'name' => $name,
        'label' => ucfirst(str_replace('_', ' ', $name)),
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
        'table' => ['name' => $tableName, 'primaryKey' => 'id', 'timestamps' => true, 'softDelete' => false, 'tenantScoped' => true],
        'fields' => $fields,
        'grids' => [],
        'relations' => [],
        'rules' => [],
        'permissions' => ['read' => ['admin', 'seller'], 'create' => ['admin'], 'update' => ['admin'], 'delete' => ['admin']],
    ];

    file_put_contents($projectRoot . '/contracts/entities/' . $name . '.entity.json', json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function seedSearchTables(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, sku TEXT, barcode TEXT, nombre TEXT, precio_venta REAL, iva_rate REAL, activo INTEGER, created_at TEXT, updated_at TEXT)');
    $pdo->exec('CREATE INDEX idx_products_tenant_barcode ON products (tenant_id, barcode)');
    $pdo->exec('CREATE INDEX idx_products_tenant_sku ON products (tenant_id, sku)');
    $pdo->exec('CREATE INDEX idx_products_tenant_nombre ON products (tenant_id, nombre)');
    $pdo->exec('CREATE TABLE customers (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, documento TEXT, nombre TEXT, activo INTEGER, created_at TEXT, updated_at TEXT)');
    $pdo->exec('CREATE INDEX idx_customers_tenant_documento ON customers (tenant_id, documento)');
    $pdo->exec('CREATE INDEX idx_customers_tenant_nombre ON customers (tenant_id, nombre)');
    $pdo->exec('CREATE TABLE suppliers (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, nit TEXT, nombre TEXT, activo INTEGER, created_at TEXT, updated_at TEXT)');
    $pdo->exec('CREATE INDEX idx_suppliers_tenant_nit ON suppliers (tenant_id, nit)');
    $pdo->exec('CREATE INDEX idx_suppliers_tenant_nombre ON suppliers (tenant_id, nombre)');
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
    $raw = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($helper) . ' ' . escapeshellarg($encoded));
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
    $raw = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($helper) . ' ' . escapeshellarg($encoded));
    $json = json_decode($raw, true);

    return ['raw' => $raw, 'json' => is_array($json) ? $json : null];
}

/**
 * @param string|false $value
 */
function restoreEnvValue(string $key, $value): void
{
    if ($value === false) {
        putenv($key);
        return;
    }

    putenv($key . '=' . $value);
}
