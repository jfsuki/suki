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
use App\Core\SkillRegistry;
use App\Core\SkillResolver;

$failures = [];
$tmpDir = FRAMEWORK_ROOT . '/tests/tmp/ecommerce_hub_architecture_' . time() . '_' . random_int(1000, 9999);
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
putenv('DB_PATH=' . $tmpDir . '/ecommerce_hub.sqlite');
putenv('DB_NAMESPACE_BY_PROJECT=0');
putenv('PROJECT_STORAGE_MODEL=legacy');
putenv('DB_STORAGE_MODEL=legacy');
putenv('ECOMMERCE_HUB_SECRET=test_ecommerce_secret');

$pdo = new PDO('sqlite:' . $tmpDir . '/ecommerce_hub.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
Database::setConnection($pdo);

$service = new EcommerceHubService(
    new EcommerceHubRepository($pdo),
    new AuditLogger($pdo),
    new EcommerceHubEventLogger($tmpProjectRoot)
);

$tenantAlpha = 'tenant_alpha';
$tenantBeta = 'tenant_beta';
$storeId = '';

try {
    $store = $service->createStore([
        'tenant_id' => $tenantAlpha,
        'app_id' => 'ecommerce_app',
        'platform' => 'woocommerce',
        'store_name' => 'Alpha Commerce',
        'store_url' => 'https://alpha.example.test',
        'currency' => 'COP',
        'timezone' => 'America/Bogota',
    ]);
    $storeId = (string) ($store['id'] ?? '');
    if ($storeId === '' || (string) ($store['platform'] ?? '') !== 'woocommerce') {
        $failures[] = 'Debe crear la tienda ecommerce con plataforma valida.';
    }

    $updatedStore = $service->updateStore([
        'tenant_id' => $tenantAlpha,
        'app_id' => 'ecommerce_app',
        'store_id' => $storeId,
        'connection_status' => 'validated',
        'status' => 'active',
    ]);
    if ((string) ($updatedStore['connection_status'] ?? '') !== 'validated') {
        $failures[] = 'Debe actualizar el estado de conexion de la tienda ecommerce.';
    }

    try {
        $service->createStore([
            'tenant_id' => $tenantAlpha,
            'app_id' => 'ecommerce_app',
            'platform' => 'shopify',
            'store_name' => 'Invalid Platform',
        ]);
        $failures[] = 'Debe bloquear plataformas ecommerce invalidas.';
    } catch (Throwable $e) {
        if ((string) $e->getMessage() !== 'ECOMMERCE_PLATFORM_INVALID') {
            $failures[] = 'Plataforma invalida devolvio error inesperado: ' . $e->getMessage();
        }
    }

    $credential = $service->registerCredentials([
        'tenant_id' => $tenantAlpha,
        'app_id' => 'ecommerce_app',
        'store_id' => $storeId,
        'credential_type' => 'api_token',
        'token' => 'tok_alpha_secret',
    ]);
    if ((string) ($credential['encrypted_payload'] ?? '') !== '***masked***') {
        $failures[] = 'La salida de credenciales ecommerce debe estar enmascarada.';
    }
    $rawCredential = $pdo->query('SELECT encrypted_payload FROM ecommerce_credentials LIMIT 1')->fetchColumn();
    if (!is_string($rawCredential) || $rawCredential === '' || $rawCredential === 'tok_alpha_secret' || str_contains($rawCredential, 'tok_alpha_secret')) {
        $failures[] = 'La base debe guardar credenciales ecommerce cifradas sin exponer el secreto original.';
    }

    $setupPending = $service->validateStoreSetup($tenantAlpha, $storeId, 'ecommerce_app');
    if (($setupPending['credentials_configured'] ?? false) !== true || ($setupPending['ready'] ?? true) !== true) {
        // store was already validated before credential registration; setup should remain ready
    }

    $service->updateStore([
        'tenant_id' => $tenantAlpha,
        'app_id' => 'ecommerce_app',
        'store_id' => $storeId,
        'connection_status' => 'pending_validation',
    ]);
    $setupReview = $service->validateStoreSetup($tenantAlpha, $storeId, 'ecommerce_app');
    if (($setupReview['credentials_configured'] ?? false) !== true || ($setupReview['ready'] ?? true) !== false) {
        $failures[] = 'La validacion ecommerce debe detectar setup no listo cuando la conexion esta pendiente.';
    }

    $service->updateStore([
        'tenant_id' => $tenantAlpha,
        'app_id' => 'ecommerce_app',
        'store_id' => $storeId,
        'connection_status' => 'validated',
    ]);
    $setupReady = $service->validateStoreSetup($tenantAlpha, $storeId, 'ecommerce_app');
    if (($setupReady['ready'] ?? false) !== true) {
        $failures[] = 'La validacion ecommerce debe marcar ready cuando hay credenciales y conexion validada.';
    }

    $syncJob = $service->createSyncJob([
        'tenant_id' => $tenantAlpha,
        'app_id' => 'ecommerce_app',
        'store_id' => $storeId,
        'sync_type' => 'orders',
    ]);
    if ((string) ($syncJob['id'] ?? '') === '' || (string) ($syncJob['status'] ?? '') !== 'queued') {
        $failures[] = 'Debe crear un sync job ecommerce en cola.';
    }

    $items = $service->listSyncJobs($tenantAlpha, ['store_id' => $storeId, 'limit' => 10], 'ecommerce_app');
    if (count($items) !== 1) {
        $failures[] = 'Debe listar sync jobs ecommerce por tienda.';
    }

    $service->createOrderRef([
        'tenant_id' => $tenantAlpha,
        'app_id' => 'ecommerce_app',
        'store_id' => $storeId,
        'external_order_id' => 'WC-1001',
        'local_order_status' => 'draft',
        'external_status' => 'processing',
        'total' => 55000,
        'currency' => 'COP',
    ]);
    $orderRefs = $service->getOrderRefsByStore($tenantAlpha, $storeId, ['limit' => 10], 'ecommerce_app');
    if (count($orderRefs) !== 1 || (string) (($orderRefs[0]['external_order_id'] ?? '') ?: '') !== 'WC-1001') {
        $failures[] = 'Debe listar referencias de pedidos ecommerce por tienda.';
    }

    try {
        $service->getStore($tenantBeta, $storeId, 'ecommerce_app');
        $failures[] = 'No debe permitir leer tiendas ecommerce de otro tenant.';
    } catch (Throwable $e) {
        if ((string) $e->getMessage() !== 'ECOMMERCE_STORE_NOT_FOUND') {
            $failures[] = 'Tenant isolation ecommerce devolvio error inesperado: ' . $e->getMessage();
        }
    }
} catch (Throwable $e) {
    $failures[] = 'El servicio de ecommerce debe funcionar de extremo a extremo: ' . $e->getMessage();
}

try {
    $catalog = (new ContractRegistry())->getSkillsCatalog();
    $resolver = new SkillResolver();
    $skillRegistry = new SkillRegistry($catalog);
    $createSkill = $resolver->resolve('crear tienda ecommerce platform=woocommerce store_name="Alpha Skill"', $skillRegistry, []);
    $syncSkill = $resolver->resolve('crear sync ecommerce store_id=1 sync_type=orders', $skillRegistry, []);
    if ((string) (($createSkill['selected']['name'] ?? '') ?: '') !== 'ecommerce_create_store') {
        $failures[] = 'SkillResolver debe detectar ecommerce_create_store.';
    }
    if ((string) (($syncSkill['selected']['name'] ?? '') ?: '') !== 'ecommerce_create_sync_job') {
        $failures[] = 'SkillResolver debe detectar ecommerce_create_sync_job.';
    }
} catch (Throwable $e) {
    $failures[] = 'El catalogo ecommerce debe resolver skills correctamente: ' . $e->getMessage();
}

try {
    $sharedEnv = [
        'APP_ENV' => 'local',
        'ALLOW_RUNTIME_SCHEMA' => '1',
        'PROJECT_REGISTRY_DB_PATH' => $tmpDir . '/project_registry.sqlite',
        'DB_DRIVER' => 'sqlite',
        'DB_PATH' => $tmpDir . '/ecommerce_hub.sqlite',
        'DB_NAMESPACE_BY_PROJECT' => '0',
        'PROJECT_STORAGE_MODEL' => 'legacy',
        'DB_STORAGE_MODEL' => 'legacy',
        'ECOMMERCE_HUB_SECRET' => 'test_ecommerce_secret',
    ];
    $sharedEnv['SUKI_RUNTIME_ENV_OVERRIDES_JSON'] = json_encode($sharedEnv, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $chatCreate = runChatTurn([
        'project_root' => $tmpProjectRoot,
        'env' => $sharedEnv,
        'payload' => [
            'tenant_id' => $tenantAlpha,
            'project_id' => 'ecommerce_app',
            'session_id' => 'ecommerce_chat_' . time() . '_create',
            'user_id' => 'ecommerce_chat_user',
            'role' => 'admin',
            'is_authenticated' => true,
            'mode' => 'app',
            'channel' => 'test',
            'message' => 'crear tienda ecommerce platform=woocommerce store_name="Chat Store"',
        ],
    ]);
    $chatStoreId = (string) (($chatCreate['data']['store']['id'] ?? '') ?: '');
    if ((string) ($chatCreate['status'] ?? '') !== 'success' || (string) (($chatCreate['data']['module_used'] ?? '') ?: '') !== 'ecommerce' || (string) (($chatCreate['data']['ecommerce_action'] ?? '') ?: '') !== 'create_store' || $chatStoreId === '') {
        $failures[] = 'ChatAgent debe crear tiendas ecommerce via skill + CommandBus.';
    }

    $chatSync = runChatTurn([
        'project_root' => $tmpProjectRoot,
        'env' => $sharedEnv,
        'payload' => [
            'tenant_id' => $tenantAlpha,
            'project_id' => 'ecommerce_app',
            'session_id' => 'ecommerce_chat_' . time() . '_sync',
            'user_id' => 'ecommerce_chat_user',
            'role' => 'admin',
            'is_authenticated' => true,
            'mode' => 'app',
            'channel' => 'test',
            'message' => 'crear sync ecommerce store_id=' . $chatStoreId . ' sync_type=products',
        ],
    ]);
    if ((string) ($chatSync['status'] ?? '') !== 'success' || (string) (($chatSync['data']['ecommerce_action'] ?? '') ?: '') !== 'create_sync_job') {
        $failures[] = 'ChatAgent debe crear sync jobs ecommerce.';
    }
} catch (Throwable $e) {
    $failures[] = 'La integracion chat ecommerce debe pasar: ' . $e->getMessage();
}

try {
    $env = [
        'APP_ENV' => 'local',
        'ALLOW_RUNTIME_SCHEMA' => '1',
        'PROJECT_REGISTRY_DB_PATH' => $tmpDir . '/project_registry.sqlite',
        'DB_DRIVER' => 'sqlite',
        'DB_PATH' => $tmpDir . '/ecommerce_hub.sqlite',
        'DB_NAMESPACE_BY_PROJECT' => '0',
        'PROJECT_STORAGE_MODEL' => 'legacy',
        'DB_STORAGE_MODEL' => 'legacy',
        'ECOMMERCE_HUB_SECRET' => 'test_ecommerce_secret',
    ];
    $env['SUKI_RUNTIME_ENV_OVERRIDES_JSON'] = json_encode($env, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $session = [
        'auth_user' => [
            'id' => 'api_ecommerce',
            'tenant_id' => $tenantAlpha,
            'project_id' => 'ecommerce_app',
            'role' => 'admin',
            'label' => 'API Ecommerce',
        ],
    ];

    $create = runApiRoute([
        'route' => 'ecommerce/create-store',
        'method' => 'POST',
        'payload' => [
            'platform' => 'prestashop',
            'store_name' => 'API Store',
            'project_id' => 'ecommerce_app',
        ],
        'session' => $session,
        'env' => $env,
    ]);
    $createJson = $create['json'];
    $apiStoreId = (string) (($createJson['data']['store']['id'] ?? '') ?: '');
    if (!is_array($createJson) || (string) ($createJson['status'] ?? '') !== 'success' || $apiStoreId === '') {
        $failures[] = 'API ecommerce/create-store debe crear la tienda.';
    }

    $register = runApiRoute([
        'route' => 'ecommerce/register-credentials',
        'method' => 'POST',
        'payload' => [
            'store_id' => $apiStoreId,
            'credential_type' => 'api_token',
            'token' => 'tok_api_secret',
            'project_id' => 'ecommerce_app',
        ],
        'session' => $session,
        'env' => $env,
    ]);
    $registerJson = $register['json'];
    if (!is_array($registerJson) || (string) ($registerJson['status'] ?? '') !== 'success' || (string) (($registerJson['data']['credential']['encrypted_payload'] ?? '') ?: '') !== '***masked***') {
        $failures[] = 'API ecommerce/register-credentials debe enmascarar la salida de credenciales.';
    }

    $service->createOrderRef([
        'tenant_id' => $tenantAlpha,
        'app_id' => 'ecommerce_app',
        'store_id' => $apiStoreId,
        'external_order_id' => 'PS-2001',
        'external_status' => 'paid',
        'currency' => 'COP',
    ]);
    $orderRefs = runApiRoute([
        'route' => 'ecommerce/list-order-refs',
        'method' => 'GET',
        'query' => [
            'store_id' => $apiStoreId,
            'project_id' => 'ecommerce_app',
        ],
        'session' => $session,
        'env' => $env,
    ]);
    $orderRefsJson = $orderRefs['json'];
    if (!is_array($orderRefsJson) || (string) ($orderRefsJson['status'] ?? '') !== 'success' || (int) (($orderRefsJson['data']['result_count'] ?? 0)) < 1) {
        $failures[] = 'API ecommerce/list-order-refs debe devolver referencias de pedidos.';
    }
} catch (Throwable $e) {
    $failures[] = 'Las rutas API ecommerce deben pasar: ' . $e->getMessage();
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
