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
use App\Core\PurchasesEventLogger;
use App\Core\PurchasesRepository;
use App\Core\PurchasesService;
use App\Core\SkillRegistry;
use App\Core\SkillResolver;

$failures = [];
$tmpDir = FRAMEWORK_ROOT . '/tests/tmp/purchases_core_module_' . time() . '_' . random_int(1000, 9999);
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
putenv('DB_PATH=' . $tmpDir . '/purchases_core.sqlite');
putenv('DB_NAMESPACE_BY_PROJECT=0');
putenv('PROJECT_STORAGE_MODEL=legacy');
putenv('DB_STORAGE_MODEL=legacy');

$pdo = new PDO('sqlite:' . $tmpDir . '/purchases_core.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
Database::setConnection($pdo);

writeEntityContract($tmpProjectRoot, 'products', 'productos', 'products', [
    field('id', 'int', ['primary' => true, 'source' => 'system']),
    field('tenant_id', 'int'),
    field('sku', 'string'),
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
$now = new DateTimeImmutable('2026-03-11 10:00:00');

$productStmt = $pdo->prepare(
    'INSERT INTO products (id, tenant_id, sku, nombre, activo, created_at, updated_at)
     VALUES (:id, :tenant_id, :sku, :nombre, :activo, :created_at, :updated_at)'
);
foreach ([
    ['id' => 1, 'tenant_id' => $tenantAlphaInt, 'sku' => 'SKU-ARROZ-1', 'nombre' => 'Arroz Premium 1Kg', 'activo' => 1],
    ['id' => 2, 'tenant_id' => $tenantAlphaInt, 'sku' => 'SKU-ACEITE-1', 'nombre' => 'Aceite Familiar', 'activo' => 1],
    ['id' => 3, 'tenant_id' => $tenantBetaInt, 'sku' => 'SKU-BETA-1', 'nombre' => 'Producto Beta', 'activo' => 1],
] as $row) {
    $productStmt->execute($row + ['created_at' => $now->format('Y-m-d H:i:s'), 'updated_at' => $now->format('Y-m-d H:i:s')]);
}

$supplierStmt = $pdo->prepare(
    'INSERT INTO suppliers (id, tenant_id, nit, nombre, activo, created_at, updated_at)
     VALUES (:id, :tenant_id, :nit, :nombre, :activo, :created_at, :updated_at)'
);
foreach ([
    ['id' => 10, 'tenant_id' => $tenantAlphaInt, 'nit' => '900111222', 'nombre' => 'Proveedor Acme', 'activo' => 1],
    ['id' => 11, 'tenant_id' => $tenantAlphaInt, 'nit' => '900333444', 'nombre' => 'Proveedor Norte', 'activo' => 1],
    ['id' => 20, 'tenant_id' => $tenantBetaInt, 'nit' => '800999000', 'nombre' => 'Proveedor Beta', 'activo' => 1],
] as $row) {
    $supplierStmt->execute($row + ['created_at' => $now->format('Y-m-d H:i:s'), 'updated_at' => $now->format('Y-m-d H:i:s')]);
}

$registry = new EntityRegistry(null, $tmpProjectRoot);
$entitySearch = new EntitySearchService(
    new EntitySearchRepository($pdo, $registry),
    new AuditLogger($pdo),
    new EntitySearchEventLogger($tmpProjectRoot)
);
$service = new PurchasesService(
    new PurchasesRepository($pdo),
    $entitySearch,
    new AuditLogger($pdo),
    new PurchasesEventLogger($tmpProjectRoot)
);

try {
    $draft = $service->createDraft(['tenant_id' => $tenantAlpha, 'app_id' => 'purchase_app', 'currency' => 'COP']);
    $draftId = (string) ($draft['id'] ?? '');
    if ($draftId === '' || (string) ($draft['status'] ?? '') !== 'open') {
        $failures[] = 'Debe crear un borrador de compra abierto.';
    }

    $draft = $service->addLineToDraft([
        'tenant_id' => $tenantAlpha,
        'app_id' => 'purchase_app',
        'draft_id' => $draftId,
        'query' => 'arroz premium',
        'qty' => 1,
        'unit_cost' => 3000,
        'tax_rate' => 5,
    ]);
    $line = is_array(($draft['lines'][0] ?? null)) ? (array) $draft['lines'][0] : [];
    if ((string) ($line['product_id'] ?? '') !== '1') {
        $failures[] = 'Debe enlazar producto por entity search cuando se pasa query.';
    }

    $draft = $service->addLineToDraft([
        'tenant_id' => $tenantAlpha,
        'app_id' => 'purchase_app',
        'draft_id' => $draftId,
        'product_label' => 'Empaque plastico',
        'qty' => 2,
        'unit_cost' => 1000,
        'tax_rate' => 0,
    ]);
    if ((float) ($draft['subtotal'] ?? 0) !== 5000.0 || (float) ($draft['tax_total'] ?? 0) !== 150.0 || (float) ($draft['total'] ?? 0) !== 5150.0) {
        $failures[] = 'Debe recalcular totales del borrador de compra despues de agregar lineas.';
    }

    $secondLine = is_array(($draft['lines'][1] ?? null)) ? (array) $draft['lines'][1] : [];
    $draft = $service->removeLineFromDraft($tenantAlpha, $draftId, (string) ($secondLine['id'] ?? ''), 'purchase_app');
    if ((float) ($draft['subtotal'] ?? 0) !== 3000.0 || (float) ($draft['tax_total'] ?? 0) !== 150.0 || (float) ($draft['total'] ?? 0) !== 3150.0) {
        $failures[] = 'Debe recalcular totales al eliminar lineas.';
    }

    $draft = $service->attachSupplierToDraft([
        'tenant_id' => $tenantAlpha,
        'app_id' => 'purchase_app',
        'draft_id' => $draftId,
        'query' => 'proveedor acme',
    ]);
    if ((string) ($draft['supplier_id'] ?? '') !== '10') {
        $failures[] = 'Debe asociar proveedor resuelto por entity search.';
    }

    $emptyDraft = $service->createDraft(['tenant_id' => $tenantAlpha, 'app_id' => 'purchase_app']);
    try {
        $service->finalizeDraft(['tenant_id' => $tenantAlpha, 'app_id' => 'purchase_app', 'draft_id' => (string) ($emptyDraft['id'] ?? '')]);
        $failures[] = 'Debe bloquear finalizacion de borrador vacio.';
    } catch (Throwable $e) {
        if ((string) $e->getMessage() !== 'PURCHASE_DRAFT_EMPTY') {
            $failures[] = 'Borrador vacio devolvio error inesperado: ' . $e->getMessage();
        }
    }

    $result = $service->finalizeDraft(['tenant_id' => $tenantAlpha, 'app_id' => 'purchase_app', 'draft_id' => $draftId, 'created_by_user_id' => 'buyer_1']);
    $purchase = is_array($result['purchase'] ?? null) ? (array) $result['purchase'] : [];
    $purchaseId = (string) ($purchase['id'] ?? '');
    $purchaseNumber = (string) ($purchase['purchase_number'] ?? '');
    if ($purchaseId === '' || $purchaseNumber === '' || !str_starts_with($purchaseNumber, 'PUR-')) {
        $failures[] = 'Debe generar compra y numbering tenant-scoped.';
    }
    if (count((array) ($purchase['lines'] ?? [])) !== 1 || (string) (($result['draft']['status'] ?? '') ?: '') !== 'completed') {
        $failures[] = 'Debe crear snapshot de lineas y marcar borrador completado.';
    }

    $anotherDraft = $service->createDraft(['tenant_id' => $tenantAlpha, 'app_id' => 'purchase_app']);
    $anotherDraft = $service->addLineToDraft([
        'tenant_id' => $tenantAlpha,
        'app_id' => 'purchase_app',
        'draft_id' => (string) ($anotherDraft['id'] ?? ''),
        'product_label' => 'Caja carton',
        'qty' => 1,
        'unit_cost' => 2000,
    ]);
    $another = $service->finalizeDraft(['tenant_id' => $tenantAlpha, 'app_id' => 'purchase_app', 'draft_id' => (string) ($anotherDraft['id'] ?? '')]);
    if ((string) (($another['purchase']['purchase_number'] ?? '') ?: '') === $purchaseNumber) {
        $failures[] = 'La numeracion de compras debe ser unica.';
    }

    $loadedById = $service->getPurchase($tenantAlpha, $purchaseId, 'purchase_app');
    $loadedByNumber = $service->getPurchaseByNumber($tenantAlpha, $purchaseNumber, 'purchase_app');
    if ((string) ($loadedById['id'] ?? '') !== $purchaseId || (string) ($loadedByNumber['purchase_number'] ?? '') !== $purchaseNumber) {
        $failures[] = 'Debe cargar compra por id y por numero.';
    }
} catch (Throwable $e) {
    $failures[] = 'El servicio de compras debe funcionar de extremo a extremo: ' . $e->getMessage();
}

try {
    $service->getDraft($tenantBeta, (string) ($draftId ?? ''), 'purchase_app');
    $failures[] = 'No debe permitir leer borradores de compra de otro tenant.';
} catch (Throwable $e) {
    if ((string) $e->getMessage() !== 'PURCHASE_DRAFT_NOT_FOUND') {
        $failures[] = 'Tenant isolation de borradores devolvio error inesperado: ' . $e->getMessage();
    }
}

try {
    $service->getPurchase($tenantBeta, (string) ($purchaseId ?? ''), 'purchase_app');
    $failures[] = 'No debe permitir leer compras de otro tenant.';
} catch (Throwable $e) {
    if ((string) $e->getMessage() !== 'PURCHASE_NOT_FOUND') {
        $failures[] = 'Tenant isolation de compras devolvio error inesperado: ' . $e->getMessage();
    }
}

try {
    $catalog = (new ContractRegistry())->getSkillsCatalog();
    $resolver = new SkillResolver();
    $skillRegistry = new SkillRegistry($catalog);
    $createSkill = $resolver->resolve('crear borrador compra', $skillRegistry, []);
    $finalizeSkill = $resolver->resolve('finalizar compra draft_id=1', $skillRegistry, []);
    if ((string) (($createSkill['selected']['name'] ?? '') ?: '') !== 'purchases_create_draft') {
        $failures[] = 'SkillResolver debe detectar purchases_create_draft.';
    }
    if ((string) (($finalizeSkill['selected']['name'] ?? '') ?: '') !== 'purchases_finalize') {
        $failures[] = 'SkillResolver debe detectar purchases_finalize.';
    }
} catch (Throwable $e) {
    $failures[] = 'El catalogo de compras debe resolver skills correctamente: ' . $e->getMessage();
}

try {
    $sharedEnv = [
        'APP_ENV' => 'local',
        'ALLOW_RUNTIME_SCHEMA' => '1',
        'PROJECT_REGISTRY_DB_PATH' => $tmpDir . '/project_registry.sqlite',
        'DB_DRIVER' => 'sqlite',
        'DB_PATH' => $tmpDir . '/purchases_core.sqlite',
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
            'project_id' => 'purchase_app',
            'session_id' => 'purchase_chat_' . time() . '_create',
            'user_id' => 'buyer_chat',
            'role' => 'admin',
            'is_authenticated' => true,
            'mode' => 'app',
            'channel' => 'test',
            'message' => 'crear borrador compra',
        ],
    ]);
    $chatDraft = is_array(($chatCreate['data']['draft'] ?? null)) ? (array) $chatCreate['data']['draft'] : [];
    $chatDraftId = (string) ($chatDraft['id'] ?? '');
    if ((string) ($chatCreate['status'] ?? '') !== 'success' || (string) (($chatCreate['data']['module_used'] ?? '') ?: '') !== 'purchases' || $chatDraftId === '') {
        $failures[] = 'ChatAgent debe crear borradores de compra via skill + CommandBus.';
    }

    $chatAdd = runChatTurn([
        'project_root' => $tmpProjectRoot,
        'env' => $sharedEnv,
        'payload' => [
            'tenant_id' => $tenantAlpha,
            'project_id' => 'purchase_app',
            'session_id' => 'purchase_chat_' . time() . '_add',
            'user_id' => 'buyer_chat',
            'role' => 'admin',
            'is_authenticated' => true,
            'mode' => 'app',
            'channel' => 'test',
            'message' => 'agregar linea compra draft_id=' . $chatDraftId . ' query="arroz premium" qty=1 unit_cost=3200 tax_rate=5',
        ],
    ]);
    if ((string) ($chatAdd['status'] ?? '') !== 'success' || (string) (($chatAdd['data']['purchases_action'] ?? '') ?: '') !== 'add_draft_line') {
        $failures[] = 'ChatAgent debe agregar lineas de compra.';
    }

    $chatAttach = runChatTurn([
        'project_root' => $tmpProjectRoot,
        'env' => $sharedEnv,
        'payload' => [
            'tenant_id' => $tenantAlpha,
            'project_id' => 'purchase_app',
            'session_id' => 'purchase_chat_' . time() . '_supplier',
            'user_id' => 'buyer_chat',
            'role' => 'admin',
            'is_authenticated' => true,
            'mode' => 'app',
            'channel' => 'test',
            'message' => 'asociar proveedor al borrador draft_id=' . $chatDraftId . ' query="proveedor acme"',
        ],
    ]);
    if ((string) ($chatAttach['status'] ?? '') !== 'success' || (string) (($chatAttach['data']['draft']['supplier_id'] ?? '') ?: '') !== '10') {
        $failures[] = 'ChatAgent debe asociar proveedores a compras.';
    }

    $chatFinalize = runChatTurn([
        'project_root' => $tmpProjectRoot,
        'env' => $sharedEnv,
        'payload' => [
            'tenant_id' => $tenantAlpha,
            'project_id' => 'purchase_app',
            'session_id' => 'purchase_chat_' . time() . '_finalize',
            'user_id' => 'buyer_chat',
            'role' => 'admin',
            'is_authenticated' => true,
            'mode' => 'app',
            'channel' => 'test',
            'message' => 'finalizar compra draft_id=' . $chatDraftId,
        ],
    ]);
    if ((string) ($chatFinalize['status'] ?? '') !== 'success' || trim((string) (($chatFinalize['data']['purchase']['purchase_number'] ?? '') ?: '')) === '') {
        $failures[] = 'ChatAgent debe finalizar compras correctamente.';
    }
} catch (Throwable $e) {
    $failures[] = 'El flujo chat/skills de compras debe pasar: ' . $e->getMessage();
}

try {
    $env = [
        'APP_ENV' => 'local',
        'ALLOW_RUNTIME_SCHEMA' => '1',
        'PROJECT_REGISTRY_DB_PATH' => $tmpDir . '/project_registry.sqlite',
        'DB_DRIVER' => 'sqlite',
        'DB_PATH' => $tmpDir . '/purchases_core.sqlite',
        'DB_NAMESPACE_BY_PROJECT' => '0',
        'PROJECT_STORAGE_MODEL' => 'legacy',
        'DB_STORAGE_MODEL' => 'legacy',
    ];
    $env['SUKI_RUNTIME_ENV_OVERRIDES_JSON'] = json_encode($env, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $session = [
        'auth_user' => [
            'id' => 'api_buyer',
            'tenant_id' => $tenantAlpha,
            'project_id' => 'purchase_app',
            'role' => 'admin',
            'label' => 'API Buyer',
        ],
    ];

    $create = runApiRoute([
        'route' => 'purchases/create-draft',
        'method' => 'POST',
        'payload' => ['currency' => 'COP', 'project_id' => 'purchase_app'],
        'session' => $session,
        'env' => $env,
    ]);
    $createJson = $create['json'];
    $apiDraftId = (string) (($createJson['data']['draft']['id'] ?? '') ?: '');
    if (!is_array($createJson) || (string) ($createJson['status'] ?? '') !== 'success' || $apiDraftId === '') {
        $failures[] = 'API purchases/create-draft debe crear el borrador.';
    }

    $add = runApiRoute([
        'route' => 'purchases/add-draft-line',
        'method' => 'POST',
        'payload' => ['draft_id' => $apiDraftId, 'product_label' => 'Servicio flete', 'qty' => 1, 'unit_cost' => 8000, 'project_id' => 'purchase_app'],
        'session' => $session,
        'env' => $env,
    ]);
    $addJson = $add['json'];
    if (!is_array($addJson) || (string) ($addJson['status'] ?? '') !== 'success') {
        $failures[] = 'API purchases/add-draft-line debe agregar lineas.';
    }

    $finalize = runApiRoute([
        'route' => 'purchases/finalize',
        'method' => 'POST',
        'payload' => ['draft_id' => $apiDraftId, 'project_id' => 'purchase_app'],
        'session' => $session,
        'env' => $env,
    ]);
    $finalizeJson = $finalize['json'];
    $apiPurchaseId = (string) (($finalizeJson['data']['purchase']['id'] ?? '') ?: '');
    if (!is_array($finalizeJson) || (string) ($finalizeJson['status'] ?? '') !== 'success' || $apiPurchaseId === '') {
        $failures[] = 'API purchases/finalize debe registrar la compra.';
    }

    $get = runApiRoute([
        'route' => 'purchases/get-purchase',
        'method' => 'GET',
        'query' => ['purchase_id' => $apiPurchaseId, 'project_id' => 'purchase_app'],
        'session' => $session,
        'env' => $env,
    ]);
    $getJson = $get['json'];
    if (!is_array($getJson) || (string) ($getJson['status'] ?? '') !== 'success' || (string) (($getJson['data']['purchase']['id'] ?? '') ?: '') !== $apiPurchaseId) {
        $failures[] = 'API purchases/get-purchase debe devolver la compra exacta.';
    }
} catch (Throwable $e) {
    $failures[] = 'Las rutas API de compras deben pasar: ' . $e->getMessage();
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
    $pdo->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, sku TEXT, nombre TEXT, activo INTEGER, created_at TEXT, updated_at TEXT)');
    $pdo->exec('CREATE INDEX idx_products_tenant_sku ON products (tenant_id, sku)');
    $pdo->exec('CREATE INDEX idx_products_tenant_nombre ON products (tenant_id, nombre)');
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
