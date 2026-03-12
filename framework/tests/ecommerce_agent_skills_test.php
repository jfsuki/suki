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
$tmpDir = FRAMEWORK_ROOT . '/tests/tmp/ecommerce_agent_skills_' . time() . '_' . random_int(1000, 9999);
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
putenv('DB_PATH=' . $tmpDir . '/ecommerce_agent_skills.sqlite');
putenv('DB_NAMESPACE_BY_PROJECT=0');
putenv('PROJECT_STORAGE_MODEL=legacy');
putenv('DB_STORAGE_MODEL=legacy');
putenv('ECOMMERCE_HUB_SECRET=test_ecommerce_secret');

$pdo = new PDO('sqlite:' . $tmpDir . '/ecommerce_agent_skills.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
Database::setConnection($pdo);

$tenantAlpha = 'tenant_alpha';
$tenantBeta = 'tenant_beta';
$appId = 'ecommerce_app';

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
    ':tenant_id' => stableTenantInt($tenantAlpha),
    ':sku' => 'SKU-001',
    ':nombre' => 'Taladro industrial',
    ':descripcion' => 'Producto base para skills ecommerce.',
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

$wooAlpha = $service->createStore([
    'tenant_id' => $tenantAlpha,
    'app_id' => $appId,
    'platform' => 'woocommerce',
    'store_name' => 'Woo Alpha Uno',
    'store_url' => 'https://woo-alpha.example.test',
]);
$prestaAlpha = $service->createStore([
    'tenant_id' => $tenantAlpha,
    'app_id' => $appId,
    'platform' => 'prestashop',
    'store_name' => 'Presta Alpha Dos',
    'store_url' => 'https://presta-alpha.example.test',
]);
$betaStore = $service->createStore([
    'tenant_id' => $tenantBeta,
    'app_id' => $appId,
    'platform' => 'woocommerce',
    'store_name' => 'Beta Privada',
    'store_url' => 'https://beta-private.example.test',
]);
$wooAlphaId = (string) ($wooAlpha['id'] ?? '');
$prestaAlphaId = (string) ($prestaAlpha['id'] ?? '');

$service->registerCredentials([
    'tenant_id' => $tenantAlpha,
    'app_id' => $appId,
    'store_id' => $wooAlphaId,
    'credential_type' => 'api_key_secret',
    'api_key' => 'ck_alpha_public',
    'secret' => 'cs_alpha_secret',
]);
$service->createSyncJob([
    'tenant_id' => $tenantAlpha,
    'app_id' => $appId,
    'store_id' => $wooAlphaId,
    'sync_type' => 'orders',
]);
$service->registerOrderPullSnapshot($tenantAlpha, $wooAlphaId, [
    'external_order_id' => 'wc-order-202',
    'status' => 'processing',
    'currency' => 'COP',
    'total' => 87000,
], $appId);

try {
    $catalog = (new ContractRegistry())->getSkillsCatalog();
    $skillRegistry = new SkillRegistry($catalog);
    $resolver = new SkillResolver();

    $cases = [
        'valida la conexión de la tienda Woo Alpha Uno' => 'ecommerce_validate_connection',
        'qué puede hacer esta integración prestashop' => 'ecommerce_get_platform_capabilities',
        'lista mis tiendas' => 'ecommerce_list_stores',
        'vincula este producto con la tienda Woo Alpha Uno external_product_id=wc-201' => 'ecommerce_link_product',
        'mira el pedido externo wc-order-202 de la tienda Woo Alpha Uno' => 'ecommerce_get_order_snapshot',
        'revisa la sincronización' => 'ecommerce_list_sync_jobs',
    ];
    foreach ($cases as $message => $expectedSkill) {
        $resolved = $resolver->resolve($message, $skillRegistry, []);
        if ((string) (($resolved['selected']['name'] ?? '') ?: '') !== $expectedSkill) {
            $failures[] = 'SkillResolver no detecto ' . $expectedSkill . ' para: ' . $message;
        }
    }

    $nonEcommerce = $resolver->resolve('abre la caja pos', $skillRegistry, []);
    if (str_starts_with((string) (($nonEcommerce['selected']['name'] ?? '') ?: ''), 'ecommerce_')) {
        $failures[] = 'Un intent POS no debe colisionar con skills ecommerce.';
    }
} catch (Throwable $e) {
    $failures[] = 'Las skills ecommerce deben resolver rutas naturales: ' . $e->getMessage();
}

try {
    $sharedEnv = [
        'APP_ENV' => 'local',
        'ALLOW_RUNTIME_SCHEMA' => '1',
        'PROJECT_REGISTRY_DB_PATH' => $tmpDir . '/project_registry.sqlite',
        'DB_DRIVER' => 'sqlite',
        'DB_PATH' => $tmpDir . '/ecommerce_agent_skills.sqlite',
        'DB_NAMESPACE_BY_PROJECT' => '0',
        'PROJECT_STORAGE_MODEL' => 'legacy',
        'DB_STORAGE_MODEL' => 'legacy',
        'ECOMMERCE_HUB_SECRET' => 'test_ecommerce_secret',
    ];
    $sharedEnv['SUKI_RUNTIME_ENV_OVERRIDES_JSON'] = json_encode($sharedEnv, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $basePayload = [
        'tenant_id' => $tenantAlpha,
        'project_id' => $appId,
        'user_id' => 'ecommerce_skills_user',
        'role' => 'admin',
        'is_authenticated' => true,
        'mode' => 'app',
        'channel' => 'test',
    ];

    $ambiguous = runChatTurn([
        'project_root' => $tmpProjectRoot,
        'env' => $sharedEnv,
        'payload' => $basePayload + [
            'session_id' => 'ecommerce_skills_ambiguous_' . time(),
            'message' => 'valida la conexión',
        ],
    ]);
    $ambiguousReply = (string) (($ambiguous['data']['reply'] ?? '') ?: '');
    if (($ambiguous['data']['ambiguity_detected'] ?? false) !== true || !str_contains($ambiguousReply, 'store_id=' . $wooAlphaId) || !str_contains($ambiguousReply, 'store_id=' . $prestaAlphaId)) {
        $failures[] = 'La validacion de conexion ambigua debe pedir aclaracion con candidatos de tienda.';
    }
    if (str_contains($ambiguousReply, (string) ($betaStore['store_name'] ?? ''))) {
        $failures[] = 'La aclaracion ambigua no debe filtrar tiendas de otro tenant.';
    }
    if ((string) (($ambiguous['data']['skill_group'] ?? '') ?: '') !== 'store_setup') {
        $failures[] = 'La aclaracion ecommerce debe conservar skill_group=store_setup.';
    }

    $listStores = runChatTurn([
        'project_root' => $tmpProjectRoot,
        'env' => $sharedEnv,
        'payload' => $basePayload + [
            'session_id' => 'ecommerce_skills_list_' . time(),
            'message' => 'lista mis tiendas',
        ],
    ]);
    if ((string) ($listStores['status'] ?? '') !== 'success' || (string) (($listStores['data']['ecommerce_action'] ?? '') ?: '') !== 'list_stores') {
        $failures[] = 'El agente debe listar tiendas ecommerce via skill.';
    }
    if ((int) (($listStores['data']['result_count'] ?? 0)) !== 2) {
        $failures[] = 'La lista de tiendas ecommerce debe respetar tenant isolation.';
    }
    $listedStoresJson = json_encode($listStores['data']['items'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    if (str_contains($listedStoresJson, 'Beta Privada')) {
        $failures[] = 'La lista de tiendas ecommerce no debe incluir tiendas de otro tenant.';
    }

    $credentialChat = runChatTurn([
        'project_root' => $tmpProjectRoot,
        'env' => $sharedEnv,
        'payload' => $basePayload + [
            'session_id' => 'ecommerce_skills_credentials_' . time(),
            'message' => 'registrar credenciales ecommerce de la tienda Woo Alpha Uno token=tok_live_secret_123',
        ],
    ]);
    $credentialJson = json_encode($credentialChat, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    if ((string) (($credentialChat['data']['credential']['encrypted_payload'] ?? '') ?: '') !== '***masked***') {
        $failures[] = 'El registro de credenciales por chat debe devolver payload enmascarado.';
    }
    if (str_contains($credentialJson, 'tok_live_secret_123')) {
        $failures[] = 'El flujo chat ecommerce no debe filtrar secretos en respuestas.';
    }

    $productLink = runChatTurn([
        'project_root' => $tmpProjectRoot,
        'env' => $sharedEnv,
        'payload' => $basePayload + [
            'session_id' => 'ecommerce_skills_product_' . time(),
            'message' => 'vincula el producto Taladro industrial con la tienda Woo Alpha Uno external_product_id=wc-201',
        ],
    ]);
    if ((string) ($productLink['status'] ?? '') !== 'success' || (string) (($productLink['data']['ecommerce_action'] ?? '') ?: '') !== 'link_product') {
        $failures[] = 'El agente debe vincular productos ecommerce desde lenguaje natural.';
    }
    if ((string) (($productLink['data']['product_link']['local_product_id'] ?? '') ?: '') !== $productId || (string) (($productLink['data']['product_link']['external_product_id'] ?? '') ?: '') !== 'wc-201') {
        $failures[] = 'El vinculo de producto ecommerce debe resolver producto local y external_product_id.';
    }

    $orderSnapshot = runChatTurn([
        'project_root' => $tmpProjectRoot,
        'env' => $sharedEnv,
        'payload' => $basePayload + [
            'session_id' => 'ecommerce_skills_order_' . time(),
            'message' => 'mira el pedido externo wc-order-202 de la tienda Woo Alpha Uno',
        ],
    ]);
    if ((string) ($orderSnapshot['status'] ?? '') !== 'success' || (string) (($orderSnapshot['data']['ecommerce_action'] ?? '') ?: '') !== 'get_order_snapshot') {
        $failures[] = 'El agente debe cargar snapshots de pedidos ecommerce desde lenguaje natural.';
    }
    if ((string) (($orderSnapshot['data']['snapshot']['external_order_id'] ?? '') ?: '') !== 'wc-order-202') {
        $failures[] = 'El snapshot ecommerce debe conservar external_order_id.';
    }

    $syncJobs = runChatTurn([
        'project_root' => $tmpProjectRoot,
        'env' => $sharedEnv,
        'payload' => $basePayload + [
            'session_id' => 'ecommerce_skills_sync_' . time(),
            'message' => 'revisa la sincronización',
        ],
    ]);
    if ((string) ($syncJobs['status'] ?? '') !== 'success' || (string) (($syncJobs['data']['ecommerce_action'] ?? '') ?: '') !== 'list_sync_jobs') {
        $failures[] = 'El agente debe revisar sync jobs ecommerce desde lenguaje natural.';
    }
    if ((int) (($syncJobs['data']['result_count'] ?? 0)) < 1) {
        $failures[] = 'La consulta de sincronizacion ecommerce debe devolver al menos un sync job.';
    }
} catch (Throwable $e) {
    $failures[] = 'La integracion chat de ecommerce agent skills debe pasar: ' . $e->getMessage();
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
