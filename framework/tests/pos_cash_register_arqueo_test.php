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
$tmpDir = FRAMEWORK_ROOT . '/tests/tmp/pos_cash_register_arqueo_' . time() . '_' . random_int(1000, 9999);
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
putenv('DB_PATH=' . $tmpDir . '/pos_cash.sqlite');
putenv('DB_NAMESPACE_BY_PROJECT=0');
putenv('PROJECT_STORAGE_MODEL=legacy');
putenv('DB_STORAGE_MODEL=legacy');

$pdo = new PDO('sqlite:' . $tmpDir . '/pos_cash.sqlite');
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
    $opened = $service->openCashRegister([
        'tenant_id' => $tenantAlpha,
        'app_id' => 'pos_app',
        'cash_register_id' => 'CAJA-1',
        'opening_amount' => 10000,
        'opened_by_user_id' => 'cashier_1',
        'notes' => 'turno manana',
    ]);
    $sessionId = (string) ($opened['id'] ?? '');
    if ($sessionId === '' || (string) ($opened['status'] ?? '') !== 'open' || (float) ($opened['opening_amount'] ?? 0) !== 10000.0) {
        $failures[] = 'Debe abrir una sesion de caja POS con monto inicial y estado open.';
    }

    $loadedOpen = $service->getOpenCashSession($tenantAlpha, 'CAJA-1', 'pos_app');
    if ((string) ($loadedOpen['id'] ?? '') !== $sessionId) {
        $failures[] = 'Debe recuperar la sesion de caja POS abierta por cash_register_id.';
    }

    try {
        $service->openCashRegister([
            'tenant_id' => $tenantAlpha,
            'app_id' => 'pos_app',
            'cash_register_id' => 'CAJA-1',
            'opening_amount' => 5000,
        ]);
        $failures[] = 'Debe bloquear apertura duplicada de la misma caja POS.';
    } catch (Throwable $e) {
        if ((string) $e->getMessage() !== 'POS_CASH_REGISTER_ALREADY_OPEN') {
            $failures[] = 'La apertura duplicada devolvio un error inesperado: ' . $e->getMessage();
        }
    }

    $draft = $service->createDraft([
        'tenant_id' => $tenantAlpha,
        'app_id' => 'pos_app',
        'session_id' => $sessionId,
        'currency' => 'COP',
    ]);
    $draft = $service->addLineByProductReference([
        'tenant_id' => $tenantAlpha,
        'app_id' => 'pos_app',
        'draft_id' => (string) ($draft['id'] ?? ''),
        'barcode' => '7701111111111',
        'qty' => 2,
    ]);
    $finalized = $service->finalizeDraftSale([
        'tenant_id' => $tenantAlpha,
        'app_id' => 'pos_app',
        'draft_id' => (string) ($draft['id'] ?? ''),
        'requested_by_user_id' => 'cashier_1',
    ]);
    $sale = is_array($finalized['sale'] ?? null) ? (array) $finalized['sale'] : [];
    if ((string) ($sale['session_id'] ?? '') !== $sessionId || (float) ($sale['total'] ?? 0) !== 8330.0) {
        $failures[] = 'La venta POS finalizada debe quedar asociada a la sesion de caja.';
    }

    $summary = $service->buildCashSummary($tenantAlpha, $sessionId, 'pos_app');
    if ((int) ($summary['sales_count'] ?? 0) !== 1 || (float) ($summary['sales_total'] ?? 0) !== 8330.0 || (float) ($summary['expected_cash_amount'] ?? 0) !== 18330.0) {
        $failures[] = 'El arqueo POS debe calcular ventas, total esperado y monto inicial.';
    }
    $sales = is_array($summary['sales'] ?? null) ? (array) $summary['sales'] : [];
    if (count($sales) !== 1 || (string) (($sales[0]['sale_id'] ?? '') ?: '') !== (string) ($sale['id'] ?? '')) {
        $failures[] = 'El arqueo POS debe listar las ventas vinculadas a la sesion.';
    }

    $closed = $service->closeCashRegister([
        'tenant_id' => $tenantAlpha,
        'app_id' => 'pos_app',
        'session_id' => $sessionId,
        'counted_cash_amount' => 18500,
        'closed_by_user_id' => 'cashier_2',
        'notes' => 'arqueo cierre',
    ]);
    $closedSession = is_array($closed['session'] ?? null) ? (array) $closed['session'] : [];
    $closedSummary = is_array($closed['summary'] ?? null) ? (array) $closed['summary'] : [];
    if ((string) ($closedSession['status'] ?? '') !== 'closed' || (float) ($closedSession['difference_amount'] ?? 0) !== 170.0 || (float) ($closedSummary['difference_amount'] ?? 0) !== 170.0) {
        $failures[] = 'El cierre de caja POS debe persistir monto contado, esperado y diferencia.';
    }

    try {
        $service->closeCashRegister([
            'tenant_id' => $tenantAlpha,
            'app_id' => 'pos_app',
            'session_id' => $sessionId,
            'counted_cash_amount' => 18500,
        ]);
        $failures[] = 'Debe bloquear el cierre repetido de la misma sesion POS.';
    } catch (Throwable $e) {
        if ((string) $e->getMessage() !== 'POS_CASH_SESSION_ALREADY_CLOSED') {
            $failures[] = 'El cierre repetido devolvio un error inesperado: ' . $e->getMessage();
        }
    }

    $openedSecond = $service->openCashRegister([
        'tenant_id' => $tenantAlpha,
        'app_id' => 'pos_app',
        'cash_register_id' => 'CAJA-2',
        'opening_amount' => 2000,
        'opened_by_user_id' => 'cashier_3',
    ]);
    $allSessions = $service->listCashSessions($tenantAlpha, ['limit' => 10], 'pos_app');
    $closedSessions = $service->listCashSessions($tenantAlpha, ['status' => 'closed', 'limit' => 10], 'pos_app');
    $openSessions = $service->listCashSessions($tenantAlpha, ['status' => 'open', 'limit' => 10], 'pos_app');
    if (count($allSessions) < 2 || (string) (($closedSessions[0]['id'] ?? '') ?: '') !== $sessionId || (string) (($openSessions[0]['id'] ?? '') ?: '') !== (string) ($openedSecond['id'] ?? '')) {
        $failures[] = 'Debe listar sesiones POS abiertas y cerradas sin perder historial.';
    }
} catch (Throwable $e) {
    $failures[] = 'El lifecycle de caja POS debe funcionar de extremo a extremo: ' . $e->getMessage();
}

try {
    $service->buildCashSummary($tenantBeta, (string) ($sessionId ?? ''), 'pos_app');
    $failures[] = 'No debe permitir leer arqueos POS de otro tenant.';
} catch (Throwable $e) {
    if ((string) $e->getMessage() !== 'POS_SESSION_NOT_FOUND') {
        $failures[] = 'Tenant isolation de arqueo POS devolvio un error inesperado: ' . $e->getMessage();
    }
}

try {
    $service->getOpenCashSession($tenantBeta, 'CAJA-2', 'pos_app');
    $failures[] = 'No debe permitir leer sesiones abiertas POS de otro tenant.';
} catch (Throwable $e) {
    if ((string) $e->getMessage() !== 'POS_OPEN_CASH_SESSION_NOT_FOUND') {
        $failures[] = 'Tenant isolation de getOpenCashSession devolvio un error inesperado: ' . $e->getMessage();
    }
}

try {
    $catalog = (new ContractRegistry())->getSkillsCatalog();
    $resolver = new SkillResolver();
    $skillRegistry = new SkillRegistry($catalog);
    $openSkill = $resolver->resolve('abrir caja pos cash_register_id=CAJA-1 opening_amount=10000', $skillRegistry, []);
    $closeSkill = $resolver->resolve('cerrar caja pos session_id=abc counted_cash_amount=18500', $skillRegistry, []);
    $summarySkill = $resolver->resolve('arqueo pos session_id=abc', $skillRegistry, []);
    $listSkill = $resolver->resolve('listar cajas pos abiertas', $skillRegistry, []);

    if ((string) (($openSkill['selected']['name'] ?? '') ?: '') !== 'pos_open_cash_register') {
        $failures[] = 'SkillResolver debe detectar pos_open_cash_register.';
    }
    if ((string) (($closeSkill['selected']['name'] ?? '') ?: '') !== 'pos_close_cash_register') {
        $failures[] = 'SkillResolver debe detectar pos_close_cash_register.';
    }
    if ((string) (($summarySkill['selected']['name'] ?? '') ?: '') !== 'pos_build_cash_summary') {
        $failures[] = 'SkillResolver debe detectar pos_build_cash_summary.';
    }
    if ((string) (($listSkill['selected']['name'] ?? '') ?: '') !== 'pos_list_cash_sessions') {
        $failures[] = 'SkillResolver debe detectar pos_list_cash_sessions.';
    }
} catch (Throwable $e) {
    $failures[] = 'El catalogo de skills POS de caja debe resolver correctamente: ' . $e->getMessage();
}

try {
    $sharedEnv = [
        'APP_ENV' => 'local',
        'ALLOW_RUNTIME_SCHEMA' => '1',
        'PROJECT_REGISTRY_DB_PATH' => $tmpDir . '/project_registry.sqlite',
        'DB_DRIVER' => 'sqlite',
        'DB_PATH' => $tmpDir . '/pos_cash.sqlite',
        'DB_NAMESPACE_BY_PROJECT' => '0',
        'PROJECT_STORAGE_MODEL' => 'legacy',
        'DB_STORAGE_MODEL' => 'legacy',
    ];
    $sharedEnv['SUKI_RUNTIME_ENV_OVERRIDES_JSON'] = json_encode($sharedEnv, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $chatOpen = runChatTurn([
        'project_root' => $tmpProjectRoot,
        'env' => $sharedEnv,
        'payload' => [
            'tenant_id' => $tenantAlpha,
            'project_id' => 'pos_app',
            'session_id' => 'pos_cash_' . time() . '_open',
            'user_id' => 'operator_pos',
            'role' => 'admin',
            'is_authenticated' => true,
            'mode' => 'app',
            'channel' => 'test',
            'message' => 'abrir caja pos cash_register_id=CAJA-CHAT opening_amount=5000',
        ],
    ]);
    $chatOpenData = is_array($chatOpen['data'] ?? null) ? (array) $chatOpen['data'] : [];
    $chatSessionId = (string) ($chatOpenData['session_id'] ?? '');
    if ((string) ($chatOpen['status'] ?? '') !== 'success' || (string) ($chatOpenData['pos_action'] ?? '') !== 'open_cash_register' || $chatSessionId === '') {
        $failures[] = 'ChatAgent debe abrir caja POS por skill + CommandBus.';
    }

    $chatSummary = runChatTurn([
        'project_root' => $tmpProjectRoot,
        'env' => $sharedEnv,
        'payload' => [
            'tenant_id' => $tenantAlpha,
            'project_id' => 'pos_app',
            'session_id' => 'pos_cash_' . time() . '_summary',
            'user_id' => 'operator_pos',
            'role' => 'admin',
            'is_authenticated' => true,
            'mode' => 'app',
            'channel' => 'test',
            'message' => 'arqueo pos session_id=' . $chatSessionId,
        ],
    ]);
    $chatSummaryData = is_array($chatSummary['data'] ?? null) ? (array) $chatSummary['data'] : [];
    if ((string) ($chatSummary['status'] ?? '') !== 'success' || (string) ($chatSummaryData['pos_action'] ?? '') !== 'build_cash_summary') {
        $failures[] = 'ChatAgent debe preparar arqueos POS por skill.';
    }

    $chatClose = runChatTurn([
        'project_root' => $tmpProjectRoot,
        'env' => $sharedEnv,
        'payload' => [
            'tenant_id' => $tenantAlpha,
            'project_id' => 'pos_app',
            'session_id' => 'pos_cash_' . time() . '_close',
            'user_id' => 'operator_pos',
            'role' => 'admin',
            'is_authenticated' => true,
            'mode' => 'app',
            'channel' => 'test',
            'message' => 'cerrar caja pos session_id=' . $chatSessionId . ' counted_cash_amount=5000',
        ],
    ]);
    $chatCloseData = is_array($chatClose['data'] ?? null) ? (array) $chatClose['data'] : [];
    if ((string) ($chatClose['status'] ?? '') !== 'success' || (string) ($chatCloseData['pos_action'] ?? '') !== 'close_cash_register') {
        $failures[] = 'ChatAgent debe cerrar caja POS por skill.';
    }
} catch (Throwable $e) {
    $failures[] = 'El flujo chat/skills de caja POS debe pasar: ' . $e->getMessage();
}

try {
    $env = [
        'APP_ENV' => 'local',
        'ALLOW_RUNTIME_SCHEMA' => '1',
        'PROJECT_REGISTRY_DB_PATH' => $tmpDir . '/project_registry.sqlite',
        'DB_DRIVER' => 'sqlite',
        'DB_PATH' => $tmpDir . '/pos_cash.sqlite',
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

    $openRoute = runApiRoute([
        'route' => 'pos/open-cash-register',
        'method' => 'POST',
        'payload' => [
            'cash_register_id' => 'CAJA-API',
            'opening_amount' => 7000,
            'project_id' => 'pos_app',
        ],
        'session' => $session,
        'env' => $env,
    ]);
    $openRouteJson = $openRoute['json'];
    $apiSessionId = (string) (($openRouteJson['data']['session']['id'] ?? '') ?: '');
    if (!is_array($openRouteJson) || (string) ($openRouteJson['status'] ?? '') !== 'success' || $apiSessionId === '') {
        $failures[] = 'API pos/open-cash-register debe abrir la caja POS.';
    }

    $summaryRoute = runApiRoute([
        'route' => 'pos/build-cash-summary',
        'method' => 'GET',
        'query' => [
            'session_id' => $apiSessionId,
            'project_id' => 'pos_app',
        ],
        'session' => $session,
        'env' => $env,
    ]);
    $summaryRouteJson = $summaryRoute['json'];
    if (!is_array($summaryRouteJson) || (string) ($summaryRouteJson['status'] ?? '') !== 'success' || (float) (($summaryRouteJson['data']['summary']['opening_amount'] ?? 0) ?: 0) !== 7000.0) {
        $failures[] = 'API pos/build-cash-summary debe devolver arqueo POS.';
    }

    $closeRoute = runApiRoute([
        'route' => 'pos/close-cash-register',
        'method' => 'POST',
        'payload' => [
            'session_id' => $apiSessionId,
            'counted_cash_amount' => 7000,
            'project_id' => 'pos_app',
        ],
        'session' => $session,
        'env' => $env,
    ]);
    $closeRouteJson = $closeRoute['json'];
    if (!is_array($closeRouteJson) || (string) ($closeRouteJson['status'] ?? '') !== 'success' || (string) (($closeRouteJson['data']['session']['status'] ?? '') ?: '') !== 'closed') {
        $failures[] = 'API pos/close-cash-register debe cerrar la caja POS.';
    }
} catch (Throwable $e) {
    $failures[] = 'Las rutas API POS de caja/arqueo deben pasar: ' . $e->getMessage();
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
