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
$tmpDir = FRAMEWORK_ROOT . '/tests/tmp/pos_sales_flow_receipt_' . time() . '_' . random_int(1000, 9999);
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
putenv('DB_PATH=' . $tmpDir . '/pos_sales.sqlite');
putenv('DB_NAMESPACE_BY_PROJECT=0');
putenv('PROJECT_STORAGE_MODEL=legacy');
putenv('DB_STORAGE_MODEL=legacy');

$pdo = new PDO('sqlite:' . $tmpDir . '/pos_sales.sqlite');
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
$now = new DateTimeImmutable('2026-03-10 12:00:00');
$yesterday = $now->modify('-1 day');

$productStmt = $pdo->prepare(
    'INSERT INTO products (id, tenant_id, sku, barcode, nombre, precio_venta, iva_rate, activo, created_at, updated_at)
     VALUES (:id, :tenant_id, :sku, :barcode, :nombre, :precio_venta, :iva_rate, :activo, :created_at, :updated_at)'
);
foreach ([
    ['id' => 1, 'tenant_id' => $tenantAlphaInt, 'sku' => 'SKU-COCA-350', 'barcode' => '7701111111111', 'nombre' => 'Coca Cola 350', 'precio_venta' => 3500, 'iva_rate' => 19, 'activo' => 1, 'created_at' => $yesterday->format('Y-m-d H:i:s')],
    ['id' => 2, 'tenant_id' => $tenantAlphaInt, 'sku' => 'SKU-ARROZ-GR', 'barcode' => '7701111111113', 'nombre' => 'Arroz Grande Premium', 'precio_venta' => 4200, 'iva_rate' => 5, 'activo' => 1, 'created_at' => $now->format('Y-m-d H:i:s')],
    ['id' => 3, 'tenant_id' => $tenantBetaInt, 'sku' => 'SKU-BETA-1', 'barcode' => '7709999999991', 'nombre' => 'Producto Beta', 'precio_venta' => 9999, 'iva_rate' => 0, 'activo' => 1, 'created_at' => $now->format('Y-m-d H:i:s')],
] as $row) {
    $productStmt->execute($row + ['updated_at' => $row['created_at']]);
}

$customerStmt = $pdo->prepare(
    'INSERT INTO customers (id, tenant_id, documento, nombre, activo, created_at, updated_at)
     VALUES (:id, :tenant_id, :documento, :nombre, :activo, :created_at, :updated_at)'
);
foreach ([
    ['id' => 11, 'tenant_id' => $tenantAlphaInt, 'documento' => 'CC-001', 'nombre' => 'Ana Cliente', 'activo' => 1, 'created_at' => $now->format('Y-m-d H:i:s')],
    ['id' => 21, 'tenant_id' => $tenantBetaInt, 'documento' => 'CC-BETA', 'nombre' => 'Cliente Beta', 'activo' => 1, 'created_at' => $now->format('Y-m-d H:i:s')],
] as $row) {
    $customerStmt->execute($row + ['updated_at' => $row['created_at']]);
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
    $draft = $service->createDraft([
        'tenant_id' => $tenantAlpha,
        'app_id' => 'pos_app',
        'currency' => 'COP',
    ]);
    $draftId = (string) ($draft['id'] ?? '');
    $draft = $service->addLineByProductReference([
        'tenant_id' => $tenantAlpha,
        'app_id' => 'pos_app',
        'draft_id' => $draftId,
        'barcode' => '7701111111111',
        'qty' => 2,
    ]);
    $draft = $service->attachCustomerToDraft([
        'tenant_id' => $tenantAlpha,
        'app_id' => 'pos_app',
        'draft_id' => $draftId,
        'query' => 'ana cliente',
    ]);

    $finalized = $service->finalizeDraftSale([
        'tenant_id' => $tenantAlpha,
        'app_id' => 'pos_app',
        'draft_id' => $draftId,
        'requested_by_user_id' => 'cashier_1',
    ]);
    $sale = is_array($finalized['sale'] ?? null) ? (array) $finalized['sale'] : [];
    $completedDraft = is_array($finalized['draft'] ?? null) ? (array) $finalized['draft'] : [];
    $saleId = (string) ($sale['id'] ?? '');
    $saleNumber = (string) ($sale['sale_number'] ?? '');
    $saleLines = is_array($sale['lines'] ?? null) ? (array) $sale['lines'] : [];
    $saleLine = is_array($saleLines[0] ?? null) ? (array) $saleLines[0] : [];

    if ($saleId === '' || $saleNumber === '' || (string) ($sale['status'] ?? '') !== 'completed') {
        $failures[] = 'Debe finalizar el borrador en una venta POS numerada y completada.';
    }
    if ((string) ($completedDraft['status'] ?? '') !== 'checked_out') {
        $failures[] = 'Debe marcar el borrador como checked_out despues de finalizar la venta.';
    }
    if (count($saleLines) !== 1 || (string) ($saleLine['product_id'] ?? '') !== '1' || (float) ($saleLine['line_total'] ?? 0) !== 8330.0) {
        $failures[] = 'Debe crear snapshot de lineas POS al finalizar.';
    }

    $receipt = $service->buildReceiptPayload($tenantAlpha, $saleId, 'pos_app');
    if ((string) ($receipt['sale_number'] ?? '') !== $saleNumber || trim((string) ($receipt['printable_text'] ?? '')) === '' || (string) (($receipt['header']['customer_label'] ?? '') ?: '') !== 'Ana Cliente') {
        $failures[] = 'Debe preparar payload de ticket POS con texto imprimible y cliente.';
    }

    $loadedById = $service->getSale($tenantAlpha, $saleId, 'pos_app');
    $loadedByNumber = $service->getSaleByNumber($tenantAlpha, $saleNumber, 'pos_app');
    if ((string) ($loadedById['id'] ?? '') !== $saleId || (string) ($loadedByNumber['id'] ?? '') !== $saleId) {
        $failures[] = 'Debe permitir consultar ventas POS por id y por numero.';
    }

    try {
        $service->finalizeDraftSale([
            'tenant_id' => $tenantAlpha,
            'app_id' => 'pos_app',
            'draft_id' => $draftId,
        ]);
        $failures[] = 'Debe bloquear una segunda finalizacion del mismo borrador POS.';
    } catch (Throwable $e) {
        if ((string) $e->getMessage() !== 'POS_DRAFT_ALREADY_FINALIZED') {
            $failures[] = 'La refinalizacion devolvio un error inesperado: ' . $e->getMessage();
        }
    }

    $emptyDraft = $service->createDraft([
        'tenant_id' => $tenantAlpha,
        'app_id' => 'pos_app',
        'currency' => 'COP',
    ]);
    try {
        $service->finalizeDraftSale([
            'tenant_id' => $tenantAlpha,
            'app_id' => 'pos_app',
            'draft_id' => (string) ($emptyDraft['id'] ?? ''),
        ]);
        $failures[] = 'Debe rechazar la finalizacion de un borrador POS vacio.';
    } catch (Throwable $e) {
        if ((string) $e->getMessage() !== 'POS_DRAFT_EMPTY') {
            $failures[] = 'El rechazo de borrador vacio devolvio un error inesperado: ' . $e->getMessage();
        }
    }

    $secondDraft = $service->createDraft([
        'tenant_id' => $tenantAlpha,
        'app_id' => 'pos_app',
        'currency' => 'COP',
    ]);
    $secondDraft = $service->addLineByProductReference([
        'tenant_id' => $tenantAlpha,
        'app_id' => 'pos_app',
        'draft_id' => (string) ($secondDraft['id'] ?? ''),
        'sku' => 'SKU-ARROZ-GR',
        'qty' => 1,
    ]);
    $secondSale = $service->finalizeDraftSale([
        'tenant_id' => $tenantAlpha,
        'app_id' => 'pos_app',
        'draft_id' => (string) ($secondDraft['id'] ?? ''),
        'requested_by_user_id' => 'cashier_2',
    ]);
    $secondSaleNumber = (string) (($secondSale['sale']['sale_number'] ?? '') ?: '');
    if ($secondSaleNumber === '' || $secondSaleNumber === $saleNumber) {
        $failures[] = 'La numeracion POS debe ser unica por tenant.';
    }

    $latestSales = $service->listSales($tenantAlpha, ['limit' => 1], 'pos_app');
    if (count($latestSales) !== 1 || (string) (($latestSales[0]['sale_number'] ?? '') ?: '') !== $secondSaleNumber) {
        $failures[] = 'listSales debe devolver la venta mas reciente primero.';
    }
} catch (Throwable $e) {
    $failures[] = 'El flujo POS draft -> sale -> receipt debe funcionar extremo a extremo: ' . $e->getMessage();
}

try {
    $service->getSale($tenantBeta, (string) ($saleId ?? ''), 'pos_app');
    $failures[] = 'No debe permitir leer ventas POS de otro tenant.';
} catch (Throwable $e) {
    if ((string) $e->getMessage() !== 'POS_SALE_NOT_FOUND') {
        $failures[] = 'Tenant isolation en ventas POS devolvio un error inesperado: ' . $e->getMessage();
    }
}

try {
    $service->getSaleByNumber($tenantBeta, (string) ($saleNumber ?? ''), 'pos_app');
    $failures[] = 'No debe permitir leer ventas POS por numero desde otro tenant.';
} catch (Throwable $e) {
    if ((string) $e->getMessage() !== 'POS_SALE_NOT_FOUND') {
        $failures[] = 'Tenant isolation por numero POS devolvio un error inesperado: ' . $e->getMessage();
    }
}

try {
    $catalog = (new ContractRegistry())->getSkillsCatalog();
    $resolver = new SkillResolver();
    $skillRegistry = new SkillRegistry($catalog);
    $finalizeSkill = $resolver->resolve('finalizar venta pos draft_id=1', $skillRegistry, []);
    $listSkill = $resolver->resolve('ultima venta pos', $skillRegistry, []);
    $receiptSkill = $resolver->resolve('preparar ticket pos sale_number=POS-TEST-1', $skillRegistry, []);

    if ((string) (($finalizeSkill['selected']['name'] ?? '') ?: '') !== 'pos_finalize_sale') {
        $failures[] = 'SkillResolver debe detectar pos_finalize_sale.';
    }
    if ((string) (($listSkill['selected']['name'] ?? '') ?: '') !== 'pos_list_sales') {
        $failures[] = 'SkillResolver debe detectar pos_list_sales.';
    }
    if ((string) (($receiptSkill['selected']['name'] ?? '') ?: '') !== 'pos_build_receipt') {
        $failures[] = 'SkillResolver debe detectar pos_build_receipt.';
    }
} catch (Throwable $e) {
    $failures[] = 'El catalogo POS de ventas/receipt debe resolver skills correctamente: ' . $e->getMessage();
}

try {
    $chatDraft = $service->createDraft([
        'tenant_id' => $tenantAlpha,
        'app_id' => 'pos_app',
        'currency' => 'COP',
    ]);
    $chatDraft = $service->addLineByProductReference([
        'tenant_id' => $tenantAlpha,
        'app_id' => 'pos_app',
        'draft_id' => (string) ($chatDraft['id'] ?? ''),
        'barcode' => '7701111111111',
        'qty' => 1,
    ]);

    $sharedEnv = [
        'APP_ENV' => 'local',
        'ALLOW_RUNTIME_SCHEMA' => '1',
        'PROJECT_REGISTRY_DB_PATH' => $tmpDir . '/project_registry.sqlite',
        'DB_DRIVER' => 'sqlite',
        'DB_PATH' => $tmpDir . '/pos_sales.sqlite',
        'DB_NAMESPACE_BY_PROJECT' => '0',
        'PROJECT_STORAGE_MODEL' => 'legacy',
        'DB_STORAGE_MODEL' => 'legacy',
    ];
    $sharedEnv['SUKI_RUNTIME_ENV_OVERRIDES_JSON'] = json_encode($sharedEnv, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $chatFinalize = runChatTurn([
        'project_root' => $tmpProjectRoot,
        'env' => $sharedEnv,
        'payload' => [
            'tenant_id' => $tenantAlpha,
            'project_id' => 'pos_app',
            'session_id' => 'pos_sales_' . time() . '_finalize',
            'user_id' => 'operator_pos',
            'role' => 'admin',
            'is_authenticated' => true,
            'mode' => 'app',
            'channel' => 'test',
            'message' => 'finalizar venta pos draft_id=' . (string) ($chatDraft['id'] ?? ''),
        ],
    ]);
    $chatFinalizeData = is_array($chatFinalize['data'] ?? null) ? (array) $chatFinalize['data'] : [];
    $chatSale = is_array($chatFinalizeData['sale'] ?? null) ? (array) $chatFinalizeData['sale'] : [];
    $chatSaleNumber = (string) ($chatSale['sale_number'] ?? '');
    if ((string) ($chatFinalize['status'] ?? '') !== 'success' || (string) ($chatFinalizeData['pos_action'] ?? '') !== 'finalize_sale' || $chatSaleNumber === '') {
        $failures[] = 'ChatAgent debe finalizar ventas POS por skill + CommandBus.';
    }

    $chatReceipt = runChatTurn([
        'project_root' => $tmpProjectRoot,
        'env' => $sharedEnv,
        'payload' => [
            'tenant_id' => $tenantAlpha,
            'project_id' => 'pos_app',
            'session_id' => 'pos_sales_' . time() . '_receipt',
            'user_id' => 'operator_pos',
            'role' => 'admin',
            'is_authenticated' => true,
            'mode' => 'app',
            'channel' => 'test',
            'message' => 'preparar ticket pos sale_number=' . $chatSaleNumber,
        ],
    ]);
    $chatReceiptData = is_array($chatReceipt['data'] ?? null) ? (array) $chatReceipt['data'] : [];
    $chatReceiptPayload = is_array($chatReceiptData['receipt'] ?? null) ? (array) $chatReceiptData['receipt'] : [];
    if ((string) ($chatReceipt['status'] ?? '') !== 'success' || (string) ($chatReceiptData['pos_action'] ?? '') !== 'build_receipt' || trim((string) ($chatReceiptPayload['printable_text'] ?? '')) === '') {
        $failures[] = 'ChatAgent debe preparar tickets POS por skill.';
    }
} catch (Throwable $e) {
    $failures[] = 'El flujo chat/skills de venta POS debe pasar: ' . $e->getMessage();
}

try {
    $apiDraft = $service->createDraft([
        'tenant_id' => $tenantAlpha,
        'app_id' => 'pos_app',
        'currency' => 'COP',
    ]);
    $apiDraft = $service->addLineByProductReference([
        'tenant_id' => $tenantAlpha,
        'app_id' => 'pos_app',
        'draft_id' => (string) ($apiDraft['id'] ?? ''),
        'barcode' => '7701111111111',
        'qty' => 1,
    ]);

    $env = [
        'APP_ENV' => 'local',
        'ALLOW_RUNTIME_SCHEMA' => '1',
        'PROJECT_REGISTRY_DB_PATH' => $tmpDir . '/project_registry.sqlite',
        'DB_DRIVER' => 'sqlite',
        'DB_PATH' => $tmpDir . '/pos_sales.sqlite',
        'DB_NAMESPACE_BY_PROJECT' => '0',
        'PROJECT_STORAGE_MODEL' => 'legacy',
        'DB_STORAGE_MODEL' => 'legacy',
    ];
    $env['SUKI_RUNTIME_ENV_OVERRIDES_JSON'] = json_encode($env, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $session = [
        'auth_user' => [
            'id' => 'api_pos_user',
            'tenant_id' => $tenantAlpha,
            'project_id' => 'pos_app',
            'role' => 'admin',
            'label' => 'API POS',
        ],
    ];

    $finalize = runApiRoute([
        'route' => 'pos/finalize-sale',
        'method' => 'POST',
        'payload' => [
            'draft_id' => (string) ($apiDraft['id'] ?? ''),
            'project_id' => 'pos_app',
        ],
        'session' => $session,
        'env' => $env,
    ]);
    $finalizeJson = $finalize['json'];
    $apiSaleNumber = (string) (($finalizeJson['data']['sale']['sale_number'] ?? '') ?: '');
    if (!is_array($finalizeJson) || (string) ($finalizeJson['status'] ?? '') !== 'success' || $apiSaleNumber === '') {
        $failures[] = 'API pos/finalize-sale debe completar la venta POS.';
    }

    $receipt = runApiRoute([
        'route' => 'pos/build-receipt',
        'method' => 'GET',
        'query' => [
            'sale_number' => $apiSaleNumber,
            'project_id' => 'pos_app',
        ],
        'session' => $session,
        'env' => $env,
    ]);
    $receiptJson = $receipt['json'];
    if (!is_array($receiptJson) || (string) ($receiptJson['status'] ?? '') !== 'success' || trim((string) (($receiptJson['data']['receipt']['printable_text'] ?? '') ?: '')) === '') {
        $failures[] = 'API pos/build-receipt debe devolver ticket POS imprimible.';
    }
} catch (Throwable $e) {
    $failures[] = 'Las rutas API POS de venta/receipt deben pasar: ' . $e->getMessage();
}

foreach ($previous as $key => $value) {
    restoreEnvValue($key, $value);
}

$result = ['ok' => $failures === [], 'failures' => $failures];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($result['ok'] ? 0 : 1);

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
