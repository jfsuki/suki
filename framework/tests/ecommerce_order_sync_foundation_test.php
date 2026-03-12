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
$tmpDir = FRAMEWORK_ROOT . '/tests/tmp/ecommerce_order_sync_foundation_' . time() . '_' . random_int(1000, 9999);
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
putenv('DB_PATH=' . $tmpDir . '/ecommerce_order_sync.sqlite');
putenv('DB_NAMESPACE_BY_PROJECT=0');
putenv('PROJECT_STORAGE_MODEL=legacy');
putenv('DB_STORAGE_MODEL=legacy');
putenv('ECOMMERCE_HUB_SECRET=test_ecommerce_secret');

$pdo = new PDO('sqlite:' . $tmpDir . '/ecommerce_order_sync.sqlite');
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
$appId = 'ecommerce_app';

try {
    $store = $service->createStore([
        'tenant_id' => $tenantAlpha,
        'app_id' => $appId,
        'platform' => 'woocommerce',
        'store_name' => 'Woo Orders Alpha',
        'store_url' => 'https://woo-orders.example.test',
    ]);
    $storeId = (string) ($store['id'] ?? '');
    if ($storeId === '') {
        $failures[] = 'Debe crear la tienda ecommerce para order sync.';
    }

    $customStore = $service->createStore([
        'tenant_id' => $tenantAlpha,
        'app_id' => $appId,
        'platform' => 'custom_store',
        'store_name' => 'Custom Orders Alpha',
    ]);
    $customStoreId = (string) ($customStore['id'] ?? '');
    if ($customStoreId === '') {
        $failures[] = 'Debe crear la tienda custom_store para fallback seguro.';
    }

    $link = $service->linkOrder([
        'tenant_id' => $tenantAlpha,
        'app_id' => $appId,
        'store_id' => $storeId,
        'external_order_id' => 'wc-order-101',
        'local_reference_type' => 'sale_draft',
        'local_reference_id' => 'draft-101',
        'external_status' => 'processing',
        'currency' => 'cop',
        'total' => 129000,
    ]);
    $linkId = (string) ($link['id'] ?? '');
    if ($linkId === '' || (string) ($link['external_order_id'] ?? '') !== 'wc-order-101' || (string) ($link['local_reference_type'] ?? '') !== 'sale_draft') {
        $failures[] = 'Debe crear el vinculo canonico de pedido ecommerce.';
    }

    $loadedLink = $service->getOrderLink($tenantAlpha, $linkId, $appId);
    if ((string) ($loadedLink['id'] ?? '') !== $linkId) {
        $failures[] = 'Debe cargar el vinculo de pedido ecommerce por link_id.';
    }

    $listedLinks = $service->listOrderLinks($tenantAlpha, $storeId, ['limit' => 10], $appId);
    if (count($listedLinks) !== 1 || (string) (($listedLinks[0]['id'] ?? '') ?: '') !== $linkId) {
        $failures[] = 'Debe listar vinculos de pedidos ecommerce por tienda.';
    }

    $normalized = $service->normalizeExternalOrderPayload($tenantAlpha, $storeId, [
        'external_order_id' => 'wc-order-101',
        'status' => 'processing',
        'currency' => 'cop',
        'total' => 129000,
        'line_items' => [
            [
                'sku' => 'SKU-001',
                'name' => 'Taladro industrial',
                'quantity' => 2,
                'price' => 64500,
            ],
        ],
    ], $appId);
    $normalizedOrder = is_array($normalized['normalized_external_order'] ?? null) ? (array) $normalized['normalized_external_order'] : [];
    if ((string) ($normalized['adapter_key'] ?? '') !== 'woocommerce' || (string) ($normalized['validation_result'] ?? '') !== 'external_order_normalized') {
        $failures[] = 'Debe normalizar el payload externo de pedido ecommerce con el adapter correcto.';
    }
    if ((string) ($normalizedOrder['normalized_status'] ?? '') !== 'processing' || (int) ($normalizedOrder['line_count'] ?? 0) !== 1) {
        $failures[] = 'La normalizacion del pedido ecommerce debe producir status y lineas canonicas.';
    }

    $snapshotResult = $service->registerOrderPullSnapshot($tenantAlpha, $storeId, [
        'external_order_id' => 'wc-order-101',
        'status' => 'processing',
        'currency' => 'COP',
        'total' => 129000,
    ], $appId);
    $snapshot = is_array($snapshotResult['snapshot'] ?? null) ? (array) $snapshotResult['snapshot'] : [];
    $snapshotLink = is_array($snapshotResult['link'] ?? null) ? (array) $snapshotResult['link'] : [];
    if ((string) ($snapshot['external_order_id'] ?? '') !== 'wc-order-101' || (string) ($snapshotLink['sync_status'] ?? '') !== 'snapshot_received') {
        $failures[] = 'Debe registrar snapshot pull y actualizar el vinculo del pedido ecommerce.';
    }

    $marked = $service->markOrderSyncStatus($tenantAlpha, $linkId, 'synced', [
        'local_status' => 'completed',
        'note' => 'foundation ok',
    ], $appId);
    if ((string) ($marked['sync_status'] ?? '') !== 'synced' || (string) ($marked['local_status'] ?? '') !== 'completed') {
        $failures[] = 'Debe registrar el estado de sync del pedido ecommerce.';
    }

    $loadedSnapshot = $service->getOrderSnapshot($tenantAlpha, $storeId, 'wc-order-101', $appId);
    $loadedNormalized = is_array($loadedSnapshot['normalized_payload'] ?? null) ? (array) $loadedSnapshot['normalized_payload'] : [];
    if ((string) ($loadedSnapshot['external_order_id'] ?? '') !== 'wc-order-101' || (string) ($loadedNormalized['normalized_status'] ?? '') !== 'processing') {
        $failures[] = 'Debe cargar el ultimo snapshot canonico del pedido ecommerce.';
    }

    try {
        $service->getOrderLink($tenantBeta, $linkId, $appId);
        $failures[] = 'No debe permitir leer vinculos de pedidos ecommerce de otro tenant.';
    } catch (Throwable $e) {
        if ((string) $e->getMessage() !== 'ECOMMERCE_ORDER_LINK_NOT_FOUND') {
            $failures[] = 'Tenant isolation de order links devolvio error inesperado: ' . $e->getMessage();
        }
    }

    try {
        $service->normalizeExternalOrderPayload($tenantAlpha, '999999', ['external_order_id' => 'wc-order-999'], $appId);
        $failures[] = 'Debe bloquear store_id invalido al normalizar pedido ecommerce.';
    } catch (Throwable $e) {
        if ((string) $e->getMessage() !== 'ECOMMERCE_STORE_NOT_FOUND') {
            $failures[] = 'Store invalido devolvio error inesperado: ' . $e->getMessage();
        }
    }

    $fallbackNormalized = $service->normalizeExternalOrderPayload($tenantAlpha, $customStoreId, [
        'external_order_id' => 'custom-order-1',
        'status' => 'paid',
    ], $appId);
    if ((string) ($fallbackNormalized['result_status'] ?? '') !== 'safe_failure' || (string) ($fallbackNormalized['validation_result'] ?? '') !== 'order_sync_not_supported') {
        $failures[] = 'El fallback de adapter debe responder safe_failure en normalizacion de pedidos.';
    }

    $fallbackSnapshot = $service->registerOrderPullSnapshot($tenantAlpha, $customStoreId, [
        'external_order_id' => 'custom-order-1',
        'status' => 'paid',
    ], $appId);
    if ((string) ($fallbackSnapshot['result_status'] ?? '') !== 'safe_failure' || (string) ($fallbackSnapshot['validation_result'] ?? '') !== 'order_sync_not_supported') {
        $failures[] = 'El fallback de adapter debe responder safe_failure al registrar snapshots de pedidos.';
    }
} catch (Throwable $e) {
    $failures[] = 'La base de order sync ecommerce debe ejecutarse sin errores: ' . $e->getMessage();
}

try {
    $catalog = (new ContractRegistry())->getSkillsCatalog();
    $skillRegistry = new SkillRegistry($catalog);
    $skillResolver = new SkillResolver();
    $linkSkill = $skillResolver->resolve('vincular pedido ecommerce store_id=1 external_order_id=wc-order-101', $skillRegistry, []);
    $snapshotSkill = $skillResolver->resolve('registrar snapshot pedido ecommerce store_id=1 external_order_id=wc-order-101', $skillRegistry, []);
    if ((string) (($linkSkill['selected']['name'] ?? '') ?: '') !== 'ecommerce_link_order') {
        $failures[] = 'SkillResolver debe detectar ecommerce_link_order.';
    }
    if ((string) (($snapshotSkill['selected']['name'] ?? '') ?: '') !== 'ecommerce_register_order_pull_snapshot') {
        $failures[] = 'SkillResolver debe detectar ecommerce_register_order_pull_snapshot.';
    }
} catch (Throwable $e) {
    $failures[] = 'Las skills ecommerce de order sync deben resolverse correctamente: ' . $e->getMessage();
}

try {
    $sharedEnv = [
        'APP_ENV' => 'local',
        'ALLOW_RUNTIME_SCHEMA' => '1',
        'PROJECT_REGISTRY_DB_PATH' => $tmpDir . '/project_registry.sqlite',
        'DB_DRIVER' => 'sqlite',
        'DB_PATH' => $tmpDir . '/ecommerce_order_sync.sqlite',
        'DB_NAMESPACE_BY_PROJECT' => '0',
        'PROJECT_STORAGE_MODEL' => 'legacy',
        'DB_STORAGE_MODEL' => 'legacy',
        'ECOMMERCE_HUB_SECRET' => 'test_ecommerce_secret',
    ];
    $sharedEnv['SUKI_RUNTIME_ENV_OVERRIDES_JSON'] = json_encode($sharedEnv, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $chatStore = $service->createStore([
        'tenant_id' => $tenantAlpha,
        'app_id' => $appId,
        'platform' => 'woocommerce',
        'store_name' => 'Woo Orders Chat',
        'store_url' => 'https://woo-chat.example.test',
    ]);
    $chatStoreId = (string) ($chatStore['id'] ?? '');

    $chatResult = runChatTurn([
        'project_root' => $tmpProjectRoot,
        'env' => $sharedEnv,
        'payload' => [
            'tenant_id' => $tenantAlpha,
            'project_id' => $appId,
            'session_id' => 'ecommerce_order_chat_' . time(),
            'user_id' => 'ecommerce_order_user',
            'role' => 'admin',
            'is_authenticated' => true,
            'mode' => 'app',
            'channel' => 'test',
            'message' => 'registrar snapshot pedido ecommerce store_id=' . $chatStoreId . ' external_order_id=wc-order-202 status=paid currency=COP total=88000',
        ],
    ]);
    if ((string) ($chatResult['status'] ?? '') !== 'success' || (string) (($chatResult['data']['ecommerce_action'] ?? '') ?: '') !== 'register_order_pull_snapshot' || (string) (($chatResult['data']['external_order_id'] ?? '') ?: '') !== 'wc-order-202') {
        $failures[] = 'ChatAgent debe registrar snapshots de pedidos ecommerce via skill + CommandBus.';
    }
} catch (Throwable $e) {
    $failures[] = 'La ruta chat de order sync ecommerce debe pasar: ' . $e->getMessage();
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
