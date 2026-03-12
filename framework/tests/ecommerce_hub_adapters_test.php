<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\AuditLogger;
use App\Core\ContractRegistry;
use App\Core\Database;
use App\Core\EcommerceAdapterResolver;
use App\Core\EcommerceHubEventLogger;
use App\Core\EcommerceHubRepository;
use App\Core\EcommerceHubService;
use App\Core\SkillRegistry;
use App\Core\SkillResolver;

$failures = [];
$tmpDir = FRAMEWORK_ROOT . '/tests/tmp/ecommerce_hub_adapters_' . time() . '_' . random_int(1000, 9999);
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
putenv('DB_PATH=' . $tmpDir . '/ecommerce_hub_adapters.sqlite');
putenv('DB_NAMESPACE_BY_PROJECT=0');
putenv('PROJECT_STORAGE_MODEL=legacy');
putenv('DB_STORAGE_MODEL=legacy');
putenv('ECOMMERCE_HUB_SECRET=test_ecommerce_secret');

$pdo = new PDO('sqlite:' . $tmpDir . '/ecommerce_hub_adapters.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
Database::setConnection($pdo);

$resolver = new EcommerceAdapterResolver();
$service = new EcommerceHubService(
    new EcommerceHubRepository($pdo),
    new AuditLogger($pdo),
    new EcommerceHubEventLogger($tmpProjectRoot),
    $resolver
);

$tenantAlpha = 'tenant_alpha';
$tenantBeta = 'tenant_beta';
$appId = 'ecommerce_app';

try {
    if ($resolver->resolve('woocommerce')->getPlatformKey() !== 'woocommerce') {
        $failures[] = 'El resolver debe seleccionar WooCommerceAdapter.';
    }
    if ($resolver->resolve('tiendanube')->getPlatformKey() !== 'tiendanube') {
        $failures[] = 'El resolver debe seleccionar TiendanubeAdapter.';
    }
    if ($resolver->resolve('prestashop')->getPlatformKey() !== 'prestashop') {
        $failures[] = 'El resolver debe seleccionar PrestaShopAdapter.';
    }
    if ($resolver->resolve('custom_store')->getPlatformKey() !== 'unknown') {
        $failures[] = 'El resolver debe usar UnknownEcommerceAdapter como fallback seguro.';
    }

    $wooStore = $service->createStore([
        'tenant_id' => $tenantAlpha,
        'app_id' => $appId,
        'platform' => 'woocommerce',
        'store_name' => 'Woo Alpha',
        'store_url' => 'https://woo.example.test',
    ]);
    $tiendaStore = $service->createStore([
        'tenant_id' => $tenantAlpha,
        'app_id' => $appId,
        'platform' => 'tiendanube',
        'store_name' => 'Tienda Alpha',
        'store_url' => 'https://tienda.example.test',
        'metadata' => ['external_store_id' => 'tn_123'],
    ]);
    $prestaStore = $service->createStore([
        'tenant_id' => $tenantAlpha,
        'app_id' => $appId,
        'platform' => 'prestashop',
        'store_name' => 'Presta Alpha',
        'store_url' => 'https://presta.example.test',
    ]);
    $customStore = $service->createStore([
        'tenant_id' => $tenantAlpha,
        'app_id' => $appId,
        'platform' => 'custom_store',
        'store_name' => 'Custom Alpha',
    ]);

    $wooStoreId = (string) ($wooStore['id'] ?? '');
    $tiendaStoreId = (string) ($tiendaStore['id'] ?? '');
    $prestaStoreId = (string) ($prestaStore['id'] ?? '');
    $customStoreId = (string) ($customStore['id'] ?? '');

    $registered = $service->registerCredentials([
        'tenant_id' => $tenantAlpha,
        'app_id' => $appId,
        'store_id' => $wooStoreId,
        'credential_type' => 'api_token',
        'api_key' => 'ck_woo_public',
        'secret' => 'cs_woo_secret',
    ]);
    if ((string) ($registered['encrypted_payload'] ?? '') !== '***masked***') {
        $failures[] = 'El registro de credenciales debe devolver payload cifrado enmascarado.';
    }

    $wooValidation = $service->validateConnection($tenantAlpha, $wooStoreId, $appId);
    if (($wooValidation['valid'] ?? false) !== true || (string) ($wooValidation['adapter_key'] ?? '') !== 'woocommerce') {
        $failures[] = 'La validacion de WooCommerce debe pasar por el adapter correcto.';
    }
    if ((string) ($wooValidation['validation_result'] ?? '') !== 'credentials_format_valid') {
        $failures[] = 'La validacion de WooCommerce debe reportar credentials_format_valid.';
    }

    $prestaValidation = $service->validateConnection($tenantAlpha, $prestaStoreId, $appId);
    if (($prestaValidation['valid'] ?? true) !== false || (string) ($prestaValidation['validation_result'] ?? '') !== 'missing_credentials') {
        $failures[] = 'La validacion de PrestaShop sin credenciales debe fallar de forma segura.';
    }
    if ((string) ($prestaValidation['result_status'] ?? '') !== 'safe_failure') {
        $failures[] = 'La validacion fallida debe quedar marcada como safe_failure.';
    }

    $wooMetadata = $service->getNormalizedStoreMetadata($tenantAlpha, $wooStoreId, $appId);
    $maskedPayload = $wooMetadata['credential_summary']['latest_masked_payload'] ?? [];
    $metadataJson = json_encode($wooMetadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    if (($maskedPayload['api_key'] ?? '') !== '***' || ($maskedPayload['secret'] ?? '') !== '***') {
        $failures[] = 'La metadata ecommerce debe exponer solo credenciales enmascaradas.';
    }
    if (str_contains($metadataJson, 'ck_woo_public') || str_contains($metadataJson, 'cs_woo_secret')) {
        $failures[] = 'La metadata ecommerce no debe exponer secretos sin enmascarar.';
    }

    $tiendaCapabilities = $service->getPlatformCapabilities($tenantAlpha, $tiendaStoreId, null, $appId);
    if (($tiendaCapabilities['capabilities']['webhooks'] ?? false) !== true) {
        $failures[] = 'Tiendanube debe declarar webhooks en el modelo de capacidades.';
    }
    $prestaCapabilities = $service->getPlatformCapabilities($tenantAlpha, $prestaStoreId, null, $appId);
    if (($prestaCapabilities['capabilities']['webhooks'] ?? true) !== false) {
        $failures[] = 'PrestaShop debe declarar webhooks=false en esta base de adapters.';
    }
    $customCapabilities = $service->getPlatformCapabilities($tenantAlpha, $customStoreId, null, $appId);
    if ((string) ($customCapabilities['adapter_key'] ?? '') !== 'unknown' || (string) ($customCapabilities['result_status'] ?? '') !== 'safe_failure') {
        $failures[] = 'Plataformas sin adapter deben usar fallback seguro y capacidades restringidas.';
    }

    $ping = $service->pingStore($tenantAlpha, $wooStoreId, $appId);
    if (($ping['ping_attempted'] ?? true) !== false || !in_array((string) ($ping['validation_result'] ?? ''), ['not_implemented', 'remote_ping_disabled'], true)) {
        $failures[] = 'El ping ecommerce base debe ser seguro y explicito cuando el remoto no aplica.';
    }

    try {
        $service->getNormalizedStoreMetadata($tenantBeta, $wooStoreId, $appId);
        $failures[] = 'No debe permitir metadata ecommerce cross-tenant.';
    } catch (Throwable $e) {
        if ((string) $e->getMessage() !== 'ECOMMERCE_STORE_NOT_FOUND') {
            $failures[] = 'Tenant isolation en metadata ecommerce devolvio error inesperado: ' . $e->getMessage();
        }
    }

    try {
        $service->validateConnection($tenantBeta, $wooStoreId, $appId);
        $failures[] = 'No debe permitir validar conexion ecommerce de otro tenant.';
    } catch (Throwable $e) {
        if ((string) $e->getMessage() !== 'ECOMMERCE_STORE_NOT_FOUND') {
            $failures[] = 'Tenant isolation en validateConnection devolvio error inesperado: ' . $e->getMessage();
        }
    }
} catch (Throwable $e) {
    $failures[] = 'La base de adapters ecommerce debe ejecutarse sin errores: ' . $e->getMessage();
}

try {
    $catalog = (new ContractRegistry())->getSkillsCatalog();
    $skillRegistry = new SkillRegistry($catalog);
    $skillResolver = new SkillResolver();
    $capabilitiesSkill = $skillResolver->resolve('ver capacidades ecommerce platform=prestashop', $skillRegistry, []);
    $validationSkill = $skillResolver->resolve('validar conexion ecommerce store_id=1', $skillRegistry, []);
    if ((string) (($capabilitiesSkill['selected']['name'] ?? '') ?: '') !== 'ecommerce_get_platform_capabilities') {
        $failures[] = 'SkillResolver debe detectar ecommerce_get_platform_capabilities.';
    }
    if ((string) (($validationSkill['selected']['name'] ?? '') ?: '') !== 'ecommerce_validate_connection') {
        $failures[] = 'SkillResolver debe detectar ecommerce_validate_connection.';
    }
} catch (Throwable $e) {
    $failures[] = 'Las skills ecommerce nuevas deben resolverse correctamente: ' . $e->getMessage();
}

foreach ($previous as $key => $value) {
    restoreEnvValue($key, $value);
}

$result = ['ok' => $failures === [], 'failures' => $failures];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($result['ok'] ? 0 : 1);

function restoreEnvValue(string $key, $value): void
{
    if ($value === false || $value === null) {
        putenv($key);
        return;
    }

    putenv($key . '=' . $value);
}
