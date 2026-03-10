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
$tmpDir = FRAMEWORK_ROOT . '/tests/tmp/pos_products_pricing_barcode_' . time() . '_' . random_int(1000, 9999);
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
putenv('DB_PATH=' . $tmpDir . '/pos_products.sqlite');
putenv('DB_NAMESPACE_BY_PROJECT=0');
putenv('PROJECT_STORAGE_MODEL=legacy');
putenv('DB_STORAGE_MODEL=legacy');

$pdo = new PDO('sqlite:' . $tmpDir . '/pos_products.sqlite');
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
    ['id' => 2, 'tenant_id' => $tenantAlphaInt, 'sku' => 'SKU-COCA-ZERO', 'barcode' => '7701111111112', 'nombre' => 'Coca Cola Zero 350', 'precio_venta' => 3600, 'iva_rate' => 19, 'activo' => 1, 'created_at' => $now->format('Y-m-d H:i:s')],
    ['id' => 3, 'tenant_id' => $tenantAlphaInt, 'sku' => 'SKU-ARROZ-GR', 'barcode' => '7701111111113', 'nombre' => 'Arroz Grande Premium', 'precio_venta' => 4200, 'iva_rate' => 5, 'activo' => 1, 'created_at' => $now->format('Y-m-d H:i:s')],
    ['id' => 4, 'tenant_id' => $tenantBetaInt, 'sku' => 'SKU-BETA-1', 'barcode' => '7709999999991', 'nombre' => 'Producto Beta', 'precio_venta' => 9999, 'iva_rate' => 0, 'activo' => 1, 'created_at' => $now->format('Y-m-d H:i:s')],
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
    if ($draftId === '') {
        $failures[] = 'Debe crear un borrador POS para pruebas de pricing.';
    }

    $byBarcode = $service->resolveProductForPOS([
        'tenant_id' => $tenantAlpha,
        'app_id' => 'pos_app',
        'barcode' => '7701111111111',
    ]);
    if (!(bool) ($byBarcode['resolved'] ?? false) || (string) (($byBarcode['result']['entity_id'] ?? '') ?: '') !== '1' || (string) ($byBarcode['matched_by'] ?? '') !== 'barcode') {
        $failures[] = 'Debe resolver producto por barcode exacto con prioridad absoluta.';
    }

    $bySku = $service->resolveProductForPOS([
        'tenant_id' => $tenantAlpha,
        'app_id' => 'pos_app',
        'sku' => 'SKU-COCA-ZERO',
    ]);
    if (!(bool) ($bySku['resolved'] ?? false) || (string) (($bySku['result']['entity_id'] ?? '') ?: '') !== '2' || (string) ($bySku['matched_by'] ?? '') !== 'sku') {
        $failures[] = 'Debe resolver producto por SKU exacto.';
    }

    $byName = $service->resolveProductForPOS([
        'tenant_id' => $tenantAlpha,
        'app_id' => 'pos_app',
        'query' => 'Arroz Grande Premium',
    ]);
    if (!(bool) ($byName['resolved'] ?? false) || (string) (($byName['result']['entity_id'] ?? '') ?: '') !== '3' || (string) ($byName['matched_by'] ?? '') !== 'exact_name') {
        $failures[] = 'Debe resolver producto por nombre exacto.';
    }

    $candidates = $service->getProductCandidatesForPOS([
        'tenant_id' => $tenantAlpha,
        'app_id' => 'pos_app',
        'query' => 'coca',
        'limit' => 5,
    ]);
    if ((int) ($candidates['result_count'] ?? 0) < 2) {
        $failures[] = 'Debe listar candidatos por nombre parcial.';
    }

    $ambiguous = $service->resolveProductForPOS([
        'tenant_id' => $tenantAlpha,
        'app_id' => 'pos_app',
        'query' => 'coca',
        'limit' => 5,
    ]);
    if ((bool) ($ambiguous['resolved'] ?? false) || count((array) ($ambiguous['candidates'] ?? [])) < 2 || !(bool) ($ambiguous['needs_clarification'] ?? false)) {
        $failures[] = 'Debe devolver candidatos cuando la referencia de producto es ambigua.';
    }

    $notFound = $service->resolveProductForPOS([
        'tenant_id' => $tenantAlpha,
        'app_id' => 'pos_app',
        'query' => 'producto imposible total',
    ]);
    if ((bool) ($notFound['resolved'] ?? false) || count((array) ($notFound['candidates'] ?? [])) !== 0) {
        $failures[] = 'Debe devolver resultado vacio y seguro cuando no existe el producto.';
    }

    $byReference = $service->resolveProductForPOS([
        'tenant_id' => $tenantAlpha,
        'app_id' => 'pos_app',
        'product_id' => '2',
    ]);
    if (!(bool) ($byReference['resolved'] ?? false) || (string) (($byReference['result']['entity_id'] ?? '') ?: '') !== '2') {
        $failures[] = 'Debe resolver productos por referencia usando la ruta compartida de entity search.';
    }

    $draft = $service->addLineByProductReference([
        'tenant_id' => $tenantAlpha,
        'app_id' => 'pos_app',
        'draft_id' => $draftId,
        'barcode' => '7701111111111',
        'qty' => 1,
    ]);
    $lines = is_array($draft['lines'] ?? null) ? (array) $draft['lines'] : [];
    $line = is_array($lines[0] ?? null) ? (array) $lines[0] : [];
    if ((string) ($line['product_id'] ?? '') !== '1') {
        $failures[] = 'Debe agregar linea por barcode exacto.';
    }
    if ((float) ($line['base_price'] ?? 0) !== 3500.0 || (float) ($line['effective_unit_price'] ?? 0) !== 3500.0 || (float) ($line['line_subtotal'] ?? 0) !== 3500.0 || (float) ($line['line_tax'] ?? 0) !== 665.0 || (float) ($line['line_total'] ?? 0) !== 4165.0) {
        $failures[] = 'Debe calcular pricing de linea con subtotal, impuesto y total.';
    }
    if ((float) ($draft['subtotal'] ?? 0) !== 3500.0 || (float) ($draft['tax_total'] ?? 0) !== 665.0 || (float) ($draft['total'] ?? 0) !== 4165.0) {
        $failures[] = 'Debe recalcular totales del borrador despues de agregar por barcode.';
    }

    $repriced = $service->repriceDraft($tenantAlpha, $draftId, 'pos_app', [
        'line_id' => (string) ($line['id'] ?? ''),
        'qty' => 3,
    ]);
    $repricedLine = is_array(($repriced['lines'][0] ?? null)) ? (array) $repriced['lines'][0] : [];
    if ((float) ($repricedLine['line_subtotal'] ?? 0) !== 10500.0 || (float) ($repricedLine['line_tax'] ?? 0) !== 1995.0 || (float) ($repricedLine['line_total'] ?? 0) !== 12495.0 || (float) ($repriced['total'] ?? 0) !== 12495.0) {
        $failures[] = 'Debe recalcular pricing del borrador al cambiar cantidad.';
    }

    $isolated = $service->resolveProductForPOS([
        'tenant_id' => $tenantBeta,
        'app_id' => 'pos_app',
        'barcode' => '7701111111111',
    ]);
    if ((bool) ($isolated['resolved'] ?? false)) {
        $failures[] = 'No debe filtrar productos de otro tenant.';
    }
} catch (Throwable $e) {
    $failures[] = 'El servicio POS de productos/pricing debe funcionar de extremo a extremo: ' . $e->getMessage();
}

try {
    $catalog = (new ContractRegistry())->getSkillsCatalog();
    $resolver = new SkillResolver();
    $skillRegistry = new SkillRegistry($catalog);
    $findSkill = $resolver->resolve('buscar producto pos barcode=7701111111111', $skillRegistry, []);
    if ((string) (($findSkill['selected']['name'] ?? '') ?: '') !== 'pos_find_product') {
        $failures[] = 'SkillResolver debe detectar pos_find_product.';
    }
} catch (Throwable $e) {
    $failures[] = 'El catalogo de skills POS nuevas debe resolver pos_find_product: ' . $e->getMessage();
}

try {
    $sharedEnv = [
        'APP_ENV' => 'local',
        'ALLOW_RUNTIME_SCHEMA' => '1',
        'PROJECT_REGISTRY_DB_PATH' => $tmpDir . '/project_registry.sqlite',
        'DB_DRIVER' => 'sqlite',
        'DB_PATH' => $tmpDir . '/pos_products.sqlite',
        'DB_NAMESPACE_BY_PROJECT' => '0',
        'PROJECT_STORAGE_MODEL' => 'legacy',
        'DB_STORAGE_MODEL' => 'legacy',
    ];
    $sharedEnv['SUKI_RUNTIME_ENV_OVERRIDES_JSON'] = json_encode($sharedEnv, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $chatFind = runChatTurn([
        'project_root' => $tmpProjectRoot,
        'env' => $sharedEnv,
        'payload' => [
            'tenant_id' => $tenantAlpha,
            'project_id' => 'pos_app',
            'session_id' => 'pos_products_' . time() . '_find',
            'user_id' => 'operator_pos',
            'role' => 'admin',
            'is_authenticated' => true,
            'mode' => 'app',
            'channel' => 'test',
            'message' => 'buscar producto pos query="coca"',
        ],
    ]);
    $chatFindData = is_array($chatFind['data'] ?? null) ? (array) $chatFind['data'] : [];
    if ((string) ($chatFind['status'] ?? '') !== 'success' || (string) ($chatFindData['pos_action'] ?? '') !== 'find_product' || !(bool) ($chatFindData['needs_clarification'] ?? false)) {
        $failures[] = 'ChatAgent debe devolver aclaracion segura para productos POS ambiguos.';
    }
} catch (Throwable $e) {
    $failures[] = 'El flujo chat POS de producto debe pasar: ' . $e->getMessage();
}

try {
    $env = [
        'APP_ENV' => 'local',
        'ALLOW_RUNTIME_SCHEMA' => '1',
        'PROJECT_REGISTRY_DB_PATH' => $tmpDir . '/project_registry.sqlite',
        'DB_DRIVER' => 'sqlite',
        'DB_PATH' => $tmpDir . '/pos_products.sqlite',
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

    $reprice = runApiRoute([
        'route' => 'pos/reprice-draft',
        'method' => 'POST',
        'payload' => [
            'draft_id' => (string) ($draftId ?? ''),
            'line_id' => (string) (($repricedLine['id'] ?? $line['id'] ?? '') ?: ''),
            'qty' => 2,
            'project_id' => 'pos_app',
        ],
        'session' => $session,
        'env' => $env,
    ]);
    $repriceJson = $reprice['json'];
    if (!is_array($repriceJson) || (string) ($repriceJson['status'] ?? '') !== 'success' || (float) (($repriceJson['data']['draft']['total'] ?? 0)) !== 8330.0) {
        $failures[] = 'API pos/reprice-draft debe recalcular totales del borrador.';
    }
} catch (Throwable $e) {
    $failures[] = 'La API POS de producto debe pasar: ' . $e->getMessage();
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
    $pdo->exec('CREATE INDEX idx_products_tenant_barcode ON products (tenant_id, barcode)');
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
