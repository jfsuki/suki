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
$tmpDir = FRAMEWORK_ROOT . '/tests/tmp/pos_core_module_' . time() . '_' . random_int(1000, 9999);
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
putenv('DB_PATH=' . $tmpDir . '/pos_core.sqlite');
putenv('DB_NAMESPACE_BY_PROJECT=0');
putenv('PROJECT_STORAGE_MODEL=legacy');
putenv('DB_STORAGE_MODEL=legacy');

$pdo = new PDO('sqlite:' . $tmpDir . '/pos_core.sqlite');
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
    ['id' => 12, 'tenant_id' => $tenantAlphaInt, 'documento' => 'CC-002', 'nombre' => 'Carlos Cliente', 'activo' => 1, 'created_at' => $now->format('Y-m-d H:i:s')],
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
    if ($draftId === '' || (string) ($draft['status'] ?? '') !== 'open') {
        $failures[] = 'Debe crear un borrador POS abierto.';
    }

    $draft = $service->addLineToDraft([
        'tenant_id' => $tenantAlpha,
        'app_id' => 'pos_app',
        'draft_id' => $draftId,
        'query' => 'SKU-COCA-350',
        'qty' => 2,
    ]);
    $lines = is_array($draft['lines'] ?? null) ? (array) $draft['lines'] : [];
    $firstLine = is_array($lines[0] ?? null) ? (array) $lines[0] : [];
    if (count($lines) !== 1 || (string) ($firstLine['product_id'] ?? '') !== '1') {
        $failures[] = 'Debe agregar la linea del producto exacto por SKU.';
    }
    if ((float) ($draft['subtotal'] ?? 0) !== 7000.0 || (float) ($draft['tax_total'] ?? 0) !== 1330.0 || (float) ($draft['total'] ?? 0) !== 8330.0) {
        $failures[] = 'Debe recalcular subtotal, impuesto y total del borrador.';
    }

    $draft = $service->removeLineFromDraft($tenantAlpha, $draftId, (string) ($firstLine['id'] ?? ''), 'pos_app');
    if ((float) ($draft['subtotal'] ?? 999) !== 0.0 || count((array) ($draft['lines'] ?? [])) !== 0) {
        $failures[] = 'Debe eliminar la linea y recalcular totales a cero.';
    }

    $draft = $service->addLineToDraft([
        'tenant_id' => $tenantAlpha,
        'app_id' => 'pos_app',
        'draft_id' => $draftId,
        'query' => 'arroz grande',
        'qty' => 1,
    ]);
    $draft = $service->attachCustomerToDraft([
        'tenant_id' => $tenantAlpha,
        'app_id' => 'pos_app',
        'draft_id' => $draftId,
        'query' => 'ana cliente',
    ]);
    if ((string) ($draft['customer_id'] ?? '') !== '11') {
        $failures[] = 'Debe asociar el cliente resuelto por entity search.';
    }

    $openDrafts = $service->listOpenDrafts($tenantAlpha, 'pos_app', 10);
    if (count($openDrafts) < 1 || (string) ($openDrafts[0]['id'] ?? '') !== $draftId) {
        $failures[] = 'Debe listar borradores POS abiertos del tenant.';
    }

    $checkout = $service->prepareSaleForCheckout($tenantAlpha, $draftId, 'pos_app');
    if (!(bool) ($checkout['checkout_ready'] ?? false)) {
        $failures[] = 'prepareSaleForCheckout debe marcar el borrador listo cuando tiene lineas.';
    }
} catch (Throwable $e) {
    $failures[] = 'El servicio POS debe funcionar de extremo a extremo: ' . $e->getMessage();
}

try {
    $service->getDraft($tenantBeta, (string) ($draftId ?? ''), 'pos_app');
    $failures[] = 'No debe permitir leer borradores de otro tenant.';
} catch (Throwable $e) {
    if ((string) $e->getMessage() !== 'POS_DRAFT_NOT_FOUND') {
        $failures[] = 'Tenant isolation POS devolvio un error inesperado: ' . $e->getMessage();
    }
}

try {
    $service->addLineToDraft([
        'tenant_id' => $tenantAlpha,
        'app_id' => 'pos_app',
        'draft_id' => (string) ($draftId ?? ''),
        'query' => 'producto inexistente total',
        'qty' => 1,
    ]);
    $failures[] = 'Debe fallar cuando el producto no existe.';
} catch (Throwable $e) {
    if ((string) $e->getMessage() !== 'POS_PRODUCT_NOT_FOUND') {
        $failures[] = 'El manejo de producto invalido devolvio un error inesperado: ' . $e->getMessage();
    }
}

try {
    $service->addLineToDraft([
        'tenant_id' => $tenantAlpha,
        'app_id' => 'pos_app',
        'draft_id' => (string) ($draftId ?? ''),
        'query' => 'coca',
        'qty' => 1,
    ]);
    $failures[] = 'Debe pedir aclaracion cuando entity search devuelve varios productos.';
} catch (Throwable $e) {
    if ((string) $e->getMessage() !== 'POS_PRODUCT_AMBIGUOUS') {
        $failures[] = 'La ruta ambigua de entity search devolvio un error inesperado: ' . $e->getMessage();
    }
}

try {
    $catalog = (new ContractRegistry())->getSkillsCatalog();
    $resolver = new SkillResolver();
    $skillRegistry = new SkillRegistry($catalog);
    $createSkill = $resolver->resolve('crear borrador pos', $skillRegistry, []);
    $addSkill = $resolver->resolve('agregar producto al borrador draft_id=1 query="arroz grande"', $skillRegistry, []);
    $listSkill = $resolver->resolve('listar borradores abiertos pos', $skillRegistry, []);

    if ((string) (($createSkill['selected']['name'] ?? '') ?: '') !== 'pos_create_draft') {
        $failures[] = 'SkillResolver debe detectar pos_create_draft.';
    }
    if ((string) (($addSkill['selected']['name'] ?? '') ?: '') !== 'pos_add_draft_line') {
        $failures[] = 'SkillResolver debe detectar pos_add_draft_line.';
    }
    if ((string) (($listSkill['selected']['name'] ?? '') ?: '') !== 'pos_list_open_drafts') {
        $failures[] = 'SkillResolver debe detectar pos_list_open_drafts.';
    }
} catch (Throwable $e) {
    $failures[] = 'El catalogo POS debe resolver skills correctamente: ' . $e->getMessage();
}

try {
    $sharedEnv = [
        'APP_ENV' => 'local',
        'ALLOW_RUNTIME_SCHEMA' => '1',
        'PROJECT_REGISTRY_DB_PATH' => $tmpDir . '/project_registry.sqlite',
        'DB_DRIVER' => 'sqlite',
        'DB_PATH' => $tmpDir . '/pos_core.sqlite',
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
            'session_id' => 'pos_chat_' . time() . '_create',
            'user_id' => 'operator_pos',
            'role' => 'admin',
            'is_authenticated' => true,
            'mode' => 'app',
            'channel' => 'test',
            'message' => 'crear borrador pos',
        ],
    ]);
    $chatCreateData = is_array($chatCreate['data'] ?? null) ? (array) $chatCreate['data'] : [];
    $chatDraft = is_array($chatCreateData['draft'] ?? null) ? (array) $chatCreateData['draft'] : [];
    $chatDraftId = (string) ($chatDraft['id'] ?? '');
    if ((string) ($chatCreate['status'] ?? '') !== 'success' || (string) ($chatCreateData['module_used'] ?? '') !== 'pos' || (string) ($chatCreateData['pos_action'] ?? '') !== 'create_draft' || $chatDraftId === '') {
        $failures[] = 'ChatAgent debe crear borradores POS via skill + CommandBus.';
    }

    $chatAdd = runChatTurn([
        'project_root' => $tmpProjectRoot,
        'env' => $sharedEnv,
        'payload' => [
            'tenant_id' => $tenantAlpha,
            'project_id' => 'pos_app',
            'session_id' => 'pos_chat_' . time() . '_add',
            'user_id' => 'operator_pos',
            'role' => 'admin',
            'is_authenticated' => true,
            'mode' => 'app',
            'channel' => 'test',
            'message' => 'agregar producto al borrador draft_id=' . $chatDraftId . ' query="arroz grande" qty=1',
        ],
    ]);
    $chatAddData = is_array($chatAdd['data'] ?? null) ? (array) $chatAdd['data'] : [];
    if ((string) ($chatAdd['status'] ?? '') !== 'success' || (string) ($chatAddData['pos_action'] ?? '') !== 'add_draft_line' || count((array) ($chatAddData['draft']['lines'] ?? [])) < 1) {
        $failures[] = 'ChatAgent debe agregar lineas POS usando entity search.';
    }

    $chatAttach = runChatTurn([
        'project_root' => $tmpProjectRoot,
        'env' => $sharedEnv,
        'payload' => [
            'tenant_id' => $tenantAlpha,
            'project_id' => 'pos_app',
            'session_id' => 'pos_chat_' . time() . '_attach',
            'user_id' => 'operator_pos',
            'role' => 'admin',
            'is_authenticated' => true,
            'mode' => 'app',
            'channel' => 'test',
            'message' => 'asociar cliente al borrador draft_id=' . $chatDraftId . ' query="ana cliente"',
        ],
    ]);
    $chatAttachData = is_array($chatAttach['data'] ?? null) ? (array) $chatAttach['data'] : [];
    if ((string) ($chatAttach['status'] ?? '') !== 'success' || (string) ($chatAttachData['pos_action'] ?? '') !== 'attach_customer' || (string) (($chatAttachData['draft']['customer_id'] ?? '') ?: '') !== '11') {
        $failures[] = 'ChatAgent debe asociar clientes POS correctamente.';
    }

    $chatList = runChatTurn([
        'project_root' => $tmpProjectRoot,
        'env' => $sharedEnv,
        'payload' => [
            'tenant_id' => $tenantAlpha,
            'project_id' => 'pos_app',
            'session_id' => 'pos_chat_' . time() . '_list',
            'user_id' => 'operator_pos',
            'role' => 'admin',
            'is_authenticated' => true,
            'mode' => 'app',
            'channel' => 'test',
            'message' => 'listar borradores abiertos pos',
        ],
    ]);
    $chatListData = is_array($chatList['data'] ?? null) ? (array) $chatList['data'] : [];
    if ((string) ($chatList['status'] ?? '') !== 'success' || (string) ($chatListData['pos_action'] ?? '') !== 'list_open_drafts' || count((array) ($chatListData['items'] ?? [])) < 1) {
        $failures[] = 'ChatAgent debe listar borradores POS abiertos.';
    }
} catch (Throwable $e) {
    $failures[] = 'El flujo chat/skills de POS debe pasar: ' . $e->getMessage();
}

try {
    $env = [
        'APP_ENV' => 'local',
        'ALLOW_RUNTIME_SCHEMA' => '1',
        'PROJECT_REGISTRY_DB_PATH' => $tmpDir . '/project_registry.sqlite',
        'DB_DRIVER' => 'sqlite',
        'DB_PATH' => $tmpDir . '/pos_core.sqlite',
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

    $create = runApiRoute([
        'route' => 'pos/create-draft',
        'method' => 'POST',
        'payload' => [
            'currency' => 'COP',
            'project_id' => 'pos_app',
        ],
        'session' => $session,
        'env' => $env,
    ]);
    $createJson = $create['json'];
    $apiDraftId = (string) (($createJson['data']['draft']['id'] ?? '') ?: '');
    if (!is_array($createJson) || (string) ($createJson['status'] ?? '') !== 'success' || $apiDraftId === '') {
        $failures[] = 'API pos/create-draft debe crear el borrador.';
    }

    $get = runApiRoute([
        'route' => 'pos/get-draft',
        'method' => 'GET',
        'query' => [
            'draft_id' => $apiDraftId,
            'project_id' => 'pos_app',
        ],
        'session' => $session,
        'env' => $env,
    ]);
    $getJson = $get['json'];
    if (!is_array($getJson) || (string) ($getJson['status'] ?? '') !== 'success' || (string) (($getJson['data']['draft']['id'] ?? '') ?: '') !== $apiDraftId) {
        $failures[] = 'API pos/get-draft debe devolver el borrador exacto.';
    }

    $list = runApiRoute([
        'route' => 'pos/list-open-drafts',
        'method' => 'GET',
        'query' => ['project_id' => 'pos_app'],
        'session' => $session,
        'env' => $env,
    ]);
    $listJson = $list['json'];
    if (!is_array($listJson) || (string) ($listJson['status'] ?? '') !== 'success' || (int) ($listJson['data']['result_count'] ?? 0) < 1) {
        $failures[] = 'API pos/list-open-drafts debe listar borradores abiertos.';
    }
} catch (Throwable $e) {
    $failures[] = 'Las rutas API POS deben pasar: ' . $e->getMessage();
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
