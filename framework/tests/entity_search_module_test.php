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
use App\Core\SkillRegistry;
use App\Core\SkillResolver;

$failures = [];
$tmpDir = FRAMEWORK_ROOT . '/tests/tmp/entity_search_module_' . time() . '_' . random_int(1000, 9999);
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
putenv('DB_PATH=' . $tmpDir . '/entity_search.sqlite');
putenv('DB_NAMESPACE_BY_PROJECT=0');
putenv('PROJECT_STORAGE_MODEL=legacy');
putenv('DB_STORAGE_MODEL=legacy');

$pdo = new PDO('sqlite:' . $tmpDir . '/entity_search.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
Database::setConnection($pdo);

writeEntityContract($tmpProjectRoot, 'products', 'productos', 'products', [
    field('id', 'int', ['primary' => true, 'source' => 'system']),
    field('tenant_id', 'int'),
    field('sku', 'string'),
    field('barcode', 'string'),
    field('nombre', 'string'),
    field('activo', 'bool'),
]);
writeEntityContract($tmpProjectRoot, 'sales', 'ventas', 'sales', [
    field('id', 'int', ['primary' => true, 'source' => 'system']),
    field('tenant_id', 'int'),
    field('numero', 'string'),
    field('descripcion', 'string'),
    field('estado', 'string'),
    field('activo', 'bool'),
    field('fecha', 'datetime'),
]);
writeEntityContract($tmpProjectRoot, 'invoices', 'facturas', 'invoices', [
    field('id', 'int', ['primary' => true, 'source' => 'system']),
    field('tenant_id', 'int'),
    field('numero', 'string'),
    field('estado', 'string'),
    field('fecha', 'datetime'),
    field('total', 'decimal'),
]);

seedSearchTables($pdo);

$tenantAlpha = stableTenantInt('tenant_alpha');
$tenantBeta = stableTenantInt('tenant_beta');
$now = new DateTimeImmutable('2026-03-10 10:30:00');
$yesterday = $now->modify('-1 day');
$twoDaysAgo = $now->modify('-2 days');

$productStmt = $pdo->prepare('INSERT INTO products (id, tenant_id, sku, barcode, nombre, activo, created_at, updated_at) VALUES (:id, :tenant_id, :sku, :barcode, :nombre, :activo, :created_at, :updated_at)');
foreach ([
    ['id' => 1, 'tenant_id' => $tenantAlpha, 'sku' => 'SKU-COCA-350', 'barcode' => '7701111111111', 'nombre' => 'Coca Cola 350', 'activo' => 1, 'created_at' => $twoDaysAgo->format('Y-m-d H:i:s')],
    ['id' => 2, 'tenant_id' => $tenantAlpha, 'sku' => 'SKU-COCA-ZERO', 'barcode' => '7701111111112', 'nombre' => 'Coca Cola Zero 350', 'activo' => 1, 'created_at' => $yesterday->format('Y-m-d H:i:s')],
    ['id' => 3, 'tenant_id' => $tenantAlpha, 'sku' => 'SKU-ARROZ-GR', 'barcode' => '7701111111113', 'nombre' => 'Arroz Grande Premium', 'activo' => 1, 'created_at' => $now->format('Y-m-d H:i:s')],
    ['id' => 4, 'tenant_id' => $tenantBeta, 'sku' => 'SKU-BETA-1', 'barcode' => '7709999999991', 'nombre' => 'Producto Beta', 'activo' => 1, 'created_at' => $now->format('Y-m-d H:i:s')],
] as $row) {
    $productStmt->execute($row + ['updated_at' => $row['created_at']]);
}

$saleStmt = $pdo->prepare('INSERT INTO sales (id, tenant_id, numero, descripcion, estado, activo, fecha, created_at, updated_at) VALUES (:id, :tenant_id, :numero, :descripcion, :estado, :activo, :fecha, :created_at, :updated_at)');
foreach ([
    ['id' => 101, 'tenant_id' => $tenantAlpha, 'numero' => 'VTA-001', 'descripcion' => 'Venta mostrador', 'estado' => 'open', 'activo' => 1, 'fecha' => $yesterday->format('Y-m-d H:i:s')],
    ['id' => 102, 'tenant_id' => $tenantAlpha, 'numero' => 'VTA-002', 'descripcion' => 'Venta ecommerce', 'estado' => 'open', 'activo' => 1, 'fecha' => $now->format('Y-m-d H:i:s')],
    ['id' => 201, 'tenant_id' => $tenantBeta, 'numero' => 'VTA-BETA', 'descripcion' => 'Venta beta', 'estado' => 'open', 'activo' => 1, 'fecha' => $now->format('Y-m-d H:i:s')],
] as $row) {
    $saleStmt->execute($row + ['created_at' => $row['fecha'], 'updated_at' => $row['fecha']]);
}

$invoiceStmt = $pdo->prepare('INSERT INTO invoices (id, tenant_id, numero, estado, fecha, total, created_at, updated_at) VALUES (:id, :tenant_id, :numero, :estado, :fecha, :total, :created_at, :updated_at)');
foreach ([
    ['id' => 301, 'tenant_id' => $tenantAlpha, 'numero' => 'FAC-900', 'estado' => 'open', 'fecha' => $yesterday->format('Y-m-d H:i:s'), 'total' => 120000],
    ['id' => 302, 'tenant_id' => $tenantAlpha, 'numero' => 'FAC-901', 'estado' => 'pending', 'fecha' => $now->format('Y-m-d H:i:s'), 'total' => 98000],
] as $row) {
    $invoiceStmt->execute($row + ['created_at' => $row['fecha'], 'updated_at' => $row['fecha']]);
}

$mediaStmt = $pdo->prepare('INSERT INTO media_files (id, tenant_id, app_id, entity_type, entity_id, file_type, created_at, metadata_json) VALUES (:id, :tenant_id, :app_id, :entity_type, :entity_id, :file_type, :created_at, :metadata_json)');
foreach ([
    ['id' => 401, 'tenant_id' => 'tenant_alpha', 'app_id' => 'search_app', 'entity_type' => 'product', 'entity_id' => '1', 'file_type' => 'image', 'created_at' => $now->format('Y-m-d H:i:s'), 'metadata_json' => json_encode(['original_name' => 'coca-foto.png'])],
    ['id' => 402, 'tenant_id' => 'tenant_api', 'app_id' => 'search_app', 'entity_type' => 'invoice', 'entity_id' => '301', 'file_type' => 'pdf', 'created_at' => $now->format('Y-m-d H:i:s'), 'metadata_json' => json_encode(['original_name' => 'factura-api.pdf'])],
] as $row) {
    $mediaStmt->execute($row);
}

$registry = new EntityRegistry(null, $tmpProjectRoot);
$repository = new EntitySearchRepository($pdo, $registry);
$service = new EntitySearchService($repository, new AuditLogger($pdo), new EntitySearchEventLogger($tmpProjectRoot));

try {
    $exact = $service->search('tenant_alpha', 'SKU-COCA-350', ['limit' => 5]);
    $first = is_array($exact['results'][0] ?? null) ? (array) $exact['results'][0] : [];
    if ((string) ($first['entity_type'] ?? '') !== 'product' || (string) ($first['entity_id'] ?? '') !== '1') {
        $failures[] = 'Debe encontrar el producto exacto por SKU.';
    }

    $partial = $service->search('tenant_alpha', 'arroz grande', ['entity_type' => 'product']);
    $partialFirst = is_array($partial['results'][0] ?? null) ? (array) $partial['results'][0] : [];
    if ((string) ($partialFirst['entity_id'] ?? '') !== '3') {
        $failures[] = 'Debe encontrar el producto por nombre parcial.';
    }

    $latestSale = $service->resolveBestMatch('tenant_alpha', 'ultima venta', ['limit' => 5]);
    $latestSaleItem = is_array($latestSale['result'] ?? null) ? (array) $latestSale['result'] : [];
    if (!(bool) ($latestSale['resolved'] ?? false) || (string) ($latestSaleItem['entity_type'] ?? '') !== 'sale' || (string) ($latestSaleItem['entity_id'] ?? '') !== '102') {
        $failures[] = 'Debe resolver la ultima venta sin inventar.';
    }

    $invoice = $service->resolveBestMatch('tenant_alpha', 'factura FAC-900', ['limit' => 5]);
    $invoiceItem = is_array($invoice['result'] ?? null) ? (array) $invoice['result'] : [];
    if (!(bool) ($invoice['resolved'] ?? false) || (string) ($invoiceItem['entity_type'] ?? '') !== 'invoice' || (string) ($invoiceItem['entity_id'] ?? '') !== '301') {
        $failures[] = 'Debe encontrar la factura por numero.';
    }

    $ambiguous = $service->resolveBestMatch('tenant_alpha', 'coca', ['entity_type' => 'product', 'limit' => 5]);
    if ((bool) ($ambiguous['resolved'] ?? false) || count((array) ($ambiguous['candidates'] ?? [])) < 2) {
        $failures[] = 'Debe devolver candidatos cuando hay resultados ambiguos.';
    }

    $zero = $service->search('tenant_alpha', 'producto inexistente total', ['limit' => 5]);
    if ((int) ($zero['result_count'] ?? 0) !== 0) {
        $failures[] = 'Debe devolver cero resultados cuando no hay coincidencias.';
    }

    $tenantIsolationAlpha = $service->search('tenant_alpha', 'SKU-BETA-1', ['limit' => 5]);
    $tenantIsolationBeta = $service->search('tenant_beta', 'SKU-BETA-1', ['limit' => 5]);
    if ((int) ($tenantIsolationAlpha['result_count'] ?? 0) !== 0 || (string) (($tenantIsolationBeta['results'][0]['entity_id'] ?? '') ?: '') !== '4') {
        $failures[] = 'Debe respetar aislamiento por tenant.';
    }

    $byReference = $service->getByReference('tenant_alpha', 'product', '1');
    if (!is_array($byReference) || (string) ($byReference['entity_id'] ?? '') !== '1') {
        $failures[] = 'getByReference debe devolver la entidad exacta.';
    }

    $media = $service->search('tenant_alpha', 'coca-foto', ['entity_type' => 'media_file', 'limit' => 5], 'search_app');
    if ((string) (($media['results'][0]['entity_type'] ?? '') ?: '') !== 'media_file') {
        $failures[] = 'Debe buscar tambien archivos de media.';
    }
} catch (Throwable $e) {
    $failures[] = 'El servicio de entity search debe funcionar de extremo a extremo: ' . $e->getMessage();
}

try {
    $catalog = (new ContractRegistry())->getSkillsCatalog();
    $resolver = new SkillResolver();
    $registrySkills = new SkillRegistry($catalog);
    $searchSkill = $resolver->resolve('busca el arroz grande', $registrySkills, []);
    $resolveSkill = $resolver->resolve('mira la ultima venta', $registrySkills, []);

    if ((string) (($searchSkill['selected']['name'] ?? '') ?: '') !== 'entity_search') {
        $failures[] = 'SkillResolver debe detectar entity_search.';
    }
    if ((string) (($resolveSkill['selected']['name'] ?? '') ?: '') !== 'entity_resolve') {
        $failures[] = 'SkillResolver debe detectar entity_resolve.';
    }
} catch (Throwable $e) {
    $failures[] = 'El catalogo de skills de entity search debe resolver correctamente: ' . $e->getMessage();
}

try {
    $sharedEnv = [
        'APP_ENV' => 'local',
        'ALLOW_RUNTIME_SCHEMA' => '1',
        'PROJECT_REGISTRY_DB_PATH' => $tmpDir . '/project_registry.sqlite',
        'DB_DRIVER' => 'sqlite',
        'DB_PATH' => $tmpDir . '/entity_search.sqlite',
        'DB_NAMESPACE_BY_PROJECT' => '0',
        'PROJECT_STORAGE_MODEL' => 'legacy',
        'DB_STORAGE_MODEL' => 'legacy',
    ];
    $sharedEnv['SUKI_RUNTIME_ENV_OVERRIDES_JSON'] = json_encode($sharedEnv, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $chatSearch = runChatTurn([
        'project_root' => $tmpProjectRoot,
        'env' => $sharedEnv,
        'payload' => [
            'tenant_id' => 'tenant_alpha',
            'project_id' => 'search_app',
            'session_id' => 'entity_search_chat_' . time() . '_1',
            'user_id' => 'operator_search',
            'role' => 'admin',
            'is_authenticated' => true,
            'mode' => 'app',
            'channel' => 'test',
            'message' => 'busca el arroz grande',
        ],
    ]);
    $chatSearchData = is_array($chatSearch['data'] ?? null) ? (array) $chatSearch['data'] : [];
    if ((string) ($chatSearch['status'] ?? '') !== 'success' || (string) ($chatSearchData['module_used'] ?? '') !== 'entity_search' || (string) ($chatSearchData['entity_search_action'] ?? '') !== 'search' || count((array) ($chatSearchData['items'] ?? [])) < 1) {
        $failures[] = 'ChatAgent debe ejecutar entity_search via skill + CommandBus.';
    }

    $chatResolve = runChatTurn([
        'project_root' => $tmpProjectRoot,
        'env' => $sharedEnv,
        'payload' => [
            'tenant_id' => 'tenant_alpha',
            'project_id' => 'search_app',
            'session_id' => 'entity_search_chat_' . time() . '_2',
            'user_id' => 'operator_search',
            'role' => 'admin',
            'is_authenticated' => true,
            'mode' => 'app',
            'channel' => 'test',
            'message' => 'mira la ultima venta',
        ],
    ]);
    $chatResolveData = is_array($chatResolve['data'] ?? null) ? (array) $chatResolve['data'] : [];
    $chatResolvedItem = is_array($chatResolveData['result'] ?? null) ? (array) $chatResolveData['result'] : [];
    if ((string) ($chatResolve['status'] ?? '') !== 'success' || (string) ($chatResolveData['entity_search_action'] ?? '') !== 'resolve' || !(bool) ($chatResolveData['resolved'] ?? false) || (string) ($chatResolvedItem['entity_type'] ?? '') !== 'sale') {
        $failures[] = 'ChatAgent debe ejecutar entity_resolve y devolver la venta correcta.';
    }
} catch (Throwable $e) {
    $failures[] = 'El flujo chat/skills de entity search debe pasar: ' . $e->getMessage();
}

try {
    $env = [
        'APP_ENV' => 'local',
        'ALLOW_RUNTIME_SCHEMA' => '1',
        'PROJECT_REGISTRY_DB_PATH' => $tmpDir . '/project_registry.sqlite',
        'DB_DRIVER' => 'sqlite',
        'DB_PATH' => $tmpDir . '/entity_search.sqlite',
        'DB_NAMESPACE_BY_PROJECT' => '0',
        'PROJECT_STORAGE_MODEL' => 'legacy',
        'DB_STORAGE_MODEL' => 'legacy',
    ];
    $env['SUKI_RUNTIME_ENV_OVERRIDES_JSON'] = json_encode($env, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $session = [
        'auth_user' => [
            'id' => 'api_search_user',
            'tenant_id' => 'tenant_api',
            'project_id' => 'search_app',
            'role' => 'admin',
            'label' => 'API Search',
        ],
    ];

    $searchApi = runApiRoute([
        'route' => 'entity-search/search',
        'method' => 'GET',
        'query' => ['query' => 'factura-api', 'entity_type' => 'media_file', 'project_id' => 'search_app'],
        'session' => $session,
        'env' => $env,
    ]);
    if (!is_array($searchApi['json']) || (string) ($searchApi['json']['status'] ?? '') !== 'success' || (int) ($searchApi['json']['data']['result_count'] ?? 0) < 1) {
        $failures[] = 'API entity-search/search debe devolver resultados.';
    }

    $resolveApi = runApiRoute([
        'route' => 'entity-search/resolve',
        'method' => 'GET',
        'query' => ['query' => 'factura-api', 'entity_type' => 'media_file', 'project_id' => 'search_app'],
        'session' => $session,
        'env' => $env,
    ]);
    $resolveJson = $resolveApi['json'];
    if (!is_array($resolveJson) || (string) ($resolveJson['status'] ?? '') !== 'success' || !(bool) ($resolveJson['data']['resolved'] ?? false)) {
        $failures[] = 'API entity-search/resolve debe resolver una coincidencia unica.';
    }

    $getApi = runApiRoute([
        'route' => 'entity-search/get',
        'method' => 'GET',
        'query' => ['entity_type' => 'media_file', 'entity_id' => '402', 'project_id' => 'search_app'],
        'session' => $session,
        'env' => $env,
    ]);
    if (!is_array($getApi['json']) || (string) ($getApi['json']['status'] ?? '') !== 'success' || (string) (($getApi['json']['data']['result']['entity_id'] ?? '') ?: '') !== '402') {
        $failures[] = 'API entity-search/get debe devolver la referencia exacta.';
    }
} catch (Throwable $e) {
    $failures[] = 'Las rutas API de entity search deben pasar: ' . $e->getMessage();
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
        activo INTEGER,
        created_at TEXT,
        updated_at TEXT
    )');
    $pdo->exec('CREATE INDEX idx_products_tenant_sku ON products (tenant_id, sku)');
    $pdo->exec('CREATE INDEX idx_products_tenant_nombre ON products (tenant_id, nombre)');

    $pdo->exec('CREATE TABLE sales (
        id INTEGER PRIMARY KEY,
        tenant_id INTEGER NOT NULL,
        numero TEXT,
        descripcion TEXT,
        estado TEXT,
        activo INTEGER,
        fecha TEXT,
        created_at TEXT,
        updated_at TEXT
    )');
    $pdo->exec('CREATE INDEX idx_sales_tenant_numero ON sales (tenant_id, numero)');
    $pdo->exec('CREATE INDEX idx_sales_tenant_fecha ON sales (tenant_id, fecha)');

    $pdo->exec('CREATE TABLE invoices (
        id INTEGER PRIMARY KEY,
        tenant_id INTEGER NOT NULL,
        numero TEXT,
        estado TEXT,
        fecha TEXT,
        total REAL,
        created_at TEXT,
        updated_at TEXT
    )');
    $pdo->exec('CREATE INDEX idx_invoices_tenant_numero ON invoices (tenant_id, numero)');
    $pdo->exec('CREATE INDEX idx_invoices_tenant_fecha ON invoices (tenant_id, fecha)');

    $pdo->exec('CREATE TABLE media_files (
        id INTEGER PRIMARY KEY,
        tenant_id TEXT NOT NULL,
        app_id TEXT NULL,
        entity_type TEXT NOT NULL,
        entity_id TEXT NOT NULL,
        file_type TEXT NOT NULL,
        created_at TEXT NOT NULL,
        metadata_json TEXT NULL
    )');
    $pdo->exec('CREATE INDEX idx_media_files_tenant_created ON media_files (tenant_id, created_at)');
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
