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
$tmpDir = FRAMEWORK_ROOT . '/tests/tmp/fe_invoice_credit_note_support_docs_' . time() . '_' . random_int(1000, 9999);
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
putenv('DB_PATH=' . $tmpDir . '/fe_invoice_credit_note_support_docs.sqlite');
putenv('DB_NAMESPACE_BY_PROJECT=0');
putenv('PROJECT_STORAGE_MODEL=legacy');
putenv('DB_STORAGE_MODEL=legacy');

$pdo = new PDO('sqlite:' . $tmpDir . '/fe_invoice_credit_note_support_docs.sqlite');
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
$now = new DateTimeImmutable('2026-03-12 10:15:00');

$productStmt = $pdo->prepare(
    'INSERT INTO products (id, tenant_id, sku, barcode, nombre, precio_venta, iva_rate, activo, created_at, updated_at)
     VALUES (:id, :tenant_id, :sku, :barcode, :nombre, :precio_venta, :iva_rate, :activo, :created_at, :updated_at)'
);
foreach ([
    ['id' => 1, 'tenant_id' => $tenantAlphaInt, 'sku' => 'SKU-ALPHA-ARROZ', 'barcode' => '7701234500011', 'nombre' => 'Arroz Premium Alpha', 'precio_venta' => 3500, 'iva_rate' => 19, 'activo' => 1],
    ['id' => 2, 'tenant_id' => $tenantAlphaInt, 'sku' => 'SKU-ALPHA-ACEITE', 'barcode' => '7701234500012', 'nombre' => 'Aceite Hogar Uno', 'precio_venta' => 12000, 'iva_rate' => 5, 'activo' => 1],
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

try {
    $sale = createPosSale($posService, $tenantAlpha, 'pos_app', '7701234500011', 2, 'ana uno', 'cashier_alpha');
    $saleId = (string) ($sale['id'] ?? '');
    if ($saleId === '') {
        $failures[] = 'Debe crear una venta POS para las builders fiscales FE.';
        throw new RuntimeException('setup_failed');
    }

    $invoice = $service->createSalesInvoiceFromSale([
        'tenant_id' => $tenantAlpha,
        'app_id' => 'pos_app',
        'sale_id' => $saleId,
    ]);
    $invoiceId = (string) ($invoice['id'] ?? '');
    if ($invoiceId === '' || (string) ($invoice['document_type'] ?? '') !== 'sales_invoice' || (string) ($invoice['source_entity_id'] ?? '') !== $saleId) {
        $failures[] = 'Debe crear una factura electronica interna enlazada a la venta origen.';
    }

    $duplicateInvoice = $service->createSalesInvoiceFromSale([
        'tenant_id' => $tenantAlpha,
        'app_id' => 'pos_app',
        'sale_id' => $saleId,
    ]);
    $duplicateMetadata = is_array($duplicateInvoice['metadata'] ?? null) ? (array) $duplicateInvoice['metadata'] : [];
    $duplicateFlags = is_array($duplicateMetadata['duplicate_prevention'] ?? null) ? (array) $duplicateMetadata['duplicate_prevention'] : [];
    if ((string) ($duplicateInvoice['id'] ?? '') !== $invoiceId || ($duplicateFlags['duplicate_blocked'] ?? false) !== true) {
        $failures[] = 'Debe bloquear duplicados activos y reutilizar el documento fiscal existente.';
    }

    $returnResult = $posService->createReturnFromSale([
        'tenant_id' => $tenantAlpha,
        'app_id' => 'pos_app',
        'sale_id' => $saleId,
        'reason' => 'producto devuelto',
    ]);
    $return = is_array($returnResult['return'] ?? null) ? (array) $returnResult['return'] : [];
    $returnId = (string) ($return['id'] ?? '');
    if ($returnId === '') {
        $failures[] = 'Debe crear una devolucion POS para preparar la nota credito interna.';
    }

    $creditNote = $service->createCreditNoteFromSaleOrReturn([
        'tenant_id' => $tenantAlpha,
        'app_id' => 'pos_app',
        'return_id' => $returnId,
        'reason' => 'producto devuelto',
    ]);
    $creditNoteId = (string) ($creditNote['id'] ?? '');
    if ($creditNoteId === '' || (string) ($creditNote['document_type'] ?? '') !== 'credit_note' || (string) ($creditNote['source_entity_type'] ?? '') !== 'return') {
        $failures[] = 'Debe crear una nota credito interna desde la devolucion POS.';
    }

    $payload = $service->buildDocumentPayload($tenantAlpha, $creditNoteId, 'pos_app');
    $relatedFiscal = is_array($payload['references']['related_fiscal_document'] ?? null) ? (array) $payload['references']['related_fiscal_document'] : [];
    if ((string) ($payload['fiscal_document_id'] ?? '') !== $creditNoteId || (string) ($relatedFiscal['fiscal_document_id'] ?? '') !== $invoiceId) {
        $failures[] = 'El payload fiscal debe incluir referencia a la factura interna origen cuando exista.';
    }

    $creditNotes = $service->listDocumentsByType($tenantAlpha, 'credit_note', ['limit' => 10], 'pos_app');
    if ($creditNotes === [] || !array_reduce($creditNotes, static fn(bool $carry, array $item): bool => $carry && (($item['document_type'] ?? '') === 'credit_note'), true)) {
        $failures[] = 'Debe listar documentos fiscales por tipo sin mezclar otros tipos.';
    }

    $purchase = createPurchase($purchasesService, $tenantAlpha, 'purchase_app', 'premium alpha', 3, 2800, 5, 'distribuidora norte', 'buyer_alpha');
    $purchaseId = (string) ($purchase['id'] ?? '');
    $supportDocument = $service->createSupportDocumentFromPurchase([
        'tenant_id' => $tenantAlpha,
        'app_id' => 'purchase_app',
        'purchase_id' => $purchaseId,
    ]);
    if ((string) ($supportDocument['document_type'] ?? '') !== 'support_document' || (string) ($supportDocument['source_entity_id'] ?? '') !== $purchaseId) {
        $failures[] = 'Debe crear un documento soporte interno enlazado a la compra.';
    }

    $saleWithoutAdjustment = createPosSale($posService, $tenantAlpha, 'pos_app', '7701234500012', 1, 'ana uno', 'cashier_invalid_credit');
    try {
        $service->createCreditNoteFromSaleOrReturn([
            'tenant_id' => $tenantAlpha,
            'app_id' => 'pos_app',
            'sale_id' => (string) ($saleWithoutAdjustment['id'] ?? ''),
        ]);
        $failures[] = 'Debe bloquear notas credito internas desde ventas sin cancelacion ni devolucion.';
    } catch (Throwable $e) {
        if ((string) $e->getMessage() !== 'FISCAL_CREDIT_NOTE_SOURCE_INVALID') {
            $failures[] = 'La validacion de origen invalido para nota credito devolvio un error inesperado: ' . $e->getMessage();
        }
    }

    try {
        $service->getDocument($tenantBeta, $invoiceId, 'pos_app');
        $failures[] = 'No debe permitir leer documentos FE desde otro tenant.';
    } catch (Throwable $e) {
        if ((string) $e->getMessage() !== 'FISCAL_DOCUMENT_NOT_FOUND') {
            $failures[] = 'Tenant isolation fiscal FE devolvio un error inesperado: ' . $e->getMessage();
        }
    }
} catch (Throwable $e) {
    if ((string) $e->getMessage() !== 'setup_failed') {
        $failures[] = 'El flujo FE de servicio debe pasar: ' . $e->getMessage();
    }
}

try {
    $catalog = (new ContractRegistry())->getSkillsCatalog();
    $resolver = new SkillResolver();
    $skillRegistry = new SkillRegistry($catalog);

    $invoiceSkill = $resolver->resolve('crear factura electronica desde venta sale_id=123', $skillRegistry, []);
    $creditSkill = $resolver->resolve('crear nota credito desde devolucion return_id=456', $skillRegistry, []);
    $supportSkill = $resolver->resolve('crear documento soporte desde compra purchase_id=789', $skillRegistry, []);
    $payloadSkill = $resolver->resolve('preparar payload fiscal fiscal_document_id=abc', $skillRegistry, []);
    $listTypeSkill = $resolver->resolve('listar documentos fiscales por tipo document_type=credit_note', $skillRegistry, []);

    if ((string) (($invoiceSkill['selected']['name'] ?? '') ?: '') !== 'fiscal_create_sales_invoice_from_sale') {
        $failures[] = 'SkillResolver debe detectar fiscal_create_sales_invoice_from_sale.';
    }
    if ((string) (($creditSkill['selected']['name'] ?? '') ?: '') !== 'fiscal_create_credit_note') {
        $failures[] = 'SkillResolver debe detectar fiscal_create_credit_note.';
    }
    if ((string) (($supportSkill['selected']['name'] ?? '') ?: '') !== 'fiscal_create_support_document_from_purchase') {
        $failures[] = 'SkillResolver debe detectar fiscal_create_support_document_from_purchase.';
    }
    if ((string) (($payloadSkill['selected']['name'] ?? '') ?: '') !== 'fiscal_build_document_payload') {
        $failures[] = 'SkillResolver debe detectar fiscal_build_document_payload.';
    }
    if ((string) (($listTypeSkill['selected']['name'] ?? '') ?: '') !== 'fiscal_list_documents_by_type') {
        $failures[] = 'SkillResolver debe detectar fiscal_list_documents_by_type.';
    }
} catch (Throwable $e) {
    $failures[] = 'El catalogo FE debe resolver skills correctamente: ' . $e->getMessage();
}

try {
    $chatSale = createPosSale($posService, $tenantAlpha, 'pos_app', '7701234500012', 1, 'ana uno', 'cashier_chat');
    $chatSaleId = (string) ($chatSale['id'] ?? '');

    $sharedEnv = [
        'APP_ENV' => 'local',
        'ALLOW_RUNTIME_SCHEMA' => '1',
        'PROJECT_REGISTRY_DB_PATH' => $tmpDir . '/project_registry.sqlite',
        'DB_DRIVER' => 'sqlite',
        'DB_PATH' => $tmpDir . '/fe_invoice_credit_note_support_docs.sqlite',
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
            'session_id' => 'fe_chat_' . time() . '_create',
            'user_id' => 'fe_chat',
            'role' => 'admin',
            'is_authenticated' => true,
            'mode' => 'app',
            'channel' => 'test',
            'message' => 'crear factura electronica desde venta sale_id=' . $chatSaleId,
        ],
    ]);
    $chatCreateData = is_array($chatCreate['data'] ?? null) ? (array) $chatCreate['data'] : [];
    $chatDocument = is_array($chatCreateData['document'] ?? null) ? (array) $chatCreateData['document'] : [];
    $chatDocumentId = (string) ($chatDocument['id'] ?? '');
    if ((string) ($chatCreate['status'] ?? '') !== 'success' || (string) (($chatCreateData['fiscal_action'] ?? '') ?: '') !== 'create_sales_invoice_from_sale' || $chatDocumentId === '') {
        $failures[] = 'ChatAgent debe preparar la factura electronica interna via builder fiscal.';
    }

    $chatPayload = runChatTurn([
        'project_root' => $tmpProjectRoot,
        'env' => $sharedEnv,
        'payload' => [
            'tenant_id' => $tenantAlpha,
            'project_id' => 'pos_app',
            'session_id' => 'fe_chat_' . time() . '_payload',
            'user_id' => 'fe_chat',
            'role' => 'admin',
            'is_authenticated' => true,
            'mode' => 'app',
            'channel' => 'test',
            'message' => 'preparar payload fiscal fiscal_document_id=' . $chatDocumentId,
        ],
    ]);
    $chatPayloadData = is_array($chatPayload['data'] ?? null) ? (array) $chatPayload['data'] : [];
    $payloadItem = is_array($chatPayloadData['payload'] ?? null) ? (array) $chatPayloadData['payload'] : [];
    if ((string) ($chatPayload['status'] ?? '') !== 'success' || (string) ($payloadItem['fiscal_document_id'] ?? '') !== $chatDocumentId) {
        $failures[] = 'ChatAgent debe construir payloads fiscales internos.';
    }
} catch (Throwable $e) {
    $failures[] = 'El flujo chat FE debe pasar: ' . $e->getMessage();
}

try {
    $apiPurchase = createPurchase($purchasesService, $tenantAlpha, 'purchase_app', 'premium alpha', 2, 3000, 19, 'distribuidora norte', 'buyer_api');
    $apiPurchaseId = (string) ($apiPurchase['id'] ?? '');
    $env = [
        'APP_ENV' => 'local',
        'ALLOW_RUNTIME_SCHEMA' => '1',
        'PROJECT_REGISTRY_DB_PATH' => $tmpDir . '/project_registry.sqlite',
        'DB_DRIVER' => 'sqlite',
        'DB_PATH' => $tmpDir . '/fe_invoice_credit_note_support_docs.sqlite',
        'DB_NAMESPACE_BY_PROJECT' => '0',
        'PROJECT_STORAGE_MODEL' => 'legacy',
        'DB_STORAGE_MODEL' => 'legacy',
    ];
    $env['SUKI_RUNTIME_ENV_OVERRIDES_JSON'] = json_encode($env, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $session = [
        'auth_user' => [
            'id' => 'api_fe_user',
            'tenant_id' => $tenantAlpha,
            'project_id' => 'purchase_app',
            'role' => 'admin',
            'label' => 'API FE',
        ],
    ];

    $create = runApiRoute([
        'route' => 'fiscal/create-support-document-from-purchase',
        'method' => 'POST',
        'payload' => [
            'project_id' => 'purchase_app',
            'purchase_id' => $apiPurchaseId,
        ],
        'session' => $session,
        'env' => $env,
    ]);
    $createJson = $create['json'];
    $apiDocumentId = (string) (($createJson['data']['document']['id'] ?? '') ?: '');
    if (!is_array($createJson) || (string) ($createJson['status'] ?? '') !== 'success' || $apiDocumentId === '') {
        $failures[] = 'API fiscal/create-support-document-from-purchase debe preparar el documento soporte interno.';
    }

    $payloadResponse = runApiRoute([
        'route' => 'fiscal/build-document-payload',
        'method' => 'GET',
        'query' => [
            'project_id' => 'purchase_app',
            'fiscal_document_id' => $apiDocumentId,
        ],
        'session' => $session,
        'env' => $env,
    ]);
    $payloadJson = $payloadResponse['json'];
    if (!is_array($payloadJson) || (string) ($payloadJson['status'] ?? '') !== 'success' || (string) (($payloadJson['data']['payload']['fiscal_document_id'] ?? '') ?: '') !== $apiDocumentId) {
        $failures[] = 'API fiscal/build-document-payload debe devolver el payload fiscal estructurado.';
    }
} catch (Throwable $e) {
    $failures[] = 'Las rutas API FE deben pasar: ' . $e->getMessage();
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
