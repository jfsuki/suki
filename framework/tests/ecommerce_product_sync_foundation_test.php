<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\AuditLogger;
use App\Core\ContractRegistry;
use App\Core\Database;
use App\Core\EcommerceHubEventLogger;
use App\Core\EcommerceHubRepository;
use App\Core\EcommerceHubService;
use App\Core\EntityRegistry;
use App\Core\EntitySearchEventLogger;
use App\Core\EntitySearchRepository;
use App\Core\EntitySearchService;
use App\Core\SkillRegistry;
use App\Core\SkillResolver;

$failures = [];
$tmpDir = FRAMEWORK_ROOT . '/tests/tmp/ecommerce_product_sync_foundation_' . time() . '_' . random_int(1000, 9999);
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
    'ECOMMERCE_HUB_SECRET' => getenv('ECOMMERCE_HUB_SECRET'),
];

putenv('APP_ENV=local');
putenv('ALLOW_RUNTIME_SCHEMA=1');
putenv('PROJECT_REGISTRY_DB_PATH=' . $tmpDir . '/project_registry.sqlite');
putenv('DB_DRIVER=sqlite');
putenv('DB_PATH=' . $tmpDir . '/ecommerce_product_sync.sqlite');
putenv('DB_NAMESPACE_BY_PROJECT=0');
putenv('PROJECT_STORAGE_MODEL=legacy');
putenv('DB_STORAGE_MODEL=legacy');
putenv('ECOMMERCE_HUB_SECRET=test_ecommerce_secret');

$pdo = new PDO('sqlite:' . $tmpDir . '/ecommerce_product_sync.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
Database::setConnection($pdo);

$tenantAlpha = 'tenant_alpha';
$tenantBeta = 'tenant_beta';
$appId = 'ecommerce_app';
$productTenantId = stableTenantInt($tenantAlpha);

$productContract = [
    'type' => 'entity',
    'name' => 'product_catalog',
    'label' => 'Product Catalog',
    'version' => '1.0',
    'table' => [
        'name' => 'catalog_products',
        'primaryKey' => 'id',
        'timestamps' => true,
        'softDelete' => false,
        'tenantScoped' => true,
    ],
    'fields' => [
        ['name' => 'id', 'type' => 'int', 'primary' => true, 'source' => 'system'],
        ['name' => 'sku', 'type' => 'string', 'label' => 'SKU', 'required' => false, 'source' => 'form'],
        ['name' => 'nombre', 'type' => 'string', 'label' => 'Nombre', 'required' => false, 'source' => 'form'],
        ['name' => 'descripcion', 'type' => 'string', 'label' => 'Descripcion', 'required' => false, 'source' => 'form'],
        ['name' => 'activo', 'type' => 'bool', 'label' => 'Activo', 'required' => false, 'source' => 'form'],
    ],
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
file_put_contents(
    $tmpProjectRoot . '/contracts/entities/product_catalog.entity.json',
    json_encode($productContract, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
);

$pdo->exec('CREATE TABLE catalog_products (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, sku TEXT NULL, nombre TEXT NULL, descripcion TEXT NULL, activo INTEGER NULL, created_at TEXT NULL, updated_at TEXT NULL)');
$stmt = $pdo->prepare('INSERT INTO catalog_products (tenant_id, sku, nombre, descripcion, activo, created_at, updated_at) VALUES (:tenant_id, :sku, :nombre, :descripcion, :activo, :created_at, :updated_at)');
$stmt->execute([
    ':tenant_id' => $productTenantId,
    ':sku' => 'SKU-001',
    ':nombre' => 'Taladro industrial',
    ':descripcion' => 'Taladro base para sync ecommerce.',
    ':activo' => 1,
    ':created_at' => date('Y-m-d H:i:s'),
    ':updated_at' => date('Y-m-d H:i:s'),
]);
$productId = (string) $pdo->lastInsertId();

$entitySearch = new EntitySearchService(
    new EntitySearchRepository($pdo, new EntityRegistry(FRAMEWORK_ROOT, $tmpProjectRoot)),
    new AuditLogger($pdo),
    new EntitySearchEventLogger($tmpProjectRoot)
);
$service = new EcommerceHubService(
    new EcommerceHubRepository($pdo),
    new AuditLogger($pdo),
    new EcommerceHubEventLogger($tmpProjectRoot),
    null,
    $entitySearch
);

try {
    $store = $service->createStore([
        'tenant_id' => $tenantAlpha,
        'app_id' => $appId,
        'platform' => 'woocommerce',
        'store_name' => 'Woo Sync Alpha',
        'store_url' => 'https://woo-sync.example.test',
    ]);
    $storeId = (string) ($store['id'] ?? '');
    if ($storeId === '') {
        $failures[] = 'Debe crear la tienda ecommerce para product sync.';
    }

    $link = $service->linkProduct([
        'tenant_id' => $tenantAlpha,
        'app_id' => $appId,
        'store_id' => $storeId,
        'local_product_id' => $productId,
        'external_product_id' => 'wc-101',
        'external_sku' => 'SKU-001',
    ]);
    $linkId = (string) ($link['id'] ?? '');
    if ($linkId === '' || (string) ($link['local_product_id'] ?? '') !== $productId || (string) ($link['external_product_id'] ?? '') !== 'wc-101') {
        $failures[] = 'Debe crear el vinculo canonico entre producto local y ecommerce.';
    }

    $loadedLink = $service->getProductLink($tenantAlpha, $linkId, $appId);
    if ((string) ($loadedLink['id'] ?? '') !== $linkId) {
        $failures[] = 'Debe cargar el vinculo ecommerce por link_id.';
    }

    $listedLinks = $service->listProductLinks($tenantAlpha, $storeId, ['limit' => 10], $appId);
    if (count($listedLinks) !== 1 || (string) (($listedLinks[0]['id'] ?? '') ?: '') !== $linkId) {
        $failures[] = 'Debe listar vinculos ecommerce por tienda.';
    }

    $pushPayload = $service->prepareProductPushPayload($tenantAlpha, $storeId, $productId, $appId);
    $adapterPayload = is_array($pushPayload['adapter_payload'] ?? null) ? (array) $pushPayload['adapter_payload'] : [];
    $preparedPayload = is_array($adapterPayload['payload'] ?? null) ? (array) $adapterPayload['payload'] : [];
    if ((string) ($pushPayload['adapter_key'] ?? '') !== 'woocommerce' || (string) ($adapterPayload['build_result'] ?? '') !== 'payload_ready') {
        $failures[] = 'Debe preparar el payload push usando el adapter correcto.';
    }
    if ((string) ($preparedPayload['sku'] ?? '') !== 'SKU-001' || (string) ($preparedPayload['name'] ?? '') !== 'Taladro industrial') {
        $failures[] = 'El payload push debe usar el producto local normalizado.';
    }

    $pullSnapshot = $service->registerProductPullSnapshot($tenantAlpha, $storeId, [
        'external_product_id' => 'wc-101',
        'external_sku' => 'SKU-001',
        'name' => 'Taladro industrial remoto',
        'status' => 'draft',
    ], $appId);
    $snapshotLink = is_array($pullSnapshot['link'] ?? null) ? (array) $pullSnapshot['link'] : [];
    if ((string) ($snapshotLink['id'] ?? '') !== $linkId || (string) ($snapshotLink['sync_status'] ?? '') !== 'snapshot_received') {
        $failures[] = 'Debe registrar el snapshot pull sobre el vinculo existente.';
    }

    $marked = $service->markProductSyncStatus($tenantAlpha, $linkId, 'synced', [
        'sync_direction' => 'push_local_to_store',
        'sync_note' => 'foundation ok',
    ], $appId);
    if ((string) ($marked['sync_status'] ?? '') !== 'synced' || (string) ($marked['last_sync_direction'] ?? '') !== 'push_local_to_store') {
        $failures[] = 'Debe registrar el estado de sync del producto ecommerce.';
    }

    try {
        $service->getProductLink($tenantBeta, $linkId, $appId);
        $failures[] = 'No debe permitir leer vinculos ecommerce de otro tenant.';
    } catch (Throwable $e) {
        if ((string) $e->getMessage() !== 'ECOMMERCE_PRODUCT_LINK_NOT_FOUND') {
            $failures[] = 'Tenant isolation de product links devolvio error inesperado: ' . $e->getMessage();
        }
    }

    try {
        $service->prepareProductPushPayload($tenantAlpha, '999999', $productId, $appId);
        $failures[] = 'Debe bloquear store_id invalido en prepareProductPushPayload.';
    } catch (Throwable $e) {
        if ((string) $e->getMessage() !== 'ECOMMERCE_STORE_NOT_FOUND') {
            $failures[] = 'Store invalido devolvio error inesperado: ' . $e->getMessage();
        }
    }

    try {
        $service->linkProduct([
            'tenant_id' => $tenantAlpha,
            'app_id' => $appId,
            'store_id' => $storeId,
            'local_product_id' => '999999',
            'external_product_id' => 'wc-999',
        ]);
        $failures[] = 'Debe bloquear local_product_id invalido.';
    } catch (Throwable $e) {
        if ((string) $e->getMessage() !== 'ECOMMERCE_LOCAL_PRODUCT_NOT_FOUND') {
            $failures[] = 'Producto invalido devolvio error inesperado: ' . $e->getMessage();
        }
    }

    $unlinked = $service->unlinkProduct($tenantAlpha, $linkId, $appId);
    if (($unlinked['deleted'] ?? false) !== true) {
        $failures[] = 'Debe eliminar el vinculo ecommerce.';
    }
    if ($service->listProductLinks($tenantAlpha, $storeId, ['limit' => 10], $appId) !== []) {
        $failures[] = 'No debe listar vinculos ecommerce despues de unlink.';
    }
} catch (Throwable $e) {
    $failures[] = 'La base de product sync ecommerce debe ejecutarse sin errores: ' . $e->getMessage();
}

try {
    $catalog = (new ContractRegistry())->getSkillsCatalog();
    $skillRegistry = new SkillRegistry($catalog);
    $skillResolver = new SkillResolver();
    $linkSkill = $skillResolver->resolve('vincular producto ecommerce store_id=1 local_product_id=1 external_product_id=wc-101', $skillRegistry, []);
    $pushSkill = $skillResolver->resolve('preparar payload push ecommerce store_id=1 local_product_id=1', $skillRegistry, []);
    if ((string) (($linkSkill['selected']['name'] ?? '') ?: '') !== 'ecommerce_link_product') {
        $failures[] = 'SkillResolver debe detectar ecommerce_link_product.';
    }
    if ((string) (($pushSkill['selected']['name'] ?? '') ?: '') !== 'ecommerce_prepare_product_push_payload') {
        $failures[] = 'SkillResolver debe detectar ecommerce_prepare_product_push_payload.';
    }
} catch (Throwable $e) {
    $failures[] = 'Las skills ecommerce de product sync deben resolverse correctamente: ' . $e->getMessage();
}

try {
    $sharedEnv = [
        'APP_ENV' => 'local',
        'ALLOW_RUNTIME_SCHEMA' => '1',
        'PROJECT_REGISTRY_DB_PATH' => $tmpDir . '/project_registry.sqlite',
        'DB_DRIVER' => 'sqlite',
        'DB_PATH' => $tmpDir . '/ecommerce_product_sync.sqlite',
        'DB_NAMESPACE_BY_PROJECT' => '0',
        'PROJECT_STORAGE_MODEL' => 'legacy',
        'DB_STORAGE_MODEL' => 'legacy',
        'ECOMMERCE_HUB_SECRET' => 'test_ecommerce_secret',
    ];
    $sharedEnv['SUKI_RUNTIME_ENV_OVERRIDES_JSON'] = json_encode($sharedEnv, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $service->createStore([
        'tenant_id' => $tenantAlpha,
        'app_id' => $appId,
        'platform' => 'woocommerce',
        'store_name' => 'Woo Chat Alpha',
        'store_url' => 'https://woo-chat.example.test',
    ]);
    $chatStore = $service->listStores($tenantAlpha, ['limit' => 1], $appId);
    $chatStoreId = (string) (($chatStore[0]['id'] ?? '') ?: '');

    $chatPrepare = runChatTurn([
        'project_root' => $tmpProjectRoot,
        'env' => $sharedEnv,
        'payload' => [
            'tenant_id' => $tenantAlpha,
            'project_id' => $appId,
            'session_id' => 'ecommerce_product_sync_chat_' . time(),
            'user_id' => 'ecommerce_sync_user',
            'role' => 'admin',
            'is_authenticated' => true,
            'mode' => 'app',
            'channel' => 'test',
            'message' => 'preparar payload push ecommerce store_id=' . $chatStoreId . ' local_product_id=' . $productId,
        ],
    ]);
    if ((string) ($chatPrepare['status'] ?? '') !== 'success' || (string) (($chatPrepare['data']['ecommerce_action'] ?? '') ?: '') !== 'prepare_product_push_payload') {
        $failures[] = 'ChatAgent debe preparar payload push ecommerce via skill + CommandBus.';
    }
} catch (Throwable $e) {
    $failures[] = 'La integracion chat de product sync ecommerce debe pasar: ' . $e->getMessage();
}

foreach ($previous as $key => $value) {
    restoreEnvValue($key, $value);
}

$result = ['ok' => $failures === [], 'failures' => $failures];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($result['ok'] ? 0 : 1);

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

function stableTenantInt(string $tenantId): int
{
    $hash = crc32((string) $tenantId);
    $unsigned = (int) sprintf('%u', $hash);
    $max = 2147483647;
    $value = $unsigned % $max;

    return $value > 0 ? $value : 1;
}
