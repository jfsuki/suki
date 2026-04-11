<?php
declare(strict_types=1);
// project/public/api.php
// ============================================================
// BLINDAJE JSON — antes de cualquier require/autoload
// Garantiza que nunca se envíe HTML al frontend aunque PHP falle
// ============================================================
ini_set('display_errors', 0);
error_reporting(0);
ob_start(); // Captura output espurio (BOM, warnings, notices)

header('Content-Type: application/json; charset=utf-8');

set_exception_handler(function (Throwable $e) {
    while (ob_get_level() > 0) { ob_end_clean(); }
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok'    => false,
        'error' => 'Error interno del servidor.',
        'debug' => (getenv('APP_ENV') === 'dev') ? $e->getMessage() : null,
    ], JSON_UNESCAPED_UNICODE);
    exit;
});

register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        while (ob_get_level() > 0) { ob_end_clean(); }
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok'    => false,
            'error' => 'Error fatal del servidor.',
            'debug' => (getenv('APP_ENV') === 'dev') ? $err['message'] . ' in ' . $err['file'] . ':' . $err['line'] : null,
        ], JSON_UNESCAPED_UNICODE);
    }
});
// ============================================================

define('PROJECT_ROOT', dirname(__DIR__));
require_once PROJECT_ROOT . '/config/env_loader.php';
if (($runtimeEnvOverrides = trim((string) (getenv('SUKI_RUNTIME_ENV_OVERRIDES_JSON') ?: ''))) !== '') {
    $decodedOverrides = json_decode($runtimeEnvOverrides, true);
    if (is_array($decodedOverrides)) {
        foreach ($decodedOverrides as $name => $value) {
            if (!is_string($name) || $name === '') {
                continue;
            }
            $resolvedValue = is_scalar($value) || $value === null ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($resolvedValue === false) {
                continue;
            }
            putenv($name . '=' . $resolvedValue);
            $_ENV[$name] = $resolvedValue;
            $_SERVER[$name] = $resolvedValue;
        }
    }
}
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Inyección de Identidad de Arquitecto para Accesos desde Neural Tower
if (!empty($_SESSION['suki_tower_auth'])) {
    if (empty($_SESSION['auth_user']) || ($_SESSION['auth_user']['id'] ?? '') === 'master_tower') {
        $_SESSION['auth_user'] = [
            'id' => 'admin',
            'tenant_id' => 'demo',
            'project_id' => 'default',
            'role' => 'creator',
            'email' => 'architect@suki.local',
            'nombre' => 'Neural Tower Architect'
        ];
    }
}

$frameworkRoot = getenv('SUKI_FRAMEWORK_ROOT') ?: dirname(PROJECT_ROOT) . '/framework';
define('APP_ROOT', $frameworkRoot);
define('FRAMEWORK_ROOT', $frameworkRoot);

require_once APP_ROOT . '/vendor/autoload.php';
require_once APP_ROOT . '/app/autoload.php';

use App\Core\Response;
use App\Core\CommandLayer;
use App\Core\Contracts\ContractRepository;
use App\Core\ReportEngine;
use App\Core\DashboardEngine;
use App\Core\FormWizard;
use App\Core\CsvImportService;
use App\Core\EntityRegistry;
use App\Core\EntityMigrator;
use App\Core\IntegrationRegistry;
use App\Core\IntegrationValidator;
use App\Core\IntegrationStore;
use App\Core\IntegrationMigrator;
use App\Core\IntegrationActionOrchestrator;
use App\Core\AlanubeClient;
use App\Core\InvoiceRegistry;
use App\Core\InvoiceValidator;
use App\Core\InvoiceMapper;
use App\Core\Database;
use App\Core\QueryBuilder;
use App\Core\ProjectRegistry;
use App\Core\TelemetryService;
use App\Core\CapabilityGraph;
use App\Core\WorkflowRepository;
use App\Core\WorkflowExecutor;
use App\Core\WorkflowCompiler;
use App\Core\OpenApiIntegrationImporter;
use App\Core\ApiSecurityGuard;
use App\Core\SecurityStateRepository;
use App\Core\OperationalQueueStore;
use App\Core\LogSanitizer;
use App\Core\MediaAccessToken;
use App\Core\MediaService;
use App\Core\EntitySearchService;
use App\Core\FiscalEngineService;
use App\Core\EcommerceHubService;
use App\Core\POSService;
use App\Core\PurchasesService;
use App\Core\RoleContext;
use App\Core\WebhookSecurityPolicy;
use App\Core\Agents\ConversationQualityDashboard;
use App\Core\DocumentRenderer;
use App\Core\BusinessConfigService;
use App\Core\ChartDataService;
use App\Core\QuotationService;
use App\Core\EmailService;

$route = trim($_GET['route'] ?? '');
$manifestError = null;
try {
    \App\Core\ManifestValidator::validateOrFail();
} catch (\Throwable $e) {
    $manifestError = $e;
}

// --------------------------------
// CORS (solo si se configura allowlist)
// --------------------------------
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowedOrigins = getenv('SUKI_ALLOWED_ORIGINS') ?: '';
if ($origin && $allowedOrigins !== '') {
    $list = array_map('trim', explode(',', $allowedOrigins));
    if (in_array($origin, $list, true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Methods: GET,POST,PUT,DELETE,OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
    }
}

// --------------------------------
// Inicializar respuesta
// --------------------------------
$response = new Response();
if ($manifestError) {
    $allowedWithoutManifest = [
        'integrations/alanube/test',
        'integrations/alanube/save',
        'integrations/alanube/webhook',
        'channels/telegram/webhook',
        'channels/whatsapp/webhook',
    ];
    if (in_array($route, $allowedWithoutManifest, true)) {
        // allow integration setup even if manifest missing
    } else {
        http_response_code(500);
        echo $response->json('error', 'App manifest invalido: ' . $manifestError->getMessage());
        return;
    }
}

function setTenantContext(array $payload = [], bool $preferAuthenticatedTenant = false): void
{
    $sessionUser = is_array($_SESSION['auth_user'] ?? null) ? (array) $_SESSION['auth_user'] : [];
    $sessionTenant = trim((string) ($sessionUser['tenant_id'] ?? ''));
    $tenant = '';
    if ($preferAuthenticatedTenant && $sessionTenant !== '') {
        $tenant = $sessionTenant;
    } else {
        $tenant = $payload['tenant_id'] ?? ($_SERVER['HTTP_X_TENANT_ID'] ?? '');
    }
    if ($tenant === '' || $tenant === null) {
        return;
    }
    if (!defined('TENANT_ID')) {
        if (is_numeric($tenant)) {
            define('TENANT_ID', (int) $tenant);
        } else {
            $hash = stableTenantInt((string) $tenant);
            define('TENANT_ID', $hash);
            putenv('TENANT_KEY=' . $tenant);
        }
    }
    putenv('TENANT_ID=' . (defined('TENANT_ID') ? (string) TENANT_ID : (string) $tenant));
}

function stableTenantInt(string $tenantId): int
{
    $hash = crc32((string) $tenantId);
    $unsigned = (int) sprintf('%u', $hash);
    $max = 2147483647;
    $value = $unsigned % $max;
    return $value > 0 ? $value : 1;
}

function requestData(): array
{
    static $cacheReady = false;
    static $cached = [];
    if ($cacheReady) {
        return $cached;
    }

    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($contentType, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        if ($raw !== false && $raw !== '') {
            $decoded = json_decode($raw, true);
            $cached = is_array($decoded) ? $decoded : [];
            $cacheReady = true;
            return $cached;
        }
    }

    if (!empty($_POST)) {
        $cached = $_POST;
        $cacheReady = true;
        return $cached;
    }

    $raw = file_get_contents('php://input');
    if ($raw !== false && $raw !== '') {
        $decoded = json_decode($raw, true);
        $cached = is_array($decoded) ? $decoded : [];
        $cacheReady = true;
        return $cached;
    }

    $cacheReady = true;
    return $cached;
}

function requestRawBody(): string
{
    static $loaded = false;
    static $raw = '';
    if ($loaded) {
        return $raw;
    }
    $content = file_get_contents('php://input');
    $raw = $content === false ? '' : (string) $content;
    $loaded = true;
    return $raw;
}

function base64UrlEncode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64UrlDecode(string $data): ?string
{
    $data = trim($data);
    if ($data === '') {
        return null;
    }
    $pad = strlen($data) % 4;
    if ($pad > 0) {
        $data .= str_repeat('=', 4 - $pad);
    }
    $decoded = base64_decode(strtr($data, '-_', '+/'), true);
    return $decoded === false ? null : $decoded;
}

function resolveRecordsReadToken(): string
{
    $fromQuery = trim((string) ($_GET['t'] ?? ''));
    if ($fromQuery !== '') {
        return $fromQuery;
    }
    $fromHeader = trim((string) ($_SERVER['HTTP_X_RECORDS_READ_TOKEN'] ?? ''));
    if ($fromHeader !== '') {
        return $fromHeader;
    }
    $authorization = trim((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
    if (stripos($authorization, 'Bearer ') === 0) {
        return trim(substr($authorization, 7));
    }
    return '';
}

function mediaAccessSecret(): string
{
    $secret = trim((string) (getenv('MEDIA_ACCESS_SECRET') ?: getenv('RECORDS_READ_SECRET') ?: ''));
    if ($secret !== '') {
        return $secret;
    }

    return hash('sha256', FRAMEWORK_ROOT . '|' . PROJECT_ROOT . '|media_access');
}

function mediaAccessTtlSec(): int
{
    return max(60, (int) (getenv('MEDIA_ACCESS_TTL_SEC') ?: 900));
}

/**
 * @return array{ok:bool,code:int,tenant_id:string,reason:string}
 */
function verifySignedRecordsReadToken(
    string $token,
    string $method,
    string $path,
    ?string $requestedTenantId = null,
    ?string $expectedRecordId = null
): array {
    $result = [
        'ok' => false,
        'code' => 401,
        'tenant_id' => '',
        'reason' => 'missing_token',
    ];

    if ($token === '') {
        return $result;
    }

    $secret = trim((string) (getenv('RECORDS_READ_SECRET') ?: ''));
    if ($secret === '') {
        return [
            'ok' => false,
            'code' => 403,
            'tenant_id' => '',
            'reason' => 'secret_not_configured',
        ];
    }

    $parts = explode('.', $token, 3);
    if (count($parts) !== 2) {
        return [
            'ok' => false,
            'code' => 401,
            'tenant_id' => '',
            'reason' => 'malformed_token',
        ];
    }

    $payloadJson = base64UrlDecode((string) ($parts[0] ?? ''));
    $signatureRaw = base64UrlDecode((string) ($parts[1] ?? ''));
    if (!is_string($payloadJson) || !is_string($signatureRaw)) {
        return [
            'ok' => false,
            'code' => 401,
            'tenant_id' => '',
            'reason' => 'decode_failed',
        ];
    }

    $expectedSignature = hash_hmac('sha256', $payloadJson, $secret, true);
    if (!hash_equals($expectedSignature, $signatureRaw)) {
        return [
            'ok' => false,
            'code' => 403,
            'tenant_id' => '',
            'reason' => 'invalid_signature',
        ];
    }

    $payload = json_decode($payloadJson, true);
    if (!is_array($payload)) {
        return [
            'ok' => false,
            'code' => 401,
            'tenant_id' => '',
            'reason' => 'invalid_payload',
        ];
    }

    $scope = trim((string) ($payload['scope'] ?? ''));
    if ($scope !== 'records:read') {
        return [
            'ok' => false,
            'code' => 403,
            'tenant_id' => '',
            'reason' => 'invalid_scope',
        ];
    }

    $exp = (int) ($payload['exp'] ?? 0);
    $ttlSec = (int) (getenv('RECORDS_READ_TTL_SEC') ?: 900);
    if ($ttlSec <= 0) {
        $ttlSec = 900;
    }
    $now = time();
    if ($exp <= 0 || $exp < $now) {
        return [
            'ok' => false,
            'code' => 401,
            'tenant_id' => '',
            'reason' => 'expired_token',
        ];
    }
    if ($exp > ($now + $ttlSec + 5)) {
        return [
            'ok' => false,
            'code' => 403,
            'tenant_id' => '',
            'reason' => 'ttl_exceeded',
        ];
    }

    $tenantId = trim((string) ($payload['tenant_id'] ?? ''));
    if ($tenantId === '') {
        return [
            'ok' => false,
            'code' => 403,
            'tenant_id' => '',
            'reason' => 'missing_tenant',
        ];
    }

    $requestedTenantId = $requestedTenantId !== null ? trim($requestedTenantId) : null;
    if ($requestedTenantId !== null && $requestedTenantId !== '' && $requestedTenantId !== $tenantId) {
        return [
            'ok' => false,
            'code' => 403,
            'tenant_id' => '',
            'reason' => 'tenant_mismatch',
        ];
    }

    $tokenMethod = strtoupper(trim((string) ($payload['method'] ?? '')));
    if ($tokenMethod !== '' && $tokenMethod !== strtoupper($method)) {
        return [
            'ok' => false,
            'code' => 403,
            'tenant_id' => '',
            'reason' => 'method_mismatch',
        ];
    }

    $tokenPath = trim((string) ($payload['path'] ?? ''));
    if ($tokenPath !== '' && $tokenPath !== $path) {
        return [
            'ok' => false,
            'code' => 403,
            'tenant_id' => '',
            'reason' => 'path_mismatch',
        ];
    }

    $tokenRecordId = trim((string) ($payload['record_id'] ?? $payload['resource_key'] ?? ''));
    $expectedRecordId = $expectedRecordId !== null ? trim((string) $expectedRecordId) : null;
    if ($tokenRecordId !== '' && $expectedRecordId !== null && $expectedRecordId !== '' && $tokenRecordId !== $expectedRecordId) {
        return [
            'ok' => false,
            'code' => 403,
            'tenant_id' => '',
            'reason' => 'record_mismatch',
        ];
    }

    return [
        'ok' => true,
        'code' => 200,
        'tenant_id' => $tenantId,
        'reason' => 'token_valid',
    ];
}

function auditRecordsReadAccess(
    string $route,
    string $decision,
    string $authMode,
    string $tenantId = '',
    string $reason = ''
): void {
    $dir = PROJECT_ROOT . '/storage/security';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    if (!is_dir($dir)) {
        return;
    }

    $requestId = trim((string) ($_SERVER['HTTP_X_REQUEST_ID'] ?? ''));
    if ($requestId === '') {
        $requestId = substr(hash('sha256', microtime(true) . ':' . random_int(1000, 9999)), 0, 24);
    }

    $payload = [
        'ts' => date('c'),
        'request_id' => $requestId,
        'endpoint' => $route,
        'method' => strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
        'decision' => $decision,
        'auth_mode' => $authMode,
        'tenant_id' => $tenantId !== '' ? sanitizeKey($tenantId) : '',
        'reason' => $reason,
    ];
    $payload = LogSanitizer::sanitizeArray($payload);

    $line = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($line === false) {
        return;
    }
    @file_put_contents($dir . '/records_read_access.log.jsonl', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}

/**
 * @return array<string, mixed>
 */
function resolveAuthenticatedSessionUser(): array
{
    return is_array($_SESSION['auth_user'] ?? null) ? (array) $_SESSION['auth_user'] : [];
}

function resolveRequestedTenantIdForRecordsMutation(array $payload): string
{
    $payloadTenant = trim((string) ($payload['tenant_id'] ?? ''));
    if ($payloadTenant !== '') {
        return $payloadTenant;
    }

    $headerTenant = trim((string) ($_SERVER['HTTP_X_TENANT_ID'] ?? ''));
    if ($headerTenant !== '') {
        return $headerTenant;
    }

    return trim((string) ($_GET['tenant_id'] ?? ''));
}

function auditRecordsMutationAccess(
    string $route,
    string $method,
    string $decision,
    string $authMode,
    string $tenantId = '',
    string $reason = ''
): void {
    $dir = PROJECT_ROOT . '/storage/security';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    if (!is_dir($dir)) {
        return;
    }

    $requestId = trim((string) ($_SERVER['HTTP_X_REQUEST_ID'] ?? ''));
    if ($requestId === '') {
        $requestId = substr(hash('sha256', microtime(true) . ':' . random_int(1000, 9999)), 0, 24);
    }

    $payload = [
        'ts' => date('c'),
        'request_id' => $requestId,
        'endpoint' => $route,
        'method' => strtoupper($method),
        'decision' => $decision,
        'auth_mode' => $authMode,
        'tenant_id' => $tenantId !== '' ? sanitizeKey($tenantId) : '',
        'reason' => $reason,
    ];
    $payload = LogSanitizer::sanitizeArray($payload);

    $line = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($line === false) {
        return;
    }
    @file_put_contents($dir . '/records_mutation_access.log.jsonl', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}

/**
 * @return array{ok:bool,code:int,tenant_id:string,reason:string,auth_mode:string,payload:array<string,mixed>}
 */
function requireAuthenticatedRecordsMutation(string $route, string $method, array $payload): array
{
    $sessionUser = resolveAuthenticatedSessionUser();
    if (empty($sessionUser)) {
        return [
            'ok' => false,
            'code' => 401,
            'tenant_id' => '',
            'reason' => 'missing_auth',
            'auth_mode' => 'none',
            'payload' => [],
        ];
    }

    $sessionTenantId = trim((string) ($sessionUser['tenant_id'] ?? ''));
    if ($sessionTenantId === '') {
        return [
            'ok' => false,
            'code' => 403,
            'tenant_id' => '',
            'reason' => 'missing_session_tenant',
            'auth_mode' => 'session',
            'payload' => [],
        ];
    }

    $requestedTenantId = resolveRequestedTenantIdForRecordsMutation($payload);
    if ($requestedTenantId !== '' && $requestedTenantId !== $sessionTenantId) {
        return [
            'ok' => false,
            'code' => 403,
            'tenant_id' => '',
            'reason' => 'tenant_mismatch',
            'auth_mode' => 'session',
            'payload' => [],
        ];
    }

    $payload['tenant_id'] = $sessionTenantId;

    return [
        'ok' => true,
        'code' => 200,
        'tenant_id' => $sessionTenantId,
        'reason' => 'ok',
        'auth_mode' => 'session',
        'payload' => $payload,
    ];
}

function auditChannelQueueMetric(
    string $channel,
    string $route,
    string $tenantId,
    string $idempotencyKey,
    bool $enqueued,
    string $queueId,
    int $enqueueLatencyMs
): void {
    $dir = PROJECT_ROOT . '/storage/security';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    if (!is_dir($dir)) {
        return;
    }

    $requestId = trim((string) ($_SERVER['HTTP_X_REQUEST_ID'] ?? ''));
    if ($requestId === '') {
        $requestId = substr(hash('sha256', microtime(true) . ':' . random_int(1000, 9999)), 0, 24);
    }

    $payload = [
        'ts' => date('c'),
        'request_id' => $requestId,
        'channel' => sanitizeKey($channel),
        'endpoint' => $route,
        'tenant_id' => $tenantId !== '' ? sanitizeKey($tenantId) : '',
        'idempotency_key_hash' => hash('sha256', $idempotencyKey),
        'enqueue_latency_ms' => max(0, $enqueueLatencyMs),
        'dedupe_hit' => !$enqueued,
        'queue_id' => trim($queueId),
    ];
    $payload = LogSanitizer::sanitizeArray($payload);

    $line = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($line === false) {
        return;
    }
    @file_put_contents($dir . '/channel_queue_metrics.log.jsonl', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function respondJson(Response $response, string $status, string $message, array $data = [], int $code = 200): void
{
    http_response_code($code);
    echo $response->json($status, $message, $data);
}

function resolveProjectId(array $payload = []): string
{
    $projectId = (string) ($payload['project_id'] ?? $_GET['project_id'] ?? '');
    if ($projectId !== '') {
        return $projectId;
    }
    if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['current_project_id'])) {
        return (string) $_SESSION['current_project_id'];
    }
    return '';
}

function writeJsonFile(string $path, array $data): void
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('No se pudo crear directorio: ' . $dir);
        }
    }
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('No se pudo serializar JSON.');
    }
    if (file_put_contents($path, $json) === false) {
        throw new RuntimeException('No se pudo escribir archivo: ' . $path);
    }
}

function sanitizeKey(string $value): string
{
    $clean = preg_replace('/[^a-zA-Z0-9_-]/', '_', $value);
    $clean = $clean !== null ? $clean : $value;
    return $clean !== '' ? $clean : 'default';
}

function securityStateRepository(): SecurityStateRepository
{
    static $repo = null;
    if ($repo instanceof SecurityStateRepository) {
        return $repo;
    }
    $path = PROJECT_ROOT . '/storage/security/security_state.sqlite';
    $repo = new SecurityStateRepository($path);
    return $repo;
}

function verifyWhatsAppSignature(string $rawBody, string $appSecret): bool
{
    if ($appSecret === '') {
        return false;
    }
    $header = trim((string) ($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? ''));
    if ($header === '' || !str_starts_with($header, 'sha256=')) {
        return false;
    }
    $expected = 'sha256=' . hash_hmac('sha256', $rawBody, $appSecret);
    return hash_equals($expected, $header);
}

function verifyAlanubeWebhookRequest(string $rawBody, string $secret): bool
{
    if ($secret === '') {
        return false;
    }

    $rawToken = trim((string) ($_SERVER['HTTP_X_ALANUBE_WEBHOOK_SECRET'] ?? $_SERVER['HTTP_X_WEBHOOK_SECRET'] ?? ''));
    if ($rawToken !== '' && hash_equals($secret, $rawToken)) {
        return true;
    }

    $sigHeader = trim((string) ($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? ''));
    if ($sigHeader === '' || !str_starts_with($sigHeader, 'sha256=')) {
        return false;
    }
    $expected = 'sha256=' . hash_hmac('sha256', $rawBody, $secret);
    return hash_equals($expected, $sigHeader);
}

function sendTelegramMessage(string $token, string $chatId, string $text): array
{
    if ($token === '') {
        return ['ok' => false, 'error' => 'TELEGRAM_BOT_TOKEN_MISSING'];
    }
    if ($chatId === '') {
        return ['ok' => false, 'error' => 'TELEGRAM_CHAT_ID_MISSING'];
    }

    $url = 'https://api.telegram.org/bot' . $token . '/sendMessage';
    $payload = [
        'chat_id' => $chatId,
        'text' => $text !== '' ? $text : 'OK',
    ];
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return ['ok' => false, 'error' => 'TELEGRAM_PAYLOAD_INVALID'];
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => $json,
            'timeout' => 12,
        ],
    ]);
    $raw = @file_get_contents($url, false, $context);
    if ($raw === false) {
        return ['ok' => false, 'error' => 'TELEGRAM_SEND_FAILED'];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : ['ok' => false, 'error' => 'TELEGRAM_RESPONSE_INVALID'];
}

function sendWhatsAppMessage(string $token, string $phoneNumberId, string $to, string $text): array
{
    if ($token === '') {
        return ['ok' => false, 'error' => 'WHATSAPP_TOKEN_MISSING'];
    }
    if ($phoneNumberId === '') {
        return ['ok' => false, 'error' => 'WHATSAPP_PHONE_NUMBER_ID_MISSING'];
    }
    if ($to === '') {
        return ['ok' => false, 'error' => 'WHATSAPP_TO_MISSING'];
    }

    $url = 'https://graph.facebook.com/v20.0/' . $phoneNumberId . '/messages';
    $payload = [
        'messaging_product' => 'whatsapp',
        'to' => $to,
        'type' => 'text',
        'text' => [
            'body' => $text !== '' ? $text : 'OK',
        ],
    ];
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return ['ok' => false, 'error' => 'WHATSAPP_PAYLOAD_INVALID'];
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nAuthorization: Bearer {$token}\r\n",
            'content' => $json,
            'timeout' => 12,
        ],
    ]);
    $raw = @file_get_contents($url, false, $context);
    if ($raw === false) {
        return ['ok' => false, 'error' => 'WHATSAPP_SEND_FAILED'];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'error' => 'WHATSAPP_RESPONSE_INVALID'];
    }
    $ok = isset($decoded['messages']) || isset($decoded['contacts']) || isset($decoded['id']);
    $decoded['ok'] = $ok;
    return $decoded;
}

function contractEntityNames(): array
{
    $entities = glob(PROJECT_ROOT . '/contracts/entities/*.entity.json') ?: [];
    $names = [];
    foreach ($entities as $path) {
        $raw = @file_get_contents($path);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data)) {
            continue;
        }
        $name = trim((string) ($data['name'] ?? basename($path, '.entity.json')));
        if ($name !== '') {
            $names[$name] = true;
        }
    }
    return array_keys($names);
}

function syncRegistryEntities(ProjectRegistry $registry, string $projectId): array
{
    $names = contractEntityNames();
    return $registry->syncEntitiesFromContracts($projectId, $names, 'contracts');
}

function tokenizeChatMessage(string $message): array
{
    $tokens = [];
    $len = strlen($message);
    $buf = '';
    $inQuote = false;
    $quoteChar = '';

    for ($i = 0; $i < $len; $i++) {
        $ch = $message[$i];
        if ($inQuote) {
            if ($ch === $quoteChar) {
                $inQuote = false;
                continue;
            }
            if ($ch === '\\' && $i + 1 < $len) {
                $buf .= $message[$i + 1];
                $i++;
                continue;
            }
            $buf .= $ch;
            continue;
        }
        if ($ch === '"' || $ch === "'") {
            $inQuote = true;
            $quoteChar = $ch;
            continue;
        }
        if (ctype_space($ch)) {
            if ($buf !== '') {
                $tokens[] = $buf;
                $buf = '';
            }
            continue;
        }
        $buf .= $ch;
    }

    if ($buf !== '') {
        $tokens[] = $buf;
    }

    return $tokens;
}

function parseChatMessage(array $payload): array
{
    $message = trim((string) ($payload['message'] ?? $payload['text'] ?? ''));
    if ($message === '') {
        return ['command' => 'Help', 'reason' => 'empty'];
    }

    $tokens = tokenizeChatMessage($message);
    if (count($tokens) === 0) {
        return ['error' => 'Mensaje vacio'];
    }

    $verb = strtolower(array_shift($tokens));
    $verbMap = [
        'crear' => 'CreateRecord',
        'nuevo' => 'CreateRecord',
        'agregar' => 'CreateRecord',
        'add' => 'CreateRecord',
        'listar' => 'QueryRecords',
        'lista' => 'QueryRecords',
        'ver' => 'QueryRecords',
        'buscar' => 'QueryRecords',
        'consulta' => 'QueryRecords',
        'actualizar' => 'UpdateRecord',
        'editar' => 'UpdateRecord',
        'update' => 'UpdateRecord',
        'eliminar' => 'DeleteRecord',
        'borrar' => 'DeleteRecord',
        'delete' => 'DeleteRecord',
        'leer' => 'ReadRecord',
    ];

    if (!isset($verbMap[$verb])) {
        return ['command' => 'Help', 'reason' => 'unknown_verb'];
    }

    $entity = '';
    $data = [];
    $filters = [];
    $id = null;

    foreach ($tokens as $token) {
        if (strpos($token, '=') !== false || strpos($token, ':') !== false) {
            $sep = strpos($token, '=') !== false ? '=' : ':';
            [$rawKey, $rawVal] = array_pad(explode($sep, $token, 2), 2, '');
            $key = trim($rawKey);
            $val = trim($rawVal);
            if ($key === '') {
                continue;
            }
            if (strtolower($key) === 'id') {
                $id = $val;
                continue;
            }
            $data[$key] = $val;
            $filters[$key] = $val;
            continue;
        }

        if ($entity === '') {
            $entity = $token;
        }
    }

    if ($entity === '') {
        $entity = (string) ($payload['entity'] ?? '');
    }

    if ($entity === '') {
        return ['command' => 'Help', 'reason' => 'missing_entity'];
    }

    if ($verbMap[$verb] === 'QueryRecords' && $id !== null && $id !== '') {
        $verbMap[$verb] = 'ReadRecord';
    }

    return [
        'command' => $verbMap[$verb],
        'entity' => $entity,
        'data' => $data,
        'filters' => $filters,
        'id' => $id,
    ];
}

function isHelpIntent(string $text): bool
{
    $text = trim(mb_strtolower($text));
    if ($text === '') return true;
    $keywords = ['hola', 'buenas', 'buenos', 'ayuda', 'help', 'menu', 'funciones', 'que puedes', 'que haces', 'suki'];
    foreach ($keywords as $kw) {
        if (str_contains($text, $kw)) {
            return true;
        }
    }
    return false;
}

function buildHelpMessage(): string
{
    $forms = glob(PROJECT_ROOT . '/contracts/forms/*.json') ?: [];
    $entities = glob(PROJECT_ROOT . '/contracts/entities/*.entity.json') ?: [];
    $integrations = glob(PROJECT_ROOT . '/contracts/integrations/*.integration.json') ?: [];
    $invoices = glob(PROJECT_ROOT . '/contracts/invoices/*.invoice.json') ?: [];

    $formNames = [];
    foreach ($forms as $path) {
        $data = json_decode((string) @file_get_contents($path), true);
        $name = is_array($data) ? ($data['title'] ?? $data['name'] ?? basename($path, '.json')) : basename($path, '.json');
        $formNames[] = $name;
    }
    $entityNames = [];
    foreach ($entities as $path) {
        $data = json_decode((string) @file_get_contents($path), true);
        $name = is_array($data) ? ($data['label'] ?? $data['name'] ?? basename($path, '.entity.json')) : basename($path, '.entity.json');
        $entityNames[] = $name;
    }

    $lines = [];
    $lines[] = 'Hola, soy SUKI. Estoy lista para ayudarte.';
    $lines[] = 'Puedes escribirme como hablas en WhatsApp.';
    $lines[] = 'Ejemplos rapidos:';
    $lines[] = '- crear cliente nombre=Juan nit=123';
    $lines[] = '- listar cliente';
    $lines[] = '- actualizar cliente id=1 email=juan@mail.com';
    $lines[] = '- eliminar cliente id=1';
    $lines[] = 'Formularios activos: ' . (count($formNames) ? implode(', ', array_slice($formNames, 0, 5)) : 'sin formularios');
    $lines[] = 'Entidades activas: ' . (count($entityNames) ? implode(', ', array_slice($entityNames, 0, 5)) : 'sin entidades');
    if (count($integrations) > 0 && count($invoices) > 0) {
        $lines[] = 'Facturacion electronica: activa (Alanube).';
    } else {
        $lines[] = 'Facturacion electronica: no activa aun.';
    }
    $lines[] = 'Puedes enviar archivos (audio/imagen/PDF). Se procesaran cuando el OCR/voz este habilitado.';
    return implode("\n", $lines);
}

function fetchGridItems(array $entity, string $gridName, $recordId): array
{
    if ($gridName === '' || $recordId === null || $recordId === '') {
        return [];
    }
    $gridTable = '';
    $fk = '';
    foreach (($entity['grids'] ?? []) as $grid) {
        if (!is_array($grid)) {
            continue;
        }
        if (($grid['name'] ?? '') === $gridName) {
            $gridTable = (string) ($grid['table'] ?? '');
            $fk = (string) ($grid['relation']['fk'] ?? '');
            break;
        }
    }
    if ($gridTable === '') {
        $gridTable = ($entity['table']['name'] ?? '') . '__' . $gridName;
    }
    if ($fk === '') {
        $fk = ($entity['table']['name'] ?? 'parent') . '_id';
    }

    $db = Database::connection();
    $qb = new QueryBuilder($db, $gridTable);
    $qb->setAllowedColumns([]);
    return $qb->where($fk, '=', $recordId)->get();
}

// --------------------------------
// 1. Obtener la ruta
// --------------------------------
if ($route === '') {
    echo $response->json('error', 'Ruta no definida');
    return;
}

// --------------------------------
// 1.1 Endpoints especiales (contracts, records, command)
// --------------------------------
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if (empty($_SESSION['csrf_token'])) {
    try {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
    } catch (\Throwable $e) {
        $_SESSION['csrf_token'] = sha1((string) microtime(true));
    }
}

if ($route === 'auth/csrf') {
    respondJson($response, 'success', 'CSRF token', [
        'csrf_token' => (string) ($_SESSION['csrf_token'] ?? ''),
    ]);
    return;
}

$securityGuard = new ApiSecurityGuard();
$guardPayload = in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true) ? requestData() : [];
$guard = $securityGuard->enforce(
    $route,
    $method,
    $_SERVER,
    is_array($_SESSION ?? null) ? $_SESSION : [],
    $guardPayload,
    PROJECT_ROOT . '/storage/security'
);
if (!(bool) ($guard['ok'] ?? false)) {
    $code = (int) ($guard['code'] ?? 403);
    $data = [];
    if (isset($guard['retry_after'])) {
        $data['retry_after'] = (int) $guard['retry_after'];
        header('Retry-After: ' . (string) $data['retry_after']);
    }
    respondJson($response, 'error', (string) ($guard['message'] ?? 'Solicitud bloqueada por seguridad'), $data, $code);
    return;
}

$webhookSecurityPolicy = new WebhookSecurityPolicy();

if (str_starts_with($route, 'contracts/')) {
    $parts = explode('/', $route);
    $type = $parts[1] ?? '';
    if ($type !== 'form' && $type !== 'forms') {
        respondJson($response, 'error', 'Tipo de contrato no soportado', [], 400);
        return;
    }
    $key = $parts[2] ?? '';
    $module = $parts[3] ?? null;
    if ($key === '') {
        respondJson($response, 'error', 'Nombre de contrato requerido', [], 400);
        return;
    }

    try {
        $repo = new ContractRepository();
        $meta = $repo->getFormMeta($key, $module);
        $etag = '"' . sha1($meta['path'] . '|' . $meta['mtime'] . '|' . $meta['size']) . '"';
        header('ETag: ' . $etag);
        header('Cache-Control: public, max-age=60');

        $ifNoneMatch = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
        if ($ifNoneMatch === $etag) {
            http_response_code(304);
            return;
        }

        respondJson($response, 'success', 'Contrato cargado', $meta['data']);
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 500);
    }
    return;
}

// ─── Cotizaciones ──────────────────────────────────────────────────────────────
if (str_starts_with($route, 'quotation/')) {
    $qAuth = is_array($_SESSION['auth_user'] ?? null) ? (array) $_SESSION['auth_user'] : [];
    if (empty($qAuth)) {
        respondJson($response, 'error', 'Acceso denegado.', [], 401);
        return;
    }
    $tenantId = (string) ($qAuth['tenant_id'] ?? '');
    $userId   = (string) ($qAuth['id'] ?? 'system');
    $userRole = strtolower(trim((string) ($qAuth['role'] ?? 'operator')));
    $qAction  = str_replace('quotation/', '', $route);
    $payload  = requestData();

    try {
        $qService = new QuotationService();

        switch ($qAction) {
            case 'create':
                if ($method !== 'POST') { respondJson($response, 'error', 'POST requerido.', [], 405); return; }
                $result = $qService->create($tenantId, $payload, $userId);
                respondJson($response, 'success', "Cotización {$result['quotation_number']} creada.", $result);
                break;

            case 'update':
                if ($method !== 'POST' && $method !== 'PUT') { respondJson($response, 'error', 'POST/PUT requerido.', [], 405); return; }
                $qId = (string) ($payload['id'] ?? $_GET['id'] ?? '');
                if ($qId === '') { respondJson($response, 'error', 'id requerido.', [], 400); return; }
                $result = $qService->update($tenantId, $qId, $payload, $userId);
                respondJson($response, 'success', 'Cotización actualizada.', $result);
                break;

            case 'get':
                $qId = (string) ($_GET['id'] ?? $payload['id'] ?? '');
                if ($qId === '') { respondJson($response, 'error', 'id requerido.', [], 400); return; }
                $result = $qService->get($tenantId, $qId);
                respondJson($response, 'success', 'Cotización encontrada.', $result);
                break;

            case 'list':
                $filters = ['status' => $_GET['status'] ?? '', 'search' => $_GET['search'] ?? ''];
                $limit   = (int) ($_GET['limit'] ?? 20);
                $result  = $qService->list($tenantId, $filters, $limit);
                respondJson($response, 'success', count($result) . ' cotizaciones.', ['items' => $result, 'count' => count($result)]);
                break;

            case 'approve':
                $qId   = (string) ($payload['id'] ?? $_GET['id'] ?? '');
                $notes = (string) ($payload['notes'] ?? '');
                $result = $qService->approve($tenantId, $qId, $notes !== '' ? $notes : null);
                respondJson($response, 'success', 'Cotización aprobada ✓', $result);
                break;

            case 'send':
                $qId    = (string) ($payload['id'] ?? $_GET['id'] ?? '');
                $result = $qService->markSent($tenantId, $qId);
                respondJson($response, 'success', 'Cotización marcada como enviada.', $result);
                break;

            case 'send-email':
                $qId    = (string) ($payload['id'] ?? $_GET['id'] ?? '');
                $base   = (string) ($payload['base_url'] ?? $_SERVER['HTTP_ORIGIN'] ?? '');
                $result = $qService->sendByEmail($tenantId, $qId, $base !== '' ? $base : null);
                respondJson($response, 'success', $result['message'] ?? 'Email enviado.', $result);
                break;

            case 'reject':
                $qId   = (string) ($payload['id'] ?? $_GET['id'] ?? '');
                $reason = (string) ($payload['reason'] ?? $payload['notes'] ?? '');
                $result = $qService->reject($tenantId, $qId, $reason !== '' ? $reason : null);
                respondJson($response, 'success', 'Cotización rechazada.', $result);
                break;

            case 'cancel':
                $qId = (string) ($payload['id'] ?? $_GET['id'] ?? '');
                $result = $qService->cancel($tenantId, $qId);
                respondJson($response, 'success', 'Cotización cancelada.', $result);
                break;

            case 'convert-invoice':
                $qId = (string) ($payload['id'] ?? $_GET['id'] ?? '');
                if (!in_array($userRole, ['admin', 'owner', 'accountant', 'supervisor'], true)) {
                    respondJson($response, 'error', 'Solo admin/contadores pueden convertir a factura.', [], 403);
                    return;
                }
                $result = $qService->convertToInvoice($tenantId, $qId, $payload);
                respondJson($response, 'success', $result['message'] ?? 'Factura creada.', $result);
                break;

            case 'convert-remision':
                $qId = (string) ($payload['id'] ?? $_GET['id'] ?? '');
                $result = $qService->convertToRemision($tenantId, $qId, $payload);
                respondJson($response, 'success', $result['message'] ?? 'Remisión creada.', $result);
                break;

            case 'add-line':
                $qId  = (string) ($payload['quotation_id'] ?? $payload['id'] ?? $_GET['id'] ?? '');
                $line = $qService->addLine($tenantId, $qId, $payload);
                respondJson($response, 'success', 'Línea agregada.', $line);
                break;

            case 'remove-line':
                $qId    = (string) ($payload['quotation_id'] ?? $_GET['quotation_id'] ?? '');
                $lineId = (string) ($payload['line_id'] ?? $_GET['line_id'] ?? '');
                $ok = $qService->removeLine($tenantId, $qId, $lineId);
                respondJson($response, $ok ? 'success' : 'error', $ok ? 'Línea eliminada.' : 'Línea no encontrada.', [], $ok ? 200 : 404);
                break;

            default:
                respondJson($response, 'error', "Acción de cotización no reconocida: {$qAction}", [], 400);
        }
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 422);
    }
    return;
}

// ─── Email ─────────────────────────────────────────────────────────────────────
if (str_starts_with($route, 'email/')) {
    $eAuth = is_array($_SESSION['auth_user'] ?? null) ? (array) $_SESSION['auth_user'] : [];
    if (empty($eAuth)) {
        respondJson($response, 'error', 'Acceso denegado.', [], 401);
        return;
    }
    if ($method !== 'POST') {
        respondJson($response, 'error', 'POST requerido.', [], 405);
        return;
    }
    $tenantId  = (string) ($eAuth['tenant_id'] ?? '');
    $eAction   = str_replace('email/', '', $route);
    $payload   = requestData();

    try {
        $emailSvc = new EmailService();

        if ($eAction === 'send-document') {
            $result = $emailSvc->sendDocumentLink($tenantId, $payload);
            respondJson($response, $result['ok'] ? 'success' : 'error', $result['message'] ?? 'Email procesado.', $result);
        } elseif ($eAction === 'notify') {
            $result = $emailSvc->sendNotification($tenantId, $payload);
            respondJson($response, $result['ok'] ? 'success' : 'error', $result['message'] ?? 'Notificación procesada.', $result);
        } else {
            respondJson($response, 'error', "Acción email no reconocida: {$eAction}", [], 400);
        }
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 422);
    }
    return;
}

// ─── Configuración de Negocio (SMTP, Empresa, Logo) ────────────────────────────
if (str_starts_with($route, 'config/')) {
    $cAuth = is_array($_SESSION['auth_user'] ?? null) ? (array) $_SESSION['auth_user'] : [];
    if (empty($cAuth)) {
        respondJson($response, 'error', 'Acceso denegado.', [], 401);
        return;
    }
    $tenantId = (string) ($cAuth['tenant_id'] ?? '');
    $userId   = (string) ($cAuth['id'] ?? 'system');
    $cAction  = str_replace('config/', '', $route);
    $payload  = requestData();

    try {
        $configService = new App\Core\BusinessConfigService();

        if ($cAction === 'save') {
           if ($method !== 'POST') {
               respondJson($response, 'error', 'POST requerido para guardar config.', [], 405);
               return;
           }
           $result = $configService->saveConfig($tenantId, $payload, $userId);
           respondJson($response, 'success', 'Configuración guardada.', $result);
        } elseif ($cAction === 'get') {
           $result = $configService->getConfig($tenantId);
           respondJson($response, 'success', 'Configuración cargada.', $result);
        } else {
           respondJson($response, 'error', "Acción config no reconocida: {$cAction}", [], 400);
        }
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 422);
    }
    return;
}

// ─── Kanban & Tableros Visuales ──────────────────────────────────────────────
if (str_starts_with($route, 'kanban/')) {
    $cAuth = is_array($_SESSION['auth_user'] ?? null) ? (array) $_SESSION['auth_user'] : [];
    if (empty($cAuth)) {
        respondJson($response, 'error', 'Acceso denegado.', [], 401);
        return;
    }
    $tenantId = (string) ($cAuth['tenant_id'] ?? '');
    $kAction  = str_replace('kanban/', '', $route);
    $payload  = requestData();

    try {
        $kanbanService = new App\Core\KanbanService();

        if ($kAction === 'get') {
            $result = $kanbanService->getQuotesBoard($tenantId);
            respondJson($response, 'success', 'Tablero cargado.', $result);
        } elseif ($kAction === 'move') {
            if ($method !== 'POST') {
                respondJson($response, 'error', 'POST requerido para mover tarjeta.', [], 405);
                return;
            }
            $result = $kanbanService->moveCard($tenantId, (string)($payload['type'] ?? 'quote'), (string)($payload['id'] ?? ''), (string)($payload['status'] ?? ''));
            respondJson($response, 'success', 'Tarjeta movida.', ['ok' => $result]);
        } else {
            respondJson($response, 'error', "Acción kanban no reconocida: {$kAction}", [], 400);
        }
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 422);
    }
    return;
}

// ─── Dashboard & Métricas ──────────────────────────────────────────────────
if (str_starts_with($route, 'dashboard/')) {
    $cAuth = is_array($_SESSION['auth_user'] ?? null) ? (array) $_SESSION['auth_user'] : [];
    if (empty($cAuth)) {
        respondJson($response, 'error', 'Acceso denegado.', [], 401);
        return;
    }
    $tenantId = (string) ($cAuth['tenant_id'] ?? '');

    try {
        $dashboardService = new App\Core\DashboardService();
        $metrics = $dashboardService->getMetrics($tenantId);
        respondJson($response, 'success', 'Métricas cargadas.', $metrics);
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 422);
    }
    return;
}

// ─── Renderizado de Documentos Dinámicos ──────────────────────────────────────
// Acceso requerido: sesión activa + rol válido por tipo de documento
// CUFE y QR en facturas electrónicas provienen de Alanube (almacenados en fiscal_document.metadata)
if (str_starts_with($route, 'doc/render') || $route === 'doc/render') {
    $type    = strtolower(trim((string) ($_GET['type'] ?? '')));
    $id      = (string) ($_GET['id'] ?? '');
    $reportType = (string) ($_GET['report_type'] ?? '');
    $desde   = (string) ($_GET['desde'] ?? date('Y-m-01'));
    $hasta   = (string) ($_GET['hasta'] ?? date('Y-m-d'));

    // Requiere sesión activa — documentos son solo para usuarios autenticados
    $docAuth = is_array($_SESSION['auth_user'] ?? null) ? (array) $_SESSION['auth_user'] : [];
    if (empty($docAuth)) {
        respondJson($response, 'error', 'Acceso denegado — debes iniciar sesión para ver documentos.', [], 401);
        return;
    }
    $tenantId = (string) ($_GET['tenant_id'] ?? $docAuth['tenant_id'] ?? '');
    $userRole = strtolower(trim((string) ($docAuth['role'] ?? 'operator')));

    if ($tenantId === '') {
        respondJson($response, 'error', 'tenant_id requerido.', [], 400);
        return;
    }
    if ($type === '') {
        respondJson($response, 'error', 'type requerido (invoice, credit_note, purchase_order, remision, report).', [], 400);
        return;
    }

    try {
        $renderer = new DocumentRenderer();
        $html = $renderer->render([
            'type'        => $type,
            'id'          => $id,
            'tenant_id'   => $tenantId,
            'user_role'   => $userRole,
            'report_type' => $reportType,
            'desde'       => $desde,
            'hasta'       => $hasta,
        ]);
        header('Content-Type: text/html; charset=utf-8');
        header('X-Frame-Options: SAMEORIGIN');
        header('Content-Security-Policy: default-src \'self\' \'unsafe-inline\' https://cdn.jsdelivr.net; img-src \'self\' data: https:;');
        echo $html;
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', 'Error al renderizar documento: ' . $e->getMessage(), [], 500);
        return;
    }
}

// ─── Configuración de Empresa (Business Config) ────────────────────────────────
if ($route === 'business/config') {
    $bAuth = is_array($_SESSION['auth_user'] ?? null) ? (array) $_SESSION['auth_user'] : [];
    if (empty($bAuth)) {
        respondJson($response, 'error', 'Acceso denegado.', [], 401);
        return;
    }
    $tenantId = (string) ($bAuth['tenant_id'] ?? '');
    $userRole = strtolower(trim((string) ($bAuth['role'] ?? 'operator')));
    if (!in_array($userRole, ['admin', 'owner', 'supervisor'], true)) {
        respondJson($response, 'error', 'Solo administradores pueden cambiar la configuración de empresa.', [], 403);
        return;
    }

    $bcService = new BusinessConfigService();

    if ($method === 'GET') {
        try {
            $config = $bcService->getConfig($tenantId);
            respondJson($response, 'success', 'Configuración de empresa', $config);
        } catch (\Throwable $e) {
            respondJson($response, 'error', $e->getMessage(), [], 500);
        }
        return;
    }

    if ($method === 'POST' || $method === 'PUT') {
        try {
            $payload = requestData();
            $saved = $bcService->saveConfig($tenantId, $payload, (string) ($bAuth['id'] ?? 'system'));
            respondJson($response, 'success', 'Configuración de empresa guardada.', $saved);
        } catch (\Throwable $e) {
            respondJson($response, 'error', $e->getMessage(), [], 500);
        }
        return;
    }

    respondJson($response, 'error', 'Método no permitido.', [], 405);
    return;
}

// ─── Subida de Logo ─────────────────────────────────────────────────────────────
if ($route === 'business/logo/upload') {
    if ($method !== 'POST') {
        respondJson($response, 'error', 'Método no permitido.', [], 405);
        return;
    }
    $logoAuth = is_array($_SESSION['auth_user'] ?? null) ? (array) $_SESSION['auth_user'] : [];
    if (empty($logoAuth)) {
        respondJson($response, 'error', 'Acceso denegado.', [], 401);
        return;
    }
    $tenantId = (string) ($logoAuth['tenant_id'] ?? '');
    $userRole = strtolower(trim((string) ($logoAuth['role'] ?? 'operator')));
    if (!in_array($userRole, ['admin', 'owner', 'supervisor'], true)) {
        respondJson($response, 'error', 'Solo administradores pueden subir el logo.', [], 403);
        return;
    }

    $file = is_array($_FILES['logo'] ?? null) ? (array) $_FILES['logo'] : [];
    if (empty($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        respondJson($response, 'error', 'Archivo logo no recibido o con error.', [], 400);
        return;
    }

    try {
        $bcService = new BusinessConfigService();
        $result = $bcService->uploadLogo($tenantId, [
            'tmp_path'      => (string) ($file['tmp_name'] ?? ''),
            'original_name' => (string) ($file['name'] ?? 'logo'),
            'size'          => (int) ($file['size'] ?? 0),
        ], (string) ($logoAuth['id'] ?? 'system'));
        respondJson($response, 'success', $result['message'] ?? 'Logo actualizado.', $result);
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 500);
    }
    return;
}

// ─── Reporte Dinámico (Chart.js) ──────────────────────────────────────────────
if ($route === 'report/dynamic') {
    $rAuth = is_array($_SESSION['auth_user'] ?? null) ? (array) $_SESSION['auth_user'] : [];
    if (empty($rAuth)) {
        respondJson($response, 'error', 'Acceso denegado — debes iniciar sesión.', [], 401);
        return;
    }
    $tenantId   = (string) ($_GET['tenant_id'] ?? $rAuth['tenant_id'] ?? '');
    $reportType = (string) ($_GET['report_type'] ?? 'ventas_resumen');
    $desde      = (string) ($_GET['desde'] ?? date('Y-m-01'));
    $hasta      = (string) ($_GET['hasta'] ?? date('Y-m-d'));
    $userRole   = strtolower(trim((string) ($rAuth['role'] ?? 'operator')));

    // Solo roles con acceso a reportes
    $reportRoles = ['admin', 'owner', 'accountant', 'supervisor', 'analyst'];
    if (!in_array($userRole, $reportRoles, true)) {
        respondJson($response, 'error', 'Sin acceso a reportes para tu rol: ' . $userRole, [], 403);
        return;
    }

    $format = strtolower(trim((string) ($_GET['format'] ?? 'html')));

    try {
        if ($format === 'json') {
            $chartService = new ChartDataService();
            $data = $chartService->getReportData($tenantId, $reportType, $desde, $hasta, $_GET);
            respondJson($response, 'success', 'Datos del reporte', $data);
        } else {
            $renderer = new DocumentRenderer();
            $html = $renderer->render([
                'type'        => 'report_chart',
                'tenant_id'   => $tenantId,
                'id'          => '',
                'user_role'   => $userRole,
                'report_type' => $reportType,
                'desde'       => $desde,
                'hasta'       => $hasta,
            ]);
            header('Content-Type: text/html; charset=utf-8');
            header('X-Frame-Options: SAMEORIGIN');
            echo $html;
        }
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 500);
        return;
    }
}

if (str_starts_with($route, 'reports/')) {
    $parts = explode('/', $route);
    $action = $parts[1] ?? 'preview';
    $formKey = (string) ($_GET['form'] ?? '');
    $reportKey = (string) ($_GET['report'] ?? '');
    $entity = (string) ($_GET['entity'] ?? '');
    $recordId = $_GET['id'] ?? null;

    if ($formKey === '') {
        respondJson($response, 'error', 'form requerido', [], 400);
        return;
    }

    try {
        $engine = new ReportEngine();
        if ($action === 'pdf') {
            $pdf = $engine->renderPdf($formKey, $reportKey, $recordId, $entity ?: null);
            header('Content-Type: application/pdf');
            header('Content-Disposition: inline; filename=\"reporte.pdf\"');
            echo $pdf;
            return;
        }

        $html = $engine->renderPreview($formKey, $reportKey, $recordId, $entity ?: null);
        header('Content-Type: text/html; charset=utf-8');
        echo $html;
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 500);
        return;
    }
}

if (str_starts_with($route, 'dashboards')) {
    $formKey = (string) ($_GET['form'] ?? '');
    $dashKey = (string) ($_GET['dashboard'] ?? '');
    $entity = (string) ($_GET['entity'] ?? '');
    if ($formKey === '') {
        respondJson($response, 'error', 'form requerido', [], 400);
        return;
    }

    try {
        $engine = new DashboardEngine();
        $data = $engine->build($formKey, $dashKey, $entity ?: null);
        respondJson($response, 'success', 'Dashboard listo', $data);
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 500);
        return;
    }
}

if ($route === 'chat/message') {
    // 0. Single Concurrent Session Check
    if (\App\Core\AuthMiddleware::class && !\App\Core\AuthMiddleware::checkConcurrentSession(true)) {
        respondJson($response, 'success', 'Sesión cerrada', [
            'type' => 'message',
            'agent' => ['id' => 'system', 'role' => 'security'],
            'message' => 'Se ha abierto tu cuenta en otro dispositivo. Por seguridad, esta sesión ha sido cerrada.',
            'action' => 'force_logout'
        ], 200);
        return;
    }

    $payload = requestData();
    $auth = is_array($_SESSION['auth_user'] ?? null) ? (array) $_SESSION['auth_user'] : [];
    
    // Tower override para permitir que la clave maestra use el Builder sin restricciones.
    $isTowerUser = isset($_SESSION['suki_tower_auth']) && $_SESSION['suki_tower_auth'] === true;
    if (empty($auth) && $isTowerUser) {
        $auth = [
            'id' => 'master_tower',
            'role' => 'creator',
            'tenant_id' => 'default',
            'project_id' => 'default'
        ];
    }
    
    $isAuthenticated = !empty($auth);
    if ($isAuthenticated) {
        $authUserId = (string) ($auth['id'] ?? '');
        $authTenantId = (string) ($auth['tenant_id'] ?? '');
        $authProjectId = (string) ($auth['project_id'] ?? '');
        $incomingUserId = (string) ($payload['user_id'] ?? '');
        $incomingTenantId = (string) ($payload['tenant_id'] ?? '');
        $incomingProjectId = (string) ($payload['project_id'] ?? '');

        // Bypass restrictions if acting as the system Tower Master
        if (!$isTowerUser) {
            if ($incomingUserId !== '' && $authUserId !== '' && $incomingUserId !== $authUserId) {
                respondJson($response, 'error', 'No puedes usar un user_id diferente al de tu sesion.', [], 403);
                return;
            }
            if ($incomingTenantId !== '' && $authTenantId !== '' && $incomingTenantId !== $authTenantId) {
                respondJson($response, 'error', 'No puedes usar un tenant_id diferente al de tu sesion.', [], 403);
                return;
            }
            if ($incomingProjectId !== '' && $authProjectId !== '' && $incomingProjectId !== $authProjectId) {
                respondJson($response, 'error', 'No puedes usar un project_id diferente al de tu sesion.', [], 403);
                return;
            }
        }

        if (empty($payload['user_id'])) {
            $payload['user_id'] = $auth['id'] ?? '';
        }
        if (empty($payload['role'])) {
            $payload['role'] = $auth['role'] ?? 'admin';
        }
        if (empty($payload['tenant_id'])) {
            $payload['tenant_id'] = $auth['tenant_id'] ?? '';
        }
        if (empty($payload['project_id'])) {
            $payload['project_id'] = $auth['project_id'] ?? '';
        }
    } else {
        // Unauthenticated chat requests can still be informative, but never trusted for execution.
        $payload['role'] = 'guest';
    }

    $payload['is_authenticated'] = $isAuthenticated;
    $payload['auth_user_id'] = $isAuthenticated ? (string) ($auth['id'] ?? '') : '';
    $payload['auth_tenant_id'] = $isAuthenticated ? (string) ($auth['tenant_id'] ?? '') : '';
    $payload['auth_project_id'] = $isAuthenticated ? (string) ($auth['project_id'] ?? '') : '';
    $payload['chat_exec_auth_required'] = true;

    setTenantContext($payload, $isAuthenticated);
    try {
        $agent = new \App\Core\ChatAgent();
        $result = $agent->handle($payload);
        $status = (string) ($result['status'] ?? 'success');
        $message = (string) ($result['message'] ?? 'OK');
        $data = (array) ($result['data'] ?? []);
        $code = $status === 'error' ? 400 : 200;
        $customCode = (int) ($result['code'] ?? ($data['http_code'] ?? 0));
        if ($customCode >= 100 && $customCode <= 599) {
            $code = $customCode;
        }
        unset($data['http_code']);
        respondJson($response, $status, $message, $data, $code);
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 500);
        return;
    }
}

if ($route === 'channels/telegram/webhook') {
    if ($method !== 'POST') {
        respondJson($response, 'error', 'Metodo no permitido', [], 405);
        return;
    }

    $expectedSecret = trim((string) (getenv('TELEGRAM_WEBHOOK_SECRET') ?: ''));
    if ($expectedSecret === '' && $webhookSecurityPolicy->shouldRequireSecret()) {
        respondJson($response, 'error', 'Telegram webhook secret requerido por politica de seguridad', [], 403);
        return;
    }

    $payload = requestData();
    $rawBody = requestRawBody();
    $receivedSecret = (string) ($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '');
    if ($expectedSecret !== '' && !hash_equals($expectedSecret, $receivedSecret)) {
        respondJson($response, 'error', 'Telegram webhook secret invalido', [], 401);
        return;
    }

    $update = is_array($payload) ? $payload : [];
    $messageNode = is_array($update['message'] ?? null) ? (array) $update['message'] : [];
    if (empty($messageNode) && is_array($update['edited_message'] ?? null)) {
        $messageNode = (array) $update['edited_message'];
    }
    $callback = is_array($update['callback_query'] ?? null) ? (array) $update['callback_query'] : [];
    if (empty($messageNode) && !empty($callback['message']) && is_array($callback['message'])) {
        $messageNode = (array) $callback['message'];
    }

    $updateId = trim((string) ($update['update_id'] ?? ''));
    $messageId = trim((string) ($messageNode['message_id'] ?? $callback['id'] ?? ''));
    $fallbackPayload = $rawBody !== '' ? $rawBody : ((string) (json_encode($update, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: ''));
    $payloadHash = hash('sha256', $fallbackPayload);

    if ($updateId !== '') {
        $idempotencyKey = 'telegram:update:' . $updateId;
    } elseif ($messageId !== '') {
        $idempotencyKey = 'telegram:message:' . $messageId;
    } else {
        $idempotencyKey = 'telegram:hash:' . $payloadHash;
    }

    $tenantId = trim((string) (getenv('TELEGRAM_DEFAULT_TENANT') ?: 'default'));
    if ($tenantId === '') {
        $tenantId = 'default';
    }
    $projectId = trim((string) (getenv('TELEGRAM_DEFAULT_PROJECT') ?: ''));
    if ($projectId === '') {
        try {
            $registry = new ProjectRegistry();
            $manifest = $registry->resolveProjectFromManifest();
            $projectId = (string) ($manifest['id'] ?? 'default');
        } catch (\Throwable $e) {
            $projectId = 'default';
        }
    }

    $chatId = trim((string) ($messageNode['chat']['id'] ?? ''));
    $text = trim((string) ($messageNode['text'] ?? ($callback['data'] ?? '')));
    $queuePayload = [
        'source' => 'telegram.webhook',
        'channel' => 'telegram',
        'tenant_id' => $tenantId,
        'project_id' => $projectId !== '' ? $projectId : 'default',
        'received_at' => date('Y-m-d H:i:s'),
        'update_id' => $updateId,
        'message_id' => $messageId,
        'chat_id' => $chatId,
        'text' => $text,
        'raw_update' => $update,
    ];

    $enqueueStart = microtime(true);
    try {
        $queue = new OperationalQueueStore();
        $enqueue = $queue->enqueueIfNotExists(
            $tenantId,
            'telegram',
            $idempotencyKey,
            'telegram.inbound',
            $queuePayload,
            $payloadHash
        );
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 500);
        return;
    }
    $enqueueLatencyMs = (int) round((microtime(true) - $enqueueStart) * 1000);

    $enqueued = (bool) ($enqueue['enqueued'] ?? false);
    $queueId = (string) ($enqueue['job_id'] ?? '');
    $dedupeHit = !$enqueued;
    auditChannelQueueMetric(
        'telegram',
        $route,
        $tenantId,
        $idempotencyKey,
        $enqueued,
        $queueId,
        $enqueueLatencyMs
    );
    $message = $enqueued
        ? 'Telegram update encolado'
        : 'Telegram update duplicado ignorado';

    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status' => 'success',
        'message' => $message,
        'ok' => true,
        'enqueued' => $enqueued,
        'idempotency_key' => $idempotencyKey,
        'job_id' => $queueId,
        'channel' => 'telegram',
        'enqueue_latency_ms' => $enqueueLatencyMs,
        'dedupe_hit' => $dedupeHit,
        'queue_id' => $queueId,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return;
}

if ($route === 'channels/whatsapp/webhook') {
    if ($method === 'GET') {
        $verifyMode = (string) ($_GET['hub_mode'] ?? $_GET['hub.mode'] ?? '');
        $verifyToken = (string) ($_GET['hub_verify_token'] ?? $_GET['hub.verify_token'] ?? '');
        $challenge = (string) ($_GET['hub_challenge'] ?? $_GET['hub.challenge'] ?? '');
        $expectedToken = (string) (getenv('WHATSAPP_VERIFY_TOKEN') ?: '');
        if ($verifyMode === 'subscribe' && $expectedToken !== '' && hash_equals($expectedToken, $verifyToken)) {
            header('Content-Type: text/plain; charset=utf-8');
            echo $challenge;
            return;
        }
        respondJson($response, 'error', 'WhatsApp verify token invalido', [], 401);
        return;
    }

    if ($method !== 'POST') {
        respondJson($response, 'error', 'Metodo no permitido', [], 405);
        return;
    }

    $whatsAppAppSecret = trim((string) (getenv('WHATSAPP_APP_SECRET') ?: ''));
    if ($whatsAppAppSecret === '' && $webhookSecurityPolicy->shouldRequireSecret()) {
        respondJson($response, 'error', 'WhatsApp webhook secret requerido por politica de seguridad', [], 403);
        return;
    }

    $enqueueStart = microtime(true);
    $rawBody = requestRawBody();
    if ($whatsAppAppSecret !== '' && !verifyWhatsAppSignature($rawBody, $whatsAppAppSecret)) {
        respondJson($response, 'error', 'WhatsApp signature invalida', [], 401);
        return;
    }

    $payload = requestData();
    $entry = is_array($payload['entry'] ?? null) ? (array) ($payload['entry'][0] ?? []) : [];
    $changes = is_array($entry['changes'] ?? null) ? (array) ($entry['changes'][0] ?? []) : [];
    $value = is_array($changes['value'] ?? null) ? (array) $changes['value'] : [];
    $messages = is_array($value['messages'] ?? null) ? (array) $value['messages'] : [];
    $message = is_array($messages[0] ?? null) ? (array) $messages[0] : [];
    $statuses = is_array($value['statuses'] ?? null) ? (array) $value['statuses'] : [];
    $statusNode = is_array($statuses[0] ?? null) ? (array) $statuses[0] : [];
    $from = trim((string) ($message['from'] ?? ($statusNode['recipient_id'] ?? '')));
    $textNode = is_array($message['text'] ?? null) ? (array) $message['text'] : [];
    $text = trim((string) ($textNode['body'] ?? ''));
    $messageId = trim((string) ($message['id'] ?? $statusNode['id'] ?? ''));
    if ($messageId === '' && $from === '' && empty($messages) && empty($statuses)) {
        respondJson($response, 'success', 'WhatsApp update ignorado', ['ignored' => true]);
        return;
    }

    $tenantId = trim((string) (getenv('WHATSAPP_DEFAULT_TENANT') ?: 'default'));
    if ($tenantId === '') {
        $tenantId = 'default';
    }
    $projectId = trim((string) (getenv('WHATSAPP_DEFAULT_PROJECT') ?: ''));
    if ($projectId === '') {
        try {
            $registry = new ProjectRegistry();
            $manifest = $registry->resolveProjectFromManifest();
            $projectId = (string) ($manifest['id'] ?? 'default');
        } catch (\Throwable $e) {
            $projectId = 'default';
        }
    }

    $fallbackPayload = $rawBody !== '' ? $rawBody : ((string) (json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: ''));
    $payloadHash = hash('sha256', $fallbackPayload);
    $idempotencyKey = $messageId !== ''
        ? ('whatsapp:message:' . $messageId)
        : ('whatsapp:hash:' . $payloadHash);

    $queuePayload = [
        'source' => 'whatsapp.webhook',
        'channel' => 'whatsapp',
        'tenant_id' => $tenantId,
        'project_id' => $projectId !== '' ? $projectId : 'default',
        'received_at' => date('Y-m-d H:i:s'),
        'message_id' => $messageId,
        'from' => $from,
        'text' => $text,
        'status' => (string) ($statusNode['status'] ?? ''),
        'raw_update' => $payload,
    ];

    try {
        $queue = new OperationalQueueStore();
        $enqueue = $queue->enqueueIfNotExists(
            $tenantId,
            'whatsapp',
            $idempotencyKey,
            'whatsapp.inbound',
            $queuePayload,
            $payloadHash
        );
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 500);
        return;
    }
    $enqueueLatencyMs = (int) round((microtime(true) - $enqueueStart) * 1000);

    $enqueued = (bool) ($enqueue['enqueued'] ?? false);
    $queueId = (string) ($enqueue['job_id'] ?? '');
    $dedupeHit = !$enqueued;
    $message = $enqueued
        ? 'WhatsApp update encolado'
        : 'WhatsApp update duplicado ignorado';
    auditChannelQueueMetric(
        'whatsapp',
        $route,
        $tenantId,
        $idempotencyKey,
        $enqueued,
        $queueId,
        $enqueueLatencyMs
    );

    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status' => 'success',
        'message' => $message,
        'ok' => true,
        'enqueued' => $enqueued,
        'idempotency_key' => $idempotencyKey,
        'job_id' => $queueId,
        'channel' => 'whatsapp',
        'enqueue_latency_ms' => $enqueueLatencyMs,
        'dedupe_hit' => $dedupeHit,
        'queue_id' => $queueId,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return;
}

if ($route === 'chat/help') {
    $payload = requestData();
    setTenantContext($payload);
    $mode = (string) ($payload['mode'] ?? 'app');
    $projectId = resolveProjectId($payload);
    try {
        $registry = new ProjectRegistry();
        $manifest = $registry->resolveProjectFromManifest();
        if ($projectId === '') {
            $projectId = (string) ($manifest['id'] ?? 'default');
        }
        $sync = syncRegistryEntities($registry, $projectId);
        $summary = $registry->summary($projectId);
        $project = $registry->getProject($projectId);
        $capabilities = (new CapabilityGraph(PROJECT_ROOT))->build($projectId, $mode === 'builder' ? 'builder' : 'app');
    } catch (\Throwable $e) {
        $sync = null;
        $summary = null;
        $project = null;
        $capabilities = null;
    }
    $agent = new \App\Core\ChatAgent();
    $reply = $agent->buildHelpMessage($mode === 'builder' ? 'builder' : 'app', $projectId);
    respondJson($response, 'success', 'OK', [
        'reply' => $reply,
        'summary' => $summary,
        'project' => $project,
        'sync' => $sync,
        'capabilities' => $capabilities,
    ]);
    return;
}

if ($route === 'chat/acid-test') {
    $payload = requestData();
    $tenantId = (string) ($payload['tenant_id'] ?? $_GET['tenant_id'] ?? getenv('TENANT_KEY') ?? getenv('TENANT_ID') ?? 'default');
    $tenantId = $tenantId !== '' ? $tenantId : 'default';
    setTenantContext($payload);
    try {
        $runner = new \App\Core\Agents\AcidChatRunner(PROJECT_ROOT);
        $report = $runner->run($tenantId, ['save' => true]);
        respondJson($response, 'success', 'Acid test listo', [
            'report' => $report,
        ]);
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 500);
    }
    return;
}

if ($route === 'chat/acid-report') {
    $payload = requestData();
    $tenantId = (string) ($payload['tenant_id'] ?? $_GET['tenant_id'] ?? getenv('TENANT_KEY') ?? getenv('TENANT_ID') ?? 'default');
    $safeTenant = sanitizeKey($tenantId);
    $path = PROJECT_ROOT . '/storage/reports/chat_acid_' . $safeTenant . '.json';
    if (!is_file($path)) {
        respondJson($response, 'success', 'Sin reporte', [
            'report' => null,
        ]);
        return;
    }
    $raw = file_get_contents($path);
    $data = $raw !== false ? json_decode($raw, true) : null;
    respondJson($response, 'success', 'Reporte', [
        'report' => is_array($data) ? $data : null,
    ]);
    return;
}

if ($route === 'chat/quality') {
    $payload = requestData();
    $tenantId = (string) ($payload['tenant_id'] ?? $_GET['tenant_id'] ?? getenv('TENANT_KEY') ?? getenv('TENANT_ID') ?? 'default');
    $tenantId = $tenantId !== '' ? $tenantId : 'default';
    $days = (int) ($payload['days'] ?? $_GET['days'] ?? 7);
    $projectId = resolveProjectId($payload);
    try {
        $registry = new ProjectRegistry();
        $manifest = $registry->resolveProjectFromManifest();
        if ($projectId === '') {
            $projectId = (string) ($manifest['id'] ?? 'default');
        }
        $quality = new ConversationQualityDashboard(PROJECT_ROOT);
        $report = $quality->build($tenantId, $days);
        $ops = (new TelemetryService())->summary($tenantId, $projectId, $days);
        $report['ops_summary'] = $ops;
        respondJson($response, 'success', 'Calidad conversacional', [
            'tenant_id' => $tenantId,
            'project_id' => $projectId,
            'report' => $report,
        ]);
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 500);
    }
    return;
}

if ($route === 'chat/sessions/list') {
    $auth = is_array($_SESSION['auth_user'] ?? null) ? (array) $_SESSION['auth_user'] : [];
    if (empty($auth)) {
        respondJson($response, 'error', 'Acceso denegado', [], 401);
        return;
    }
    try {
        $registry = new App\Core\ProjectRegistry();
        $db = $registry->db();
        
        // List all non-archived sessions for this user, newest first
        $sql = "SELECT session_id, title, project_id, last_message_at, is_archived
                FROM chat_sessions
                WHERE user_id = :userId
                  AND is_archived = 0
                ORDER BY COALESCE(last_message_at, session_id) DESC
                LIMIT 40";

        $stmt = $db->prepare($sql);
        $stmt->execute([':userId' => (string)$auth['id']]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        respondJson($response, 'success', 'Listado de sesiones', ['sessions' => $rows]);
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 500);
    }
    return;
}

if ($route === 'chat/history') {
    $auth = is_array($_SESSION['auth_user'] ?? null) ? (array) $_SESSION['auth_user'] : [];
    if (empty($auth)) {
        respondJson($response, 'error', 'Acceso denegado', [], 401);
        return;
    }
    $sessionId = (string) ($_GET['session_id'] ?? '');
    $limit     = max(1, min(200, (int) ($_GET['limit'] ?? 100)));
    $tenantId  = (string) ($auth['tenant_id'] ?? 'default');

    try {
        // Read from conversation_memory (SQLite project_registry) — stores BOTH user AND assistant turns
        $registry = new \App\Core\ProjectRegistry();
        $db       = $registry->db();
        $threadId = $tenantId . ':' . $sessionId;

        $stmt = $db->prepare(
            "SELECT role, content, created_at
             FROM conversation_memory
             WHERE thread_id = :tid
             ORDER BY id ASC
             LIMIT :lim"
        );
        $stmt->bindValue(':tid', $threadId);
        $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        // Map to the format builder.php expects: direction + message + created_at
        $history = array_map(static function (array $row): array {
            return [
                'direction'  => $row['role'] === 'user' ? 'in' : 'out',
                'message'    => (string) ($row['content'] ?? ''),
                'created_at' => (string) ($row['created_at'] ?? ''),
            ];
        }, $rows);

        respondJson($response, 'success', 'Historial recuperado', ['history' => $history]);
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 500);
    }
    return;
}

if ($route === 'chat/sessions/create') {
    $auth = is_array($_SESSION['auth_user'] ?? null) ? (array) $_SESSION['auth_user'] : [];
    if (empty($auth)) {
        respondJson($response, 'error', 'Acceso denegado', [], 401);
        return;
    }
    try {
        $convManager = new \App\Core\ConversationManagerService();
        $sessionId = $convManager->startNewSubject(
            (string)$auth['id'], 
            (string)($auth['project_id'] ?? 'default'), 
            (string)($auth['tenant_id'] ?? 'default'), 
            'local'
        );
        respondJson($response, 'success', 'Sesión creada', ['session_id' => $sessionId]);
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 500);
    }
    return;
}

if ($route === 'chat/journal/get') {
    $auth = is_array($_SESSION['auth_user'] ?? null) ? (array) $_SESSION['auth_user'] : [];
    if (empty($auth)) {
        respondJson($response, 'error', 'Acceso denegado', [], 401);
        return;
    }
    $agentRole = (string) ($_GET['role'] ?? 'admin');
    $projectId = (string) ($auth['project_id'] ?? 'default');
    $tenantId = (string) ($auth['tenant_id'] ?? 'default');
    $sessionId = (string) ($_GET['session_id'] ?? '');
    
    try {
        $journalService = new \App\Core\AgentJournalService();
        $journal = $journalService->getJournal($tenantId, $projectId, $agentRole, $sessionId);
        // Keywords are LLM-extracted (stored in journal['keywords']) — no regex, no stopwords
        respondJson($response, 'success', 'Agenda recuperada', [
            'journal'  => $journal,
            'keywords' => $journal['keywords'] ?? [],
        ]);
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 500);
    }
    return;
}

if ($route === 'chat/ops-quality') {
    $payload = requestData();
    $tenantId = (string) ($payload['tenant_id'] ?? $_GET['tenant_id'] ?? getenv('TENANT_KEY') ?? getenv('TENANT_ID') ?? 'default');
    $tenantId = $tenantId !== '' ? $tenantId : 'default';
    $days = (int) ($payload['days'] ?? $_GET['days'] ?? 7);
    $projectId = resolveProjectId($payload);
    try {
        $registry = new ProjectRegistry();
        $manifest = $registry->resolveProjectFromManifest();
        if ($projectId === '') {
            $projectId = (string) ($manifest['id'] ?? 'default');
        }
        $ops = (new TelemetryService())->summary($tenantId, $projectId, $days);
        respondJson($response, 'success', 'Metricas operativas', [
            'tenant_id' => $tenantId,
            'project_id' => $projectId,
            'days' => $days,
            'ops_summary' => $ops,
        ]);
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 500);
    }
    return;
}

if ($route === 'integrations/import_openapi') {
    $payload = requestData();
    try {
        $importer = new OpenApiIntegrationImporter();
        $persist = !isset($payload['persist']) || (bool) $payload['persist'];
        $result = $importer->import($payload, $persist);
        respondJson($response, 'success', 'Integracion importada desde OpenAPI', $result);
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 400);
    }
    return;
}

if ($route === 'workflow/templates') {
    try {
        $repo = new WorkflowRepository();
        respondJson($response, 'success', 'Templates de workflow', [
            'templates' => $repo->templates(),
        ]);
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 500);
    }
    return;
}

if ($route === 'workflow/remix') {
    $payload = requestData();
    $templateId = (string) ($payload['template_id'] ?? $_GET['template_id'] ?? '');
    $workflowId = (string) ($payload['workflow_id'] ?? $_GET['workflow_id'] ?? '');
    if ($templateId === '' || $workflowId === '') {
        respondJson($response, 'error', 'template_id y workflow_id son requeridos', [], 400);
        return;
    }
    try {
        $repo = new WorkflowRepository();
        $save = $repo->remix($templateId, $workflowId);
        respondJson($response, 'success', 'Workflow remix creado', $save);
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 400);
    }
    return;
}

if ($route === 'workflow/list') {
    try {
        $repo = new WorkflowRepository();
        respondJson($response, 'success', 'Workflows', [
            'workflows' => $repo->list(),
        ]);
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 500);
    }
    return;
}

if ($route === 'workflow/get') {
    $payload = requestData();
    $workflowId = (string) ($payload['workflow_id'] ?? $_GET['workflow_id'] ?? '');
    if ($workflowId === '') {
        respondJson($response, 'error', 'workflow_id requerido', [], 400);
        return;
    }
    try {
        $repo = new WorkflowRepository();
        $contract = $repo->load($workflowId);
        respondJson($response, 'success', 'Workflow cargado', [
            'workflow_id' => $workflowId,
            'contract' => $contract,
        ]);
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 404);
    }
    return;
}

if ($route === 'workflow/revisions') {
    $payload = requestData();
    $workflowId = (string) ($payload['workflow_id'] ?? $_GET['workflow_id'] ?? '');
    if ($workflowId === '') {
        respondJson($response, 'error', 'workflow_id requerido', [], 400);
        return;
    }
    try {
        $repo = new WorkflowRepository();
        $rows = $repo->history($workflowId);
        respondJson($response, 'success', 'Historial de workflow', [
            'workflow_id' => $workflowId,
            'revisions' => $rows,
        ]);
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 404);
    }
    return;
}

if ($route === 'workflow/diff') {
    $payload = requestData();
    $workflowId = (string) ($payload['workflow_id'] ?? $_GET['workflow_id'] ?? '');
    $fromRevision = (int) ($payload['from_revision'] ?? $_GET['from_revision'] ?? 0);
    $toRevision = (int) ($payload['to_revision'] ?? $_GET['to_revision'] ?? 0);
    if ($workflowId === '' || $fromRevision < 1 || $toRevision < 1) {
        respondJson($response, 'error', 'workflow_id, from_revision y to_revision son requeridos', [], 400);
        return;
    }
    try {
        $repo = new WorkflowRepository();
        $diff = $repo->diff($workflowId, $fromRevision, $toRevision);
        respondJson($response, 'success', 'Diff de revisiones generado', $diff);
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 400);
    }
    return;
}

if ($route === 'workflow/restore') {
    $payload = requestData();
    $workflowId = (string) ($payload['workflow_id'] ?? $_GET['workflow_id'] ?? '');
    $revision = (int) ($payload['revision'] ?? $_GET['revision'] ?? 0);
    if ($workflowId === '' || $revision < 1) {
        respondJson($response, 'error', 'workflow_id y revision validos son requeridos', [], 400);
        return;
    }
    try {
        $repo = new WorkflowRepository();
        $restored = $repo->restore($workflowId, $revision);
        respondJson($response, 'success', 'Workflow restaurado', $restored);
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 400);
    }
    return;
}

if ($route === 'workflow/validate') {
    $payload = requestData();
    $workflowId = (string) ($payload['workflow_id'] ?? $_GET['workflow_id'] ?? '');
    $contract = is_array($payload['contract'] ?? null) ? (array) $payload['contract'] : [];
    try {
        if (empty($contract) && $workflowId !== '') {
            $repo = new WorkflowRepository();
            $contract = $repo->load($workflowId);
        }
        if (empty($contract)) {
            respondJson($response, 'error', 'contract o workflow_id requerido', [], 400);
            return;
        }
        \App\Core\WorkflowValidator::validateOrFail($contract);
        respondJson($response, 'success', 'Workflow valido', [
            'workflow_id' => (string) ($contract['meta']['id'] ?? $workflowId),
        ]);
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 400);
    }
    return;
}

if ($route === 'workflow/compile') {
    $payload = requestData();
    $text = (string) ($payload['text'] ?? $payload['message'] ?? '');
    $workflowId = (string) ($payload['workflow_id'] ?? '');
    $current = is_array($payload['current_contract'] ?? null) ? (array) $payload['current_contract'] : [];
    try {
        if (empty($current) && $workflowId !== '') {
            $repo = new WorkflowRepository();
            if ($repo->exists($workflowId)) {
                $current = $repo->load($workflowId);
            }
        }
        $compiler = new WorkflowCompiler();
        $proposal = $compiler->compile($text, $current);
        respondJson($response, 'success', 'Propuesta de workflow lista', $proposal);
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 400);
    }
    return;
}

if ($route === 'workflow/save') {
    $payload = requestData();
    $contract = is_array($payload['contract'] ?? null) ? (array) $payload['contract'] : [];
    if (empty($contract)) {
        respondJson($response, 'error', 'contract requerido', [], 400);
        return;
    }
    try {
        $repo = new WorkflowRepository();
        $saved = $repo->save($contract, 'api_workflow_save');
        respondJson($response, 'success', 'Workflow guardado', $saved);
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 400);
    }
    return;
}

if ($route === 'workflow/apply') {
    $payload = requestData();
    $confirm = (bool) ($payload['confirm'] ?? false);
    if (!$confirm) {
        respondJson($response, 'error', 'confirm=true es requerido para aplicar propuesta', [], 400);
        return;
    }
    $contract = is_array($payload['proposed_contract'] ?? null) ? (array) $payload['proposed_contract'] : [];
    if (empty($contract)) {
        respondJson($response, 'error', 'proposed_contract requerido', [], 400);
        return;
    }
    try {
        $repo = new WorkflowRepository();
        $saved = $repo->save($contract, 'api_workflow_apply');
        respondJson($response, 'success', 'Propuesta aplicada', $saved);
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 400);
    }
    return;
}

if ($route === 'workflow/execute') {
    $payload = requestData();
    $workflowId = (string) ($payload['workflow_id'] ?? $_GET['workflow_id'] ?? '');
    $contract = is_array($payload['contract'] ?? null) ? (array) $payload['contract'] : [];
    $input = is_array($payload['input'] ?? null) ? (array) $payload['input'] : [];
    try {
        if (empty($contract) && $workflowId !== '') {
            $repo = new WorkflowRepository();
            $contract = $repo->load($workflowId);
        }
        if (empty($contract)) {
            respondJson($response, 'error', 'contract o workflow_id requerido', [], 400);
            return;
        }
        $executor = new WorkflowExecutor();
        $result = $executor->execute($contract, $input, [
            'tenant_id' => (string) ($_SESSION['auth_user']['tenant_id'] ?? 'default'),
            'project_id' => resolveProjectId($payload),
            'user_id' => (string) ($_SESSION['auth_user']['id'] ?? 'anon'),
        ]);
        $status = (bool) ($result['ok'] ?? false) ? 'success' : 'error';
        $code = $status === 'success' ? 200 : 400;
        respondJson($response, $status, $status === 'success' ? 'Workflow ejecutado' : 'Workflow fallo', $result, $code);
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 400);
    }
    return;
}

if ($route === 'wizard/form-from-entity') {
    $payload = requestData();
    $entityName = (string) ($payload['entity'] ?? $_GET['entity'] ?? '');
    if ($entityName === '') {
        respondJson($response, 'error', 'entity requerido', [], 400);
        return;
    }

    try {
        $registry = new EntityRegistry();
        $entity = $registry->get($entityName);
        $wizard = new FormWizard();
        $options = [
            'master_detail' => !empty($payload['master_detail']) || !empty($payload['masterDetail']),
            'report_type' => (string) ($payload['report_type'] ?? $payload['reportType'] ?? ''),
            'template' => (string) ($payload['template'] ?? ''),
        ];
        $form = $wizard->buildFromEntity($entity, $options);

        $saved = false;
        if (!empty($payload['save'])) {
            $fileName = $form['name'] . '.json';
            $path = PROJECT_ROOT . '/contracts/forms/' . $fileName;
            writeJsonFile($path, $form);
            $saved = true;
        }

        respondJson($response, 'success', 'Formulario generado', [
            'form' => $form,
            'saved' => $saved,
        ]);
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 500);
        return;
    }
}

if ($route === 'entity/save') {
    $payload = requestData();
    $entity = $payload['entity'] ?? null;
    if (!is_array($entity)) {
        respondJson($response, 'error', 'entity requerido', [], 400);
        return;
    }
    try {
        $name = $entity['name'] ?? '';
        if ($name === '') {
            respondJson($response, 'error', 'entity.name requerido', [], 400);
            return;
        }
        $fileName = $name . '.entity.json';
        $path = PROJECT_ROOT . '/contracts/entities/' . $fileName;
        writeJsonFile($path, $entity);
        $migrator = new EntityMigrator(new EntityRegistry());
        $migration = $migrator->migrateEntity($entity, true);
        try {
            $registry = new ProjectRegistry();
            $manifest = $registry->resolveProjectFromManifest();
            $registry->ensureProject(
                $manifest['id'] ?? 'default',
                $manifest['name'] ?? 'Proyecto',
                $manifest['status'] ?? 'draft',
                $manifest['tenant_mode'] ?? 'shared',
                '',
                (string) ($manifest['storage_model'] ?? '')
            );
            $registry->registerEntity($manifest['id'] ?? 'default', $name, 'editor');
        } catch (\Throwable $e) {
            // ignore registry errors
        }
        respondJson($response, 'success', 'Entidad guardada', [
            'file' => $fileName,
            'migration' => $migration,
        ]);
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 500);
        return;
    }
}

if ($route === 'import/csv') {
    $payload = requestData();
    try {
        $service = new CsvImportService();
        $result = $service->import($payload);
        $entity = $result['entity'];
        $fileName = ($entity['name'] ?? 'entity') . '.entity.json';
        $path = PROJECT_ROOT . '/contracts/entities/' . $fileName;
        writeJsonFile($path, $entity);

        $form = null;
        if (!empty($payload['createForm'])) {
            $wizard = new FormWizard();
            $form = $wizard->buildFromEntity($entity);
            $formFile = $form['name'] . '.json';
            writeJsonFile(PROJECT_ROOT . '/contracts/forms/' . $formFile, $form);
        }

        try {
            $registry = new ProjectRegistry();
            $manifest = $registry->resolveProjectFromManifest();
            $registry->ensureProject(
                $manifest['id'] ?? 'default',
                $manifest['name'] ?? 'Proyecto',
                $manifest['status'] ?? 'draft',
                $manifest['tenant_mode'] ?? 'shared',
                '',
                (string) ($manifest['storage_model'] ?? '')
            );
            $registry->registerEntity($manifest['id'] ?? 'default', $entity['name'] ?? 'entity', 'csv');
        } catch (\Throwable $e) {
            // ignore registry errors
        }

        respondJson($response, 'success', 'CSV importado', [
            'entity' => $entity,
            'migration' => $result['migration'] ?? [],
            'form' => $form,
        ]);
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 500);
        return;
    }
}

if ($route === 'registry/status') {
    try {
        $registry = new ProjectRegistry();
        $manifest = $registry->resolveProjectFromManifest();
        $registry->ensureProject(
            $manifest['id'] ?? 'default',
            $manifest['name'] ?? 'Proyecto',
            $manifest['status'] ?? 'draft',
            $manifest['tenant_mode'] ?? 'shared',
            '',
            (string) ($manifest['storage_model'] ?? '')
        );
        $projectId = resolveProjectId();
        if ($projectId === '') {
            $projectId = (string) ($manifest['id'] ?? 'default');
            if (session_status() === PHP_SESSION_ACTIVE) {
                $_SESSION['current_project_id'] = $projectId;
            }
        }
        $sync = syncRegistryEntities($registry, $projectId);
        $summary = $registry->summary($projectId);
        $project = $registry->getProject($projectId) ?: $manifest;
        $capabilities = (new CapabilityGraph(PROJECT_ROOT))->build($projectId, 'app');
        $tenantId = (string) (getenv('TENANT_KEY') ?: getenv('TENANT_ID') ?: 'default');
        $quality = (new ConversationQualityDashboard(PROJECT_ROOT))->build($tenantId, 7);
        $ops = (new TelemetryService())->summary($tenantId, $projectId, 7);
        respondJson($response, 'success', 'OK', [
            'project' => $project,
            'summary' => $summary,
            'sync' => $sync,
            'capabilities' => $capabilities,
            'chat_quality' => $quality['summary'] ?? [],
            'ops_metrics' => $ops,
        ]);
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 500);
        return;
    }
}

if ($route === 'registry/entities') {
    try {
        $registry = new ProjectRegistry();
        $manifest = $registry->resolveProjectFromManifest();
        $projectId = resolveProjectId();
        if ($projectId === '') {
            $projectId = (string) ($manifest['id'] ?? 'default');
        }
        $sync = syncRegistryEntities($registry, $projectId);
        $entities = glob(PROJECT_ROOT . '/contracts/entities/*.entity.json') ?: [];
        $list = [];
        foreach ($entities as $path) {
            $data = json_decode((string) @file_get_contents($path), true);
            if (!is_array($data)) {
                continue;
            }
            $name = (string) ($data['name'] ?? basename($path, '.entity.json'));
            $label = (string) ($data['label'] ?? $name);
            $fields = [];
            $required = [];
            foreach (($data['fields'] ?? []) as $field) {
                if (!is_array($field)) {
                    continue;
                }
                $fname = (string) ($field['name'] ?? '');
                if ($fname === '' || $fname === 'id') {
                    continue;
                }
                $source = (string) ($field['source'] ?? '');
                if (!empty($field['grid']) || str_starts_with($source, 'grid:')) {
                    continue;
                }
                $fields[] = $fname;
                if (!empty($field['required'])) {
                    $required[] = $fname;
                }
            }
            $sample = !empty($required) ? array_slice($required, 0, 2) : array_slice($fields, 0, 2);
            $exampleParts = [];
            foreach ($sample as $fname) {
                $exampleParts[] = $fname . '=valor';
            }
            $example = $name !== '' ? 'crear ' . $name . ' ' . implode(' ', $exampleParts) : '';
            $list[] = [
                'name' => $name,
                'label' => $label,
                'fields' => $fields,
                'required' => $required,
                'example' => $example,
            ];
        }
        respondJson($response, 'success', 'OK', [
            'project_id' => $projectId,
            'sync' => $sync,
            'entities' => $list,
        ]);
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 500);
        return;
    }
}

if ($route === 'registry/users') {
    try {
        $registry = new ProjectRegistry();
        $manifest = $registry->resolveProjectFromManifest();
        $projectId = resolveProjectId();
        if ($projectId === '') {
            $projectId = (string) ($manifest['id'] ?? 'default');
        }
        $users = $registry->listUsers($projectId);
        respondJson($response, 'success', 'OK', [
            'project_id' => $projectId,
            'users' => $users,
        ]);
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 500);
        return;
    }
}

if ($route === 'registry/user') {
    $payload = requestData();
    try {
        $registry = new ProjectRegistry();
        $manifest = $registry->resolveProjectFromManifest();
        $projectId = resolveProjectId($payload);
        if ($projectId === '') {
            $projectId = (string) ($manifest['id'] ?? 'default');
        }
        $userId = (string) ($payload['id'] ?? $payload['user_id'] ?? '');
        if ($userId === '') {
            respondJson($response, 'error', 'user id requerido', [], 400);
            return;
        }
        $role = (string) ($payload['role'] ?? 'viewer');
        $type = (string) ($payload['type'] ?? 'app');
        $tenantId = (string) ($payload['tenant_id'] ?? 'default');
        $label = (string) ($payload['label'] ?? $userId);
        $registry->ensureProject(
            $projectId,
            $manifest['name'] ?? 'Proyecto',
            $manifest['status'] ?? 'draft',
            $manifest['tenant_mode'] ?? 'shared',
            $userId,
            (string) ($manifest['storage_model'] ?? '')
        );
        $registry->touchUser($userId, $role, $type, $tenantId, $label);
        $registry->assignUserToProject($projectId, $userId, $role);
        respondJson($response, 'success', 'Usuario registrado', [
            'project_id' => $projectId,
            'user_id' => $userId,
            'role' => $role,
            'type' => $type,
        ]);
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 500);
        return;
    }
}

if ($route === 'registry/deploys') {
    try {
        $registry = new ProjectRegistry();
        $manifest = $registry->resolveProjectFromManifest();
        $projectId = resolveProjectId();
        if ($projectId === '') {
            $projectId = (string) ($manifest['id'] ?? 'default');
        }
        $deploys = $registry->listDeploys($projectId);
        respondJson($response, 'success', 'OK', [
            'project_id' => $projectId,
            'deploys' => $deploys,
        ]);
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 500);
        return;
    }
}

if ($route === 'registry/deploy') {
    $payload = requestData();
    try {
        $registry = new ProjectRegistry();
        $manifest = $registry->resolveProjectFromManifest();
        $projectId = resolveProjectId($payload);
        if ($projectId === '') {
            $projectId = (string) ($manifest['id'] ?? 'default');
        }
        $name = (string) ($payload['name'] ?? '');
        $env = (string) ($payload['env'] ?? 'dev');
        $url = (string) ($payload['url'] ?? '');
        $status = (string) ($payload['status'] ?? 'pending');
        $deploy = $registry->addDeploy($projectId, $name, $env, $url, $status);
        respondJson($response, 'success', 'Deploy registrado', [
            'deploy' => $deploy
        ]);
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 500);
        return;
    }
}

if ($route === 'registry/projects') {
    try {
        $registry = new ProjectRegistry();
        $manifest = $registry->resolveProjectFromManifest();
        $registry->ensureProject(
            $manifest['id'] ?? 'default',
            $manifest['name'] ?? 'Proyecto',
            $manifest['status'] ?? 'draft',
            $manifest['tenant_mode'] ?? 'shared',
            '',
            (string) ($manifest['storage_model'] ?? '')
        );
        if (session_status() === PHP_SESSION_ACTIVE && empty($_SESSION['current_project_id'])) {
            $_SESSION['current_project_id'] = $manifest['id'] ?? 'default';
        }
        $projects = $registry->listProjects();
        respondJson($response, 'success', 'OK', [
            'projects' => $projects,
            'current' => $_SESSION['current_project_id'] ?? null,
        ]);
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 500);
        return;
    }
}

if ($route === 'registry/capabilities') {
    try {
        $registry = new ProjectRegistry();
        $manifest = $registry->resolveProjectFromManifest();
        $projectId = resolveProjectId();
        if ($projectId === '') {
            $projectId = (string) ($manifest['id'] ?? 'default');
        }
        $mode = (string) ($_GET['mode'] ?? (requestData()['mode'] ?? 'app'));
        $mode = strtolower($mode) === 'builder' ? 'builder' : 'app';
        $sync = syncRegistryEntities($registry, $projectId);
        $capabilities = (new CapabilityGraph(PROJECT_ROOT))->build($projectId, $mode);
        respondJson($response, 'success', 'OK', [
            'project_id' => $projectId,
            'mode' => $mode,
            'sync' => $sync,
            'capabilities' => $capabilities,
        ]);
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 500);
        return;
    }
}

if ($route === 'registry/project') {
    $payload = requestData();
    try {
        $registry = new ProjectRegistry();
        $id = (string) ($payload['id'] ?? '');
        $name = (string) ($payload['name'] ?? '');
        if ($id === '' || $name === '') {
            respondJson($response, 'error', 'id y name requeridos', [], 400);
            return;
        }
        $status = (string) ($payload['status'] ?? 'draft');
        $tenantMode = (string) ($payload['tenant_mode'] ?? 'shared');
        $owner = (string) ($payload['owner_user_id'] ?? '');
        $registry->ensureProject($id, $name, $status, $tenantMode, $owner, (string) ($payload['storage_model'] ?? ''));
        respondJson($response, 'success', 'Proyecto guardado', [
            'project_id' => $id,
        ]);
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 500);
        return;
    }
}

if ($route === 'registry/select') {
    $payload = requestData();
    $projectId = (string) ($payload['project_id'] ?? $_GET['project_id'] ?? '');
    if ($projectId === '') {
        respondJson($response, 'error', 'project_id requerido', [], 400);
        return;
    }
    $_SESSION['current_project_id'] = $projectId;
    respondJson($response, 'success', 'Proyecto activo actualizado', [
        'project_id' => $projectId,
    ]);
    return;
}

if ($route === 'auth/register') {
    $payload = requestData();
    try {
        $registry = new ProjectRegistry();
        $manifest = $registry->resolveProjectFromManifest();
        $projectId = resolveProjectId($payload);
        if ($projectId === '') {
            $projectId = (string) ($manifest['id'] ?? 'default');
        }
        $userId = (string) ($payload['id'] ?? $payload['user_id'] ?? '');
        $password = (string) ($payload['password'] ?? '');
        $role = (string) ($payload['role'] ?? 'admin');
        $tenantId = (string) ($payload['tenant_id'] ?? 'default');
        $label = (string) ($payload['label'] ?? $userId);
        $registry->createAuthUser($projectId, $userId, $password, $role, $tenantId, $label);
        $registry->touchUser($userId, $role, 'auth', $tenantId, $label);
        $registry->assignUserToProject($projectId, $userId, $role);
        respondJson($response, 'success', 'Usuario creado', [
            'project_id' => $projectId,
            'user_id' => $userId,
            'role' => $role,
        ]);
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 500);
        return;
    }
}

if ($route === 'auth/request_code') {
    $payload = requestData();
    try {
        $registry = new ProjectRegistry();
        $manifest = $registry->resolveProjectFromManifest();
        $projectId = (string) ($payload['project_id'] ?? $manifest['id'] ?? 'default');
        $phone = preg_replace('/[^0-9]/', '', (string) ($payload['phone'] ?? ''));
        if ($phone === '') {
            respondJson($response, 'error', 'Telefono requerido', [], 400);
            return;
        }
        $code = (string) random_int(100000, 999999);
        $registry->storeAuthCode($projectId, $phone, $code);
        respondJson($response, 'success', 'Codigo generado (simulado)', [
            'phone' => $phone,
            'code' => $code,
        ]);
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 500);
        return;
    }
}

if ($route === 'auth/verify_code') {
    $payload = requestData();
    try {
        $registry = new ProjectRegistry();
        $manifest = $registry->resolveProjectFromManifest();
        $projectId = (string) ($payload['project_id'] ?? $manifest['id'] ?? 'default');
        $phone = preg_replace('/[^0-9]/', '', (string) ($payload['phone'] ?? ''));
        $code = (string) ($payload['code'] ?? '');
        $role = (string) ($payload['role'] ?? 'admin');
        $tenantId = (string) ($payload['tenant_id'] ?? 'default');
        if ($phone === '' || $code === '') {
            respondJson($response, 'error', 'Telefono y codigo requeridos', [], 400);
            return;
        }
        if (!$registry->verifyAuthCode($projectId, $phone, $code)) {
            respondJson($response, 'error', 'Codigo invalido', [], 401);
            return;
        }
        $registry->createAuthUser($projectId, $phone, $code, $role, $tenantId, $phone);
        $registry->touchUser($phone, $role, 'auth', $tenantId, $phone);
        $registry->assignUserToProject($projectId, $phone, $role);
        respondJson($response, 'success', 'Telefono verificado', [
            'user_id' => $phone,
            'project_id' => $projectId,
            'role' => $role,
            'tenant_id' => $tenantId,
        ]);
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 500);
        return;
    }
}

if ($route === 'auth/login') {
    $payload = requestData();
    try {
        $registry = new ProjectRegistry();
        $manifest = $registry->resolveProjectFromManifest();
        $projectId = resolveProjectId($payload);
        if ($projectId === '') {
            $projectId = (string) ($manifest['id'] ?? 'default');
        }
        $userId = (string) ($payload['id'] ?? $payload['user_id'] ?? '');
        $password = (string) ($payload['password'] ?? '');
        $user = $registry->verifyAuthUser($projectId, $userId, $password);
        if (!$user) {
            respondJson($response, 'error', 'Credenciales invalidas', [], 401);
            return;
        }
        $_SESSION['auth_user'] = [
            'id' => $user['id'],
            'project_id' => $projectId,
            'role' => $user['role'],
            'tenant_id' => $user['tenant_id'],
            'label' => $user['label'],
        ];
        $_SESSION['current_project_id'] = $projectId;
        respondJson($response, 'success', 'Login OK', [
            'user' => $_SESSION['auth_user'],
        ]);
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 500);
        return;
    }
}

if ($route === 'auth/me') {
    $user = $_SESSION['auth_user'] ?? null;
    if (!$user) {
        respondJson($response, 'error', 'No autenticado', [], 401);
        return;
    }
    respondJson($response, 'success', 'OK', [
        'user' => $user
    ]);
    return;
}

if ($route === 'auth/logout') {
    $_SESSION['auth_user'] = null;
    respondJson($response, 'success', 'Logout OK', []);
    return;
}

if ($route === 'auth/users') {
    try {
        $registry = new ProjectRegistry();
        $manifest = $registry->resolveProjectFromManifest();
        $projectId = resolveProjectId();
        if ($projectId === '') {
            $projectId = (string) ($manifest['id'] ?? 'default');
        }
        $users = $registry->listAuthUsers($projectId);
        respondJson($response, 'success', 'OK', [
            'project_id' => $projectId,
            'users' => $users,
        ]);
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 500);
        return;
    }
}

if ($route === 'llm/health') {
    $payload = requestData();
    $mode = (string) ($payload['mode'] ?? $_GET['mode'] ?? 'ping');
    $result = [];
    $providers = [
        'gemini' => ['key' => 'GEMINI_API_KEY', 'type' => 'client'],
        'groq' => ['key' => 'GROQ_API_KEY', 'type' => 'client'],
        'openrouter' => ['key' => 'OPENROUTER_API_KEY', 'type' => 'provider'],
        'claude' => ['key' => 'CLAUDE_API_KEY', 'type' => 'provider'],
    ];
    foreach ($providers as $name => $meta) {
        $hasKey = getenv($meta['key']) ? true : false;
        $entry = ['available' => $hasKey, 'ok' => false, 'message' => ''];
        if (!$hasKey) {
            $entry['message'] = 'Key missing';
            $result[$name] = $entry;
            continue;
        }
        if ($mode !== 'ping') {
            $entry['ok'] = true;
            $entry['message'] = 'Key OK';
            $result[$name] = $entry;
            continue;
        }
        try {
            if ($name === 'gemini') {
                $client = new \App\Core\GeminiClient();
                $client->generate('ping', ['max_tokens' => 5]);
            } elseif ($name === 'groq') {
                $client = new \App\Core\GroqClient();
                $client->chat([['role' => 'user', 'content' => 'ping']], ['max_tokens' => 5]);
            } elseif ($name === 'openrouter') {
                $provider = new \App\Core\LLM\Providers\OpenRouterProvider();
                $provider->sendChat([['role' => 'user', 'content' => 'ping']], ['max_tokens' => 5]);
            } elseif ($name === 'claude') {
                $provider = new \App\Core\LLM\Providers\ClaudeProvider();
                $provider->sendChat([['role' => 'user', 'content' => 'ping']], ['max_tokens' => 5]);
            }
            $entry['ok'] = true;
            $entry['message'] = 'Ping OK';
        } catch (\Throwable $e) {
            $entry['ok'] = false;
            $entry['message'] = $e->getMessage();
        }
        $result[$name] = $entry;
    }
    respondJson($response, 'success', 'Health check', [
        'mode' => $mode,
        'providers' => $result
    ]);
    return;
}

if ($route === 'integrations/action') {
    $payload = requestData();
    setTenantContext($payload);
    $tenantId = (string) ($payload['tenant_id'] ?? $_GET['tenant_id'] ?? getenv('TENANT_KEY') ?? getenv('TENANT_ID') ?? 'default');
    $environment = (string) ($payload['environment'] ?? $_GET['environment'] ?? '');
    $projectId = resolveProjectId($payload);

    try {
        $orchestrator = new IntegrationActionOrchestrator();
        $result = $orchestrator->execute($payload, [
            'tenant_id' => $tenantId,
            'environment' => $environment,
            'project_id' => $projectId,
            'actor_id' => (string) ($_SESSION['user_id'] ?? ''),
            'actor_label' => (string) ($_SESSION['user_name'] ?? ''),
        ]);

        $statusCode = (int) ($result['status'] ?? 200);
        $ok = $statusCode >= 200 && $statusCode < 300;
        respondJson(
            $response,
            $ok ? 'success' : 'error',
            $ok ? 'Accion de integracion ejecutada' : 'Integracion respondio error',
            $result,
            $ok ? 200 : 502
        );
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 500);
        return;
    }
}

if ($route === 'integrations/alanube/test') {
    $payload = requestData();
    $baseUrl = (string) ($payload['base_url'] ?? '');
    $tokenEnv = (string) ($payload['token_env'] ?? 'ALANUBE_TOKEN');
    $token = (string) ($payload['token'] ?? getenv($tokenEnv) ?: '');
    if ($baseUrl === '') {
        respondJson($response, 'error', 'base_url requerido', [], 400);
        return;
    }
    if ($token === '') {
        respondJson($response, 'error', 'Token no encontrado en .env', [], 400);
        return;
    }

    try {
        $client = new AlanubeClient($baseUrl, $token);
        $result = $client->testConnection();
        $status = (int) ($result['status'] ?? 0);
        $ok = ($status >= 200 && $status < 300) || in_array($status, [401, 403, 404], true);
        $message = $ok ? 'Conexion OK (sandbox o prod)' : 'No se pudo conectar';
        respondJson($response, $ok ? 'success' : 'error', $message, [
            'status' => $status,
            'data' => $result['data'] ?? []
        ]);
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 500);
        return;
    }
}

if ($route === 'integrations/alanube/save') {
    $payload = requestData();
    $integration = $payload['integration'] ?? null;
    if (!is_array($integration)) {
        respondJson($response, 'error', 'integration requerida', [], 400);
        return;
    }
    try {
        IntegrationValidator::validateOrFail($integration);
        $id = (string) ($integration['id'] ?? '');
        if ($id === '') {
            respondJson($response, 'error', 'integration.id requerido', [], 400);
            return;
        }
        $path = PROJECT_ROOT . '/contracts/integrations/' . $id . '.integration.json';
        writeJsonFile($path, $integration);

        $store = new IntegrationStore();
        $store->saveConnection($integration);

        respondJson($response, 'success', 'Integracion guardada', [
            'file' => basename($path)
        ]);
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 500);
        return;
    }
}

if ($route === 'integrations/alanube/emit') {
    $payload = requestData();
    $invoiceKey = (string) ($payload['invoice'] ?? '');
    $recordId = $payload['record_id'] ?? null;
    $integrationId = (string) ($payload['integration_id'] ?? 'alanube_main');
    $manualPayload = $payload['payload'] ?? null;

    try {
        $integrations = new IntegrationRegistry();
        $integration = $integrations->get($integrationId);
        $tokenEnv = $integration['auth']['token_env'] ?? 'ALANUBE_TOKEN';
        $token = getenv($tokenEnv) ?: '';
        if ($token === '') {
            respondJson($response, 'error', 'Token no encontrado en .env', [], 400);
            return;
        }

        $client = new AlanubeClient($integration['base_url'], $token);
        $endpoint = (string) ($payload['endpoint'] ?? '/documents');
        $payloadData = [];
        $entityName = null;

        if (is_array($manualPayload)) {
            $payloadData = $manualPayload;
        } elseif ($invoiceKey !== '') {
            $registry = new InvoiceRegistry();
            $invoice = $registry->get($invoiceKey);
            InvoiceValidator::validateOrFail($invoice);
            $entityName = (string) ($invoice['entity'] ?? '');
            if ($entityName === '') {
                respondJson($response, 'error', 'entity requerido en invoice', [], 400);
                return;
            }
            if ($recordId === null || $recordId === '') {
                respondJson($response, 'error', 'record_id requerido', [], 400);
                return;
            }
            $entities = new EntityRegistry();
            $entity = $entities->get($entityName);
            $repo = new \App\Core\BaseRepository($entity);
            $record = $repo->find($recordId);
            if (!$record) {
                respondJson($response, 'error', 'Registro no encontrado', [], 404);
                return;
            }
            $gridName = (string) (($invoice['mapping']['items']['source_grid'] ?? '') ?: '');
            $gridItems = fetchGridItems($entity, $gridName, $recordId);
            $mapper = new InvoiceMapper();
            $payloadData = $mapper->buildPayload($invoice, $record, $gridItems);
            if (!empty($invoice['emit_endpoint'])) {
                $endpoint = $invoice['emit_endpoint'];
            }
        } else {
            respondJson($response, 'error', 'payload o invoice requerido', [], 400);
            return;
        }

        $result = $client->emitDocument($endpoint, $payloadData);
        $externalId = $result['data']['id'] ?? ($result['data']['documentId'] ?? null);

        $store = new IntegrationStore();
        $store->saveDocument($integrationId, $entityName, $recordId ? (string) $recordId : null, $externalId ? (string) $externalId : null, 'sent', $payloadData, $result['data']);

        respondJson($response, 'success', 'Documento emitido', [
            'status' => $result['status'],
            'data' => $result['data'],
            'external_id' => $externalId
        ]);
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 500);
        return;
    }
}

if ($route === 'integrations/alanube/status') {
    $payload = requestData();
    $integrationId = (string) ($payload['integration_id'] ?? $_GET['integration_id'] ?? 'alanube_main');
    $externalId = (string) ($payload['external_id'] ?? $_GET['external_id'] ?? '');
    if ($externalId === '') {
        respondJson($response, 'error', 'external_id requerido', [], 400);
        return;
    }
    try {
        $integrations = new IntegrationRegistry();
        $integration = $integrations->get($integrationId);
        $tokenEnv = $integration['auth']['token_env'] ?? 'ALANUBE_TOKEN';
        $token = getenv($tokenEnv) ?: '';
        if ($token === '') {
            respondJson($response, 'error', 'Token no encontrado en .env', [], 400);
            return;
        }
        $client = new AlanubeClient($integration['base_url'], $token);
        $endpoint = (string) ($payload['endpoint'] ?? '/documents');
        $result = $client->getDocument($endpoint, $externalId);
        $store = new IntegrationStore();
        $store->updateDocumentStatus($integrationId, $externalId, 'status', $result['data']);
        respondJson($response, 'success', 'Estado consultado', $result);
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 500);
        return;
    }
}

if ($route === 'integrations/alanube/cancel') {
    $payload = requestData();
    $integrationId = (string) ($payload['integration_id'] ?? 'alanube_main');
    $externalId = (string) ($payload['external_id'] ?? '');
    if ($externalId === '') {
        respondJson($response, 'error', 'external_id requerido', [], 400);
        return;
    }
    try {
        $integrations = new IntegrationRegistry();
        $integration = $integrations->get($integrationId);
        $tokenEnv = $integration['auth']['token_env'] ?? 'ALANUBE_TOKEN';
        $token = getenv($tokenEnv) ?: '';
        if ($token === '') {
            respondJson($response, 'error', 'Token no encontrado en .env', [], 400);
            return;
        }
        $client = new AlanubeClient($integration['base_url'], $token);
        $endpoint = (string) ($payload['endpoint'] ?? '/documents');
        $result = $client->cancelDocument($endpoint, $externalId, $payload['payload'] ?? []);
        $store = new IntegrationStore();
        $store->updateDocumentStatus($integrationId, $externalId, 'cancelled', $result['data']);
        respondJson($response, 'success', 'Documento anulado', $result);
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 500);
        return;
    }
}

if ($route === 'integrations/alanube/webhook') {
    if ($method !== 'POST') {
        respondJson($response, 'error', 'Metodo no permitido', [], 405);
        return;
    }

    $alanubeSecret = trim((string) (getenv('ALANUBE_WEBHOOK_SECRET') ?: ''));
    if ($alanubeSecret === '' && $webhookSecurityPolicy->shouldRequireSecret()) {
        respondJson($response, 'error', 'Alanube webhook secret requerido por politica de seguridad', [], 403);
        return;
    }

    $rawBody = requestRawBody();
    if ($alanubeSecret !== '' && !verifyAlanubeWebhookRequest($rawBody, $alanubeSecret)) {
        respondJson($response, 'error', 'Alanube webhook signature invalida', [], 401);
        return;
    }

    $payload = requestData();
    $integrationId = (string) ($payload['integration_id'] ?? $_GET['integration_id'] ?? 'alanube_main');
    $event = (string) ($payload['event'] ?? $payload['type'] ?? $payload['action'] ?? '');
    $externalId = null;
    if (isset($payload['id'])) {
        $externalId = (string) $payload['id'];
    } elseif (isset($payload['documentId'])) {
        $externalId = (string) $payload['documentId'];
    } elseif (isset($payload['document']['id'])) {
        $externalId = (string) $payload['document']['id'];
    }

    $fallbackPayload = $rawBody !== '' ? $rawBody : ((string) (json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: ''));
    $replayNonce = $externalId !== null && $externalId !== '' ? ('doc:' . $externalId) : ('hash:' . sha1($fallbackPayload));
    $replayTtl = (int) (getenv('ALANUBE_REPLAY_TTL_SEC') ?: 86400);
    try {
        $fresh = securityStateRepository()->rememberReplayNonce('alanube', $replayNonce, $replayTtl);
        if (!$fresh) {
            respondJson($response, 'success', 'Webhook Alanube duplicado ignorado', [
                'ignored' => true,
                'reason' => 'replay_detected',
                'external_id' => $externalId,
            ]);
            return;
        }
    } catch (\Throwable $e) {
        if ((string) (getenv('API_SECURITY_STRICT') ?: '0') === '1') {
            respondJson($response, 'error', 'No se pudo validar anti-replay de Alanube', [], 503);
            return;
        }
    }

    try {
        $store = new IntegrationStore();
        $store->logWebhook($integrationId, $event ?: null, $externalId, $payload);
        if ($externalId) {
            $store->updateDocumentStatus($integrationId, $externalId, $event ?: 'webhook', $payload);
        }
        respondJson($response, 'success', 'Webhook recibido', ['external_id' => $externalId]);
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 500);
        return;
    }
}

if ($route === 'media/upload') {
    if ($method !== 'POST') {
        respondJson($response, 'error', 'Metodo no permitido', [], 405);
        return;
    }

    $sessionUser = resolveAuthenticatedSessionUser();
    if (empty($sessionUser)) {
        respondJson($response, 'error', 'Acceso no autorizado para este recurso.', [], 401);
        return;
    }

    $tenantId = trim((string) ($sessionUser['tenant_id'] ?? ''));
    if ($tenantId === '') {
        respondJson($response, 'error', 'Acceso no autorizado para este recurso.', [], 403);
        return;
    }

    $payload = requestData();
    $requestedTenant = trim((string) ($payload['tenant_id'] ?? ''));
    if ($requestedTenant !== '' && $requestedTenant !== $tenantId) {
        respondJson($response, 'error', 'Acceso no autorizado para este recurso.', [], 403);
        return;
    }

    $payload['tenant_id'] = $tenantId;
    $payload['project_id'] = (string) ($payload['project_id'] ?? $_GET['project_id'] ?? $sessionUser['project_id'] ?? '');
    if (isset($_FILES['file']) && is_array($_FILES['file']) && (int) ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        $payload['file'] = [
            'source_path' => (string) ($_FILES['file']['tmp_name'] ?? ''),
            'tmp_path' => (string) ($_FILES['file']['tmp_name'] ?? ''),
            'path' => (string) ($_FILES['file']['tmp_name'] ?? ''),
            'original_name' => (string) ($_FILES['file']['name'] ?? ''),
            'name' => (string) ($_FILES['file']['name'] ?? ''),
            'mime_type' => (string) ($_FILES['file']['type'] ?? ''),
            'type' => (string) ($_FILES['file']['type'] ?? ''),
            'file_size' => (int) ($_FILES['file']['size'] ?? 0),
            'size' => (int) ($_FILES['file']['size'] ?? 0),
        ];
    }

    setTenantContext(['tenant_id' => $tenantId], true);
    RoleContext::setRole((string) ($sessionUser['role'] ?? 'admin'));
    RoleContext::setUserId((string) ($sessionUser['id'] ?? 'anon'));
    RoleContext::setUserLabel((string) ($sessionUser['label'] ?? $sessionUser['name'] ?? ''));

    try {
        $media = (new MediaService())->upload($payload + [
            'app_id' => $payload['project_id'] !== '' ? $payload['project_id'] : null,
            'uploaded_by_user_id' => (string) ($sessionUser['id'] ?? 'anon'),
        ]);
        respondJson($response, 'success', 'Archivo subido', ['media' => $media, 'item' => $media]);
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 422);
        return;
    }
}

if ($route === 'media/list') {
    if (!in_array($method, ['GET', 'POST'], true)) {
        respondJson($response, 'error', 'Metodo no permitido', [], 405);
        return;
    }

    $sessionUser = resolveAuthenticatedSessionUser();
    if (empty($sessionUser)) {
        respondJson($response, 'error', 'Acceso no autorizado para este recurso.', [], 401);
        return;
    }

    $tenantId = trim((string) ($sessionUser['tenant_id'] ?? ''));
    $payload = $method === 'GET' ? $_GET : requestData();
    setTenantContext(['tenant_id' => $tenantId], true);
    RoleContext::setRole((string) ($sessionUser['role'] ?? 'admin'));
    RoleContext::setUserId((string) ($sessionUser['id'] ?? 'anon'));
    RoleContext::setUserLabel((string) ($sessionUser['label'] ?? $sessionUser['name'] ?? ''));

    try {
        $items = (new MediaService())->list(
            $tenantId,
            (string) ($payload['entity_type'] ?? ''),
            (string) ($payload['entity_id'] ?? ''),
            trim((string) ($payload['project_id'] ?? $sessionUser['project_id'] ?? '')) ?: null,
            isset($payload['limit']) ? (int) $payload['limit'] : 50,
            isset($payload['offset']) ? (int) $payload['offset'] : 0
        );
        respondJson($response, 'success', 'Archivos cargados', ['items' => $items]);
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 422);
        return;
    }
}

if ($route === 'media/get') {
    if ($method !== 'GET') {
        respondJson($response, 'error', 'Metodo no permitido', [], 405);
        return;
    }

    $sessionUser = resolveAuthenticatedSessionUser();
    if (empty($sessionUser)) {
        respondJson($response, 'error', 'Acceso no autorizado para este recurso.', [], 401);
        return;
    }

    $tenantId = trim((string) ($sessionUser['tenant_id'] ?? ''));
    setTenantContext(['tenant_id' => $tenantId], true);
    RoleContext::setRole((string) ($sessionUser['role'] ?? 'admin'));
    RoleContext::setUserId((string) ($sessionUser['id'] ?? 'anon'));
    RoleContext::setUserLabel((string) ($sessionUser['label'] ?? $sessionUser['name'] ?? ''));

    try {
        $mediaId = (string) ($_GET['id'] ?? $_GET['media_id'] ?? '');
        $media = (new MediaService())->get(
            $tenantId,
            $mediaId,
            trim((string) ($_GET['project_id'] ?? $sessionUser['project_id'] ?? '')) ?: null
        );
        respondJson($response, 'success', 'Archivo cargado', ['media' => $media, 'item' => $media]);
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 404);
        return;
    }
}

if ($route === 'media/delete') {
    if (!in_array($method, ['DELETE', 'POST'], true)) {
        respondJson($response, 'error', 'Metodo no permitido', [], 405);
        return;
    }

    $sessionUser = resolveAuthenticatedSessionUser();
    if (empty($sessionUser)) {
        respondJson($response, 'error', 'Acceso no autorizado para este recurso.', [], 401);
        return;
    }

    $tenantId = trim((string) ($sessionUser['tenant_id'] ?? ''));
    $payload = array_merge($_GET, requestData());
    setTenantContext(['tenant_id' => $tenantId], true);
    RoleContext::setRole((string) ($sessionUser['role'] ?? 'admin'));
    RoleContext::setUserId((string) ($sessionUser['id'] ?? 'anon'));
    RoleContext::setUserLabel((string) ($sessionUser['label'] ?? $sessionUser['name'] ?? ''));

    try {
        $mediaId = (string) ($payload['id'] ?? $payload['media_id'] ?? '');
        $result = (new MediaService())->delete(
            $tenantId,
            $mediaId,
            trim((string) ($payload['project_id'] ?? $sessionUser['project_id'] ?? '')) ?: null,
            (string) ($sessionUser['id'] ?? 'anon')
        );
        respondJson($response, 'success', 'Archivo eliminado', $result);
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 404);
        return;
    }
}

if ($route === 'media/thumbnail') {
    if ($method !== 'POST') {
        respondJson($response, 'error', 'Metodo no permitido', [], 405);
        return;
    }

    $sessionUser = resolveAuthenticatedSessionUser();
    if (empty($sessionUser)) {
        respondJson($response, 'error', 'Acceso no autorizado para este recurso.', [], 401);
        return;
    }

    $tenantId = trim((string) ($sessionUser['tenant_id'] ?? ''));
    $payload = requestData();
    setTenantContext(['tenant_id' => $tenantId], true);
    RoleContext::setRole((string) ($sessionUser['role'] ?? 'admin'));
    RoleContext::setUserId((string) ($sessionUser['id'] ?? 'anon'));
    RoleContext::setUserLabel((string) ($sessionUser['label'] ?? $sessionUser['name'] ?? ''));

    try {
        $mediaId = (string) ($payload['id'] ?? $payload['media_id'] ?? '');
        $media = (new MediaService())->generateThumbnail(
            $tenantId,
            $mediaId,
            trim((string) ($payload['project_id'] ?? $sessionUser['project_id'] ?? '')) ?: null
        );
        respondJson($response, 'success', 'Miniatura generada', ['media' => $media, 'item' => $media]);
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 422);
        return;
    }
}

if ($route === 'entity-search/search') {
    if (!in_array($method, ['GET', 'POST'], true)) {
        respondJson($response, 'error', 'Metodo no permitido', [], 405);
        return;
    }

    $sessionUser = resolveAuthenticatedSessionUser();
    if (empty($sessionUser)) {
        respondJson($response, 'error', 'Acceso no autorizado para este recurso.', [], 401);
        return;
    }

    $tenantId = trim((string) ($sessionUser['tenant_id'] ?? ''));
    $payload = array_merge($_GET, requestData());
    setTenantContext(['tenant_id' => $tenantId], true);
    RoleContext::setRole((string) ($sessionUser['role'] ?? 'admin'));
    RoleContext::setUserId((string) ($sessionUser['id'] ?? 'anon'));
    RoleContext::setUserLabel((string) ($sessionUser['label'] ?? $sessionUser['name'] ?? ''));

    try {
        $filters = is_array($payload['filters'] ?? null) ? (array) $payload['filters'] : [];
        foreach (['entity_type', 'date_from', 'date_to', 'limit', 'only_open', 'only_pending', 'recency_hint'] as $key) {
            if (array_key_exists($key, $payload) && !array_key_exists($key, $filters)) {
                $filters[$key] = $payload[$key];
            }
        }
        $projectId = trim((string) ($payload['project_id'] ?? $sessionUser['project_id'] ?? ''));
        $result = (new EntitySearchService())->search(
            $tenantId,
            trim((string) ($payload['query'] ?? $payload['q'] ?? $payload['text'] ?? '')),
            $filters,
            $projectId !== '' ? $projectId : null
        );
        respondJson($response, 'success', 'Busqueda ejecutada', [
            'search' => $result,
            'results' => $result['results'] ?? [],
            'result_count' => $result['result_count'] ?? 0,
        ]);
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 422);
        return;
    }
}

if ($route === 'entity-search/resolve') {
    if (!in_array($method, ['GET', 'POST'], true)) {
        respondJson($response, 'error', 'Metodo no permitido', [], 405);
        return;
    }

    $sessionUser = resolveAuthenticatedSessionUser();
    if (empty($sessionUser)) {
        respondJson($response, 'error', 'Acceso no autorizado para este recurso.', [], 401);
        return;
    }

    $tenantId = trim((string) ($sessionUser['tenant_id'] ?? ''));
    $payload = array_merge($_GET, requestData());
    setTenantContext(['tenant_id' => $tenantId], true);
    RoleContext::setRole((string) ($sessionUser['role'] ?? 'admin'));
    RoleContext::setUserId((string) ($sessionUser['id'] ?? 'anon'));
    RoleContext::setUserLabel((string) ($sessionUser['label'] ?? $sessionUser['name'] ?? ''));

    try {
        $filters = is_array($payload['filters'] ?? null) ? (array) $payload['filters'] : [];
        foreach (['entity_type', 'date_from', 'date_to', 'limit', 'only_open', 'only_pending', 'recency_hint'] as $key) {
            if (array_key_exists($key, $payload) && !array_key_exists($key, $filters)) {
                $filters[$key] = $payload[$key];
            }
        }
        $projectId = trim((string) ($payload['project_id'] ?? $sessionUser['project_id'] ?? ''));
        $result = (new EntitySearchService())->resolveBestMatch(
            $tenantId,
            trim((string) ($payload['query'] ?? $payload['q'] ?? $payload['text'] ?? '')),
            $filters,
            $projectId !== '' ? $projectId : null
        );
        respondJson($response, 'success', 'Referencia resuelta', [
            'resolution' => $result,
            'result' => $result['result'] ?? null,
            'candidates' => $result['candidates'] ?? [],
            'resolved' => $result['resolved'] ?? false,
        ]);
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 422);
        return;
    }
}

if ($route === 'entity-search/get') {
    if (!in_array($method, ['GET', 'POST'], true)) {
        respondJson($response, 'error', 'Metodo no permitido', [], 405);
        return;
    }

    $sessionUser = resolveAuthenticatedSessionUser();
    if (empty($sessionUser)) {
        respondJson($response, 'error', 'Acceso no autorizado para este recurso.', [], 401);
        return;
    }

    $tenantId = trim((string) ($sessionUser['tenant_id'] ?? ''));
    $payload = array_merge($_GET, requestData());
    setTenantContext(['tenant_id' => $tenantId], true);
    RoleContext::setRole((string) ($sessionUser['role'] ?? 'admin'));
    RoleContext::setUserId((string) ($sessionUser['id'] ?? 'anon'));
    RoleContext::setUserLabel((string) ($sessionUser['label'] ?? $sessionUser['name'] ?? ''));

    try {
        $filters = is_array($payload['filters'] ?? null) ? (array) $payload['filters'] : [];
        $projectId = trim((string) ($payload['project_id'] ?? $sessionUser['project_id'] ?? ''));
        $result = (new EntitySearchService())->getByReference(
            $tenantId,
            trim((string) ($payload['entity_type'] ?? '')),
            trim((string) ($payload['entity_id'] ?? $payload['id'] ?? '')),
            $filters,
            $projectId !== '' ? $projectId : null
        );
        if (!is_array($result)) {
            respondJson($response, 'error', 'Referencia no encontrada', [], 404);
            return;
        }
        respondJson($response, 'success', 'Referencia cargada', ['result' => $result, 'item' => $result]);
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 404);
        return;
    }
}

if ($route === 'pos/create-draft') {
    if ($method !== 'POST') {
        respondJson($response, 'error', 'Metodo no permitido', [], 405);
        return;
    }

    $sessionUser = resolveAuthenticatedSessionUser();
    if (empty($sessionUser)) {
        respondJson($response, 'error', 'Acceso no autorizado para este recurso.', [], 401);
        return;
    }

    $tenantId = trim((string) ($sessionUser['tenant_id'] ?? ''));
    $payload = requestData();
    if (($payload['tenant_id'] ?? '') !== '' && trim((string) $payload['tenant_id']) !== $tenantId) {
        respondJson($response, 'error', 'Acceso no autorizado para este recurso.', [], 403);
        return;
    }

    setTenantContext(['tenant_id' => $tenantId], true);
    RoleContext::setRole((string) ($sessionUser['role'] ?? 'admin'));
    RoleContext::setUserId((string) ($sessionUser['id'] ?? 'anon'));
    RoleContext::setUserLabel((string) ($sessionUser['label'] ?? $sessionUser['name'] ?? ''));

    try {
        $draft = (new POSService())->createDraft($payload + [
            'tenant_id' => $tenantId,
            'app_id' => trim((string) ($payload['project_id'] ?? $sessionUser['project_id'] ?? '')) ?: null,
        ]);
        respondJson($response, 'success', 'Borrador POS creado', ['draft' => $draft, 'item' => $draft]);
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 422);
        return;
    }
}

if ($route === 'pos/get-draft') {
    if (!in_array($method, ['GET', 'POST'], true)) {
        respondJson($response, 'error', 'Metodo no permitido', [], 405);
        return;
    }

    $sessionUser = resolveAuthenticatedSessionUser();
    if (empty($sessionUser)) {
        respondJson($response, 'error', 'Acceso no autorizado para este recurso.', [], 401);
        return;
    }

    $tenantId = trim((string) ($sessionUser['tenant_id'] ?? ''));
    $payload = array_merge($_GET, requestData());
    setTenantContext(['tenant_id' => $tenantId], true);
    RoleContext::setRole((string) ($sessionUser['role'] ?? 'admin'));
    RoleContext::setUserId((string) ($sessionUser['id'] ?? 'anon'));
    RoleContext::setUserLabel((string) ($sessionUser['label'] ?? $sessionUser['name'] ?? ''));

    try {
        $draftId = trim((string) ($payload['draft_id'] ?? $payload['sale_draft_id'] ?? ''));
        $draft = (new POSService())->getDraft(
            $tenantId,
            $draftId,
            trim((string) ($payload['project_id'] ?? $sessionUser['project_id'] ?? '')) ?: null
        );
        respondJson($response, 'success', 'Borrador POS cargado', ['draft' => $draft, 'item' => $draft]);
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 404);
        return;
    }
}

if ($route === 'pos/find-product') {
    if (!in_array($method, ['GET', 'POST'], true)) {
        respondJson($response, 'error', 'Metodo no permitido', [], 405);
        return;
    }

    $sessionUser = resolveAuthenticatedSessionUser();
    if (empty($sessionUser)) {
        respondJson($response, 'error', 'Acceso no autorizado para este recurso.', [], 401);
        return;
    }

    $tenantId = trim((string) ($sessionUser['tenant_id'] ?? ''));
    $payload = array_merge($_GET, requestData());
    setTenantContext(['tenant_id' => $tenantId], true);
    RoleContext::setRole((string) ($sessionUser['role'] ?? 'admin'));
    RoleContext::setUserId((string) ($sessionUser['id'] ?? 'anon'));
    RoleContext::setUserLabel((string) ($sessionUser['label'] ?? $sessionUser['name'] ?? ''));

    try {
        $result = (new POSService())->resolveProductForPOS($payload + [
            'tenant_id' => $tenantId,
            'app_id' => trim((string) ($payload['project_id'] ?? $sessionUser['project_id'] ?? '')) ?: null,
        ]);
        $data = [
            'result' => $result['result'] ?? null,
            'item' => $result['result'] ?? null,
            'candidates' => $result['candidates'] ?? [],
            'items' => (bool) ($result['resolved'] ?? false)
                ? [($result['result'] ?? null)]
                : ($result['candidates'] ?? []),
            'resolved' => (bool) ($result['resolved'] ?? false),
            'needs_clarification' => (bool) ($result['needs_clarification'] ?? false),
            'result_status' => (string) ($result['result_status'] ?? 'not_found'),
            'matched_product_id' => (string) ($result['matched_product_id'] ?? ''),
            'matched_by' => (string) ($result['matched_by'] ?? ''),
            'product_query' => (string) ($result['product_query'] ?? ''),
        ];
        respondJson($response, 'success', 'Busqueda POS ejecutada', $data);
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 422);
        return;
    }
}

if ($route === 'pos/get-product-candidates') {
    if (!in_array($method, ['GET', 'POST'], true)) {
        respondJson($response, 'error', 'Metodo no permitido', [], 405);
        return;
    }

    $sessionUser = resolveAuthenticatedSessionUser();
    if (empty($sessionUser)) {
        respondJson($response, 'error', 'Acceso no autorizado para este recurso.', [], 401);
        return;
    }

    $tenantId = trim((string) ($sessionUser['tenant_id'] ?? ''));
    $payload = array_merge($_GET, requestData());
    setTenantContext(['tenant_id' => $tenantId], true);
    RoleContext::setRole((string) ($sessionUser['role'] ?? 'admin'));
    RoleContext::setUserId((string) ($sessionUser['id'] ?? 'anon'));
    RoleContext::setUserLabel((string) ($sessionUser['label'] ?? $sessionUser['name'] ?? ''));

    try {
        $result = (new POSService())->getProductCandidatesForPOS($payload + [
            'tenant_id' => $tenantId,
            'app_id' => trim((string) ($payload['project_id'] ?? $sessionUser['project_id'] ?? '')) ?: null,
        ]);
        respondJson($response, 'success', 'Candidatos POS cargados', [
            'items' => $result['items'] ?? [],
            'candidates' => $result['candidates'] ?? [],
            'result' => $result['result'] ?? null,
            'item' => $result['result'] ?? null,
            'resolved' => (bool) ($result['resolved'] ?? false),
            'needs_clarification' => (bool) ($result['needs_clarification'] ?? false),
            'result_status' => (string) ($result['result_status'] ?? 'not_found'),
            'matched_product_id' => (string) ($result['matched_product_id'] ?? ''),
            'matched_by' => (string) ($result['matched_by'] ?? ''),
            'product_query' => (string) ($result['query'] ?? ''),
            'result_count' => (int) ($result['result_count'] ?? 0),
        ]);
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 422);
        return;
    }
}

if ($route === 'pos/add-draft-line') {
    if ($method !== 'POST') {
        respondJson($response, 'error', 'Metodo no permitido', [], 405);
        return;
    }

    $sessionUser = resolveAuthenticatedSessionUser();
    if (empty($sessionUser)) {
        respondJson($response, 'error', 'Acceso no autorizado para este recurso.', [], 401);
        return;
    }

    $tenantId = trim((string) ($sessionUser['tenant_id'] ?? ''));
    $payload = requestData();
    setTenantContext(['tenant_id' => $tenantId], true);
    RoleContext::setRole((string) ($sessionUser['role'] ?? 'admin'));
    RoleContext::setUserId((string) ($sessionUser['id'] ?? 'anon'));
    RoleContext::setUserLabel((string) ($sessionUser['label'] ?? $sessionUser['name'] ?? ''));

    try {
        $draft = (new POSService())->addLineToDraft($payload + [
            'tenant_id' => $tenantId,
            'app_id' => trim((string) ($payload['project_id'] ?? $sessionUser['project_id'] ?? '')) ?: null,
        ]);
        respondJson($response, 'success', 'Linea POS agregada', ['draft' => $draft, 'item' => $draft]);
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 422);
        return;
    }
}

if ($route === 'pos/add-line-by-reference') {
    if ($method !== 'POST') {
        respondJson($response, 'error', 'Metodo no permitido', [], 405);
        return;
    }

    $sessionUser = resolveAuthenticatedSessionUser();
    if (empty($sessionUser)) {
        respondJson($response, 'error', 'Acceso no autorizado para este recurso.', [], 401);
        return;
    }

    $tenantId = trim((string) ($sessionUser['tenant_id'] ?? ''));
    $payload = requestData();
    setTenantContext(['tenant_id' => $tenantId], true);
    RoleContext::setRole((string) ($sessionUser['role'] ?? 'admin'));
    RoleContext::setUserId((string) ($sessionUser['id'] ?? 'anon'));
    RoleContext::setUserLabel((string) ($sessionUser['label'] ?? $sessionUser['name'] ?? ''));

    try {
        $service = new POSService();
        $resolution = $service->resolveProductForPOS($payload + [
            'tenant_id' => $tenantId,
            'app_id' => trim((string) ($payload['project_id'] ?? $sessionUser['project_id'] ?? '')) ?: null,
        ]);
        if (!(bool) ($resolution['resolved'] ?? false)) {
            respondJson($response, 'success', 'Producto no resuelto con seguridad', [
                'draft_id' => trim((string) ($payload['draft_id'] ?? $payload['sale_draft_id'] ?? '')),
                'candidates' => $resolution['candidates'] ?? [],
                'items' => $resolution['candidates'] ?? [],
                'resolved' => false,
                'needs_clarification' => (bool) ($resolution['needs_clarification'] ?? false),
                'result_status' => (string) ($resolution['result_status'] ?? 'not_found'),
                'matched_product_id' => (string) ($resolution['matched_product_id'] ?? ''),
                'matched_by' => (string) ($resolution['matched_by'] ?? ''),
                'product_query' => (string) ($resolution['product_query'] ?? ''),
            ]);
            return;
        }

        $resolvedProduct = is_array($resolution['result'] ?? null) ? (array) $resolution['result'] : [];
        $draft = $service->addLineByProductReference($payload + [
            'tenant_id' => $tenantId,
            'app_id' => trim((string) ($payload['project_id'] ?? $sessionUser['project_id'] ?? '')) ?: null,
            'product_id' => (string) ($resolvedProduct['entity_id'] ?? ''),
        ]);
        respondJson($response, 'success', 'Linea POS agregada por referencia', ['draft' => $draft, 'item' => $draft]);
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 422);
        return;
    }
}

if ($route === 'pos/remove-draft-line') {
    if (!in_array($method, ['DELETE', 'POST'], true)) {
        respondJson($response, 'error', 'Metodo no permitido', [], 405);
        return;
    }

    $sessionUser = resolveAuthenticatedSessionUser();
    if (empty($sessionUser)) {
        respondJson($response, 'error', 'Acceso no autorizado para este recurso.', [], 401);
        return;
    }

    $tenantId = trim((string) ($sessionUser['tenant_id'] ?? ''));
    $payload = array_merge($_GET, requestData());
    setTenantContext(['tenant_id' => $tenantId], true);
    RoleContext::setRole((string) ($sessionUser['role'] ?? 'admin'));
    RoleContext::setUserId((string) ($sessionUser['id'] ?? 'anon'));
    RoleContext::setUserLabel((string) ($sessionUser['label'] ?? $sessionUser['name'] ?? ''));

    try {
        $draft = (new POSService())->removeLineFromDraft(
            $tenantId,
            trim((string) ($payload['draft_id'] ?? $payload['sale_draft_id'] ?? '')),
            trim((string) ($payload['line_id'] ?? '')),
            trim((string) ($payload['project_id'] ?? $sessionUser['project_id'] ?? '')) ?: null
        );
        respondJson($response, 'success', 'Linea POS eliminada', ['draft' => $draft, 'item' => $draft]);
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 422);
        return;
    }
}

if ($route === 'pos/attach-customer') {
    if ($method !== 'POST') {
        respondJson($response, 'error', 'Metodo no permitido', [], 405);
        return;
    }

    $sessionUser = resolveAuthenticatedSessionUser();
    if (empty($sessionUser)) {
        respondJson($response, 'error', 'Acceso no autorizado para este recurso.', [], 401);
        return;
    }

    $tenantId = trim((string) ($sessionUser['tenant_id'] ?? ''));
    $payload = requestData();
    setTenantContext(['tenant_id' => $tenantId], true);
    RoleContext::setRole((string) ($sessionUser['role'] ?? 'admin'));
    RoleContext::setUserId((string) ($sessionUser['id'] ?? 'anon'));
    RoleContext::setUserLabel((string) ($sessionUser['label'] ?? $sessionUser['name'] ?? ''));

    try {
        $draft = (new POSService())->attachCustomerToDraft($payload + [
            'tenant_id' => $tenantId,
            'app_id' => trim((string) ($payload['project_id'] ?? $sessionUser['project_id'] ?? '')) ?: null,
        ]);
        respondJson($response, 'success', 'Cliente POS asociado', ['draft' => $draft, 'item' => $draft]);
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 422);
        return;
    }
}

if ($route === 'pos/list-open-drafts') {
    if (!in_array($method, ['GET', 'POST'], true)) {
        respondJson($response, 'error', 'Metodo no permitido', [], 405);
        return;
    }

    $sessionUser = resolveAuthenticatedSessionUser();
    if (empty($sessionUser)) {
        respondJson($response, 'error', 'Acceso no autorizado para este recurso.', [], 401);
        return;
    }

    $tenantId = trim((string) ($sessionUser['tenant_id'] ?? ''));
    $payload = array_merge($_GET, requestData());
    setTenantContext(['tenant_id' => $tenantId], true);
    RoleContext::setRole((string) ($sessionUser['role'] ?? 'admin'));
    RoleContext::setUserId((string) ($sessionUser['id'] ?? 'anon'));
    RoleContext::setUserLabel((string) ($sessionUser['label'] ?? $sessionUser['name'] ?? ''));

    try {
        $items = (new POSService())->listOpenDrafts(
            $tenantId,
            trim((string) ($payload['project_id'] ?? $sessionUser['project_id'] ?? '')) ?: null,
            isset($payload['limit']) ? (int) $payload['limit'] : 10
        );
        respondJson($response, 'success', 'Borradores POS cargados', ['items' => $items, 'result_count' => count($items)]);
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 422);
        return;
    }
}

if ($route === 'pos/reprice-draft') {
    if ($method !== 'POST') {
        respondJson($response, 'error', 'Metodo no permitido', [], 405);
        return;
    }

    $sessionUser = resolveAuthenticatedSessionUser();
    if (empty($sessionUser)) {
        respondJson($response, 'error', 'Acceso no autorizado para este recurso.', [], 401);
        return;
    }

    $tenantId = trim((string) ($sessionUser['tenant_id'] ?? ''));
    $payload = requestData();
    setTenantContext(['tenant_id' => $tenantId], true);
    RoleContext::setRole((string) ($sessionUser['role'] ?? 'admin'));
    RoleContext::setUserId((string) ($sessionUser['id'] ?? 'anon'));
    RoleContext::setUserLabel((string) ($sessionUser['label'] ?? $sessionUser['name'] ?? ''));

    try {
        $draftId = trim((string) ($payload['draft_id'] ?? $payload['sale_draft_id'] ?? ''));
        $draft = (new POSService())->repriceDraft(
            $tenantId,
            $draftId,
            trim((string) ($payload['project_id'] ?? $sessionUser['project_id'] ?? '')) ?: null,
            $payload
        );
        respondJson($response, 'success', 'Borrador POS recalculado', ['draft' => $draft, 'item' => $draft]);
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 422);
        return;
    }
}

if ($route === 'pos/finalize-sale') {
    if ($method !== 'POST') {
        respondJson($response, 'error', 'Metodo no permitido', [], 405);
        return;
    }

    $sessionUser = resolveAuthenticatedSessionUser();
    if (empty($sessionUser)) {
        respondJson($response, 'error', 'Acceso no autorizado para este recurso.', [], 401);
        return;
    }

    $tenantId = trim((string) ($sessionUser['tenant_id'] ?? ''));
    $payload = requestData();
    setTenantContext(['tenant_id' => $tenantId], true);
    RoleContext::setRole((string) ($sessionUser['role'] ?? 'admin'));
    RoleContext::setUserId((string) ($sessionUser['id'] ?? 'anon'));
    RoleContext::setUserLabel((string) ($sessionUser['label'] ?? $sessionUser['name'] ?? ''));

    try {
        $result = (new POSService())->finalizeDraftSale($payload + [
            'tenant_id' => $tenantId,
            'app_id' => trim((string) ($payload['project_id'] ?? $sessionUser['project_id'] ?? '')) ?: null,
            'created_by_user_id' => (string) ($sessionUser['id'] ?? 'anon'),
        ]);
        respondJson($response, 'success', 'Venta POS finalizada', $result + ['item' => $result['sale'] ?? null]);
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 422);
        return;
    }
}

if ($route === 'pos/get-sale') {
    if (!in_array($method, ['GET', 'POST'], true)) {
        respondJson($response, 'error', 'Metodo no permitido', [], 405);
        return;
    }

    $sessionUser = resolveAuthenticatedSessionUser();
    if (empty($sessionUser)) {
        respondJson($response, 'error', 'Acceso no autorizado para este recurso.', [], 401);
        return;
    }

    $tenantId = trim((string) ($sessionUser['tenant_id'] ?? ''));
    $payload = array_merge($_GET, requestData());
    setTenantContext(['tenant_id' => $tenantId], true);
    RoleContext::setRole((string) ($sessionUser['role'] ?? 'admin'));
    RoleContext::setUserId((string) ($sessionUser['id'] ?? 'anon'));
    RoleContext::setUserLabel((string) ($sessionUser['label'] ?? $sessionUser['name'] ?? ''));

    try {
        $sale = (new POSService())->getSale(
            $tenantId,
            trim((string) ($payload['sale_id'] ?? $payload['id'] ?? '')),
            trim((string) ($payload['project_id'] ?? $sessionUser['project_id'] ?? '')) ?: null
        );
        respondJson($response, 'success', 'Venta POS cargada', ['sale' => $sale, 'item' => $sale]);
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 404);
        return;
    }
}

if ($route === 'pos/list-sales') {
    if (!in_array($method, ['GET', 'POST'], true)) {
        respondJson($response, 'error', 'Metodo no permitido', [], 405);
        return;
    }

    $sessionUser = resolveAuthenticatedSessionUser();
    if (empty($sessionUser)) {
        respondJson($response, 'error', 'Acceso no autorizado para este recurso.', [], 401);
        return;
    }

    $tenantId = trim((string) ($sessionUser['tenant_id'] ?? ''));
    $payload = array_merge($_GET, requestData());
    setTenantContext(['tenant_id' => $tenantId], true);
    RoleContext::setRole((string) ($sessionUser['role'] ?? 'admin'));
    RoleContext::setUserId((string) ($sessionUser['id'] ?? 'anon'));
    RoleContext::setUserLabel((string) ($sessionUser['label'] ?? $sessionUser['name'] ?? ''));

    try {
        $items = (new POSService())->listSales(
            $tenantId,
            $payload,
            trim((string) ($payload['project_id'] ?? $sessionUser['project_id'] ?? '')) ?: null
        );
        respondJson($response, 'success', 'Ventas POS cargadas', ['items' => $items, 'result_count' => count($items)]);
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 422);
        return;
    }
}

if ($route === 'pos/build-receipt') {
    if (!in_array($method, ['GET', 'POST'], true)) {
        respondJson($response, 'error', 'Metodo no permitido', [], 405);
        return;
    }

    $sessionUser = resolveAuthenticatedSessionUser();
    if (empty($sessionUser)) {
        respondJson($response, 'error', 'Acceso no autorizado para este recurso.', [], 401);
        return;
    }

    $tenantId = trim((string) ($sessionUser['tenant_id'] ?? ''));
    $payload = array_merge($_GET, requestData());
    setTenantContext(['tenant_id' => $tenantId], true);
    RoleContext::setRole((string) ($sessionUser['role'] ?? 'admin'));
    RoleContext::setUserId((string) ($sessionUser['id'] ?? 'anon'));
    RoleContext::setUserLabel((string) ($sessionUser['label'] ?? $sessionUser['name'] ?? ''));

    try {
        $service = new POSService();
        $appId = trim((string) ($payload['project_id'] ?? $sessionUser['project_id'] ?? '')) ?: null;
        $saleId = trim((string) ($payload['sale_id'] ?? $payload['id'] ?? ''));
        if ($saleId === '') {
            $sale = $service->getSaleByNumber(
                $tenantId,
                trim((string) ($payload['sale_number'] ?? $payload['number'] ?? '')),
                $appId
            );
            $saleId = (string) ($sale['id'] ?? '');
        }
        $receipt = $service->buildReceiptPayload($tenantId, $saleId, $appId);
        respondJson($response, 'success', 'Ticket POS preparado', ['receipt' => $receipt, 'item' => $receipt]);
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 422);
        return;
    }
}

if ($route === 'pos/get-sale-by-number') {
    if (!in_array($method, ['GET', 'POST'], true)) {
        respondJson($response, 'error', 'Metodo no permitido', [], 405);
        return;
    }

    $sessionUser = resolveAuthenticatedSessionUser();
    if (empty($sessionUser)) {
        respondJson($response, 'error', 'Acceso no autorizado para este recurso.', [], 401);
        return;
    }

    $tenantId = trim((string) ($sessionUser['tenant_id'] ?? ''));
    $payload = array_merge($_GET, requestData());
    setTenantContext(['tenant_id' => $tenantId], true);
    RoleContext::setRole((string) ($sessionUser['role'] ?? 'admin'));
    RoleContext::setUserId((string) ($sessionUser['id'] ?? 'anon'));
    RoleContext::setUserLabel((string) ($sessionUser['label'] ?? $sessionUser['name'] ?? ''));

    try {
        $sale = (new POSService())->getSaleByNumber(
            $tenantId,
            trim((string) ($payload['sale_number'] ?? $payload['number'] ?? '')),
            trim((string) ($payload['project_id'] ?? $sessionUser['project_id'] ?? '')) ?: null
        );
        respondJson($response, 'success', 'Venta POS cargada por numero', ['sale' => $sale, 'item' => $sale]);
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 404);
        return;
    }
}

if ($route === 'pos/cancel-sale') {
    if ($method !== 'POST') {
        respondJson($response, 'error', 'Metodo no permitido', [], 405);
        return;
    }

    $sessionUser = resolveAuthenticatedSessionUser();
    if (empty($sessionUser)) {
        respondJson($response, 'error', 'Acceso no autorizado para este recurso.', [], 401);
        return;
    }

    $tenantId = trim((string) ($sessionUser['tenant_id'] ?? ''));
    $payload = requestData();
    if (($payload['tenant_id'] ?? '') !== '' && trim((string) $payload['tenant_id']) !== $tenantId) {
        respondJson($response, 'error', 'Acceso no autorizado para este recurso.', [], 403);
        return;
    }

    setTenantContext(['tenant_id' => $tenantId], true);
    RoleContext::setRole((string) ($sessionUser['role'] ?? 'admin'));
    RoleContext::setUserId((string) ($sessionUser['id'] ?? 'anon'));
    RoleContext::setUserLabel((string) ($sessionUser['label'] ?? $sessionUser['name'] ?? ''));

    try {
        $result = (new POSService())->cancelSale($payload + [
            'tenant_id' => $tenantId,
            'app_id' => trim((string) ($payload['project_id'] ?? $sessionUser['project_id'] ?? '')) ?: null,
            'created_by_user_id' => (string) ($sessionUser['id'] ?? 'anon'),
        ]);
        respondJson($response, 'success', 'Venta POS cancelada', $result + ['item' => $result['sale'] ?? null]);
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 422);
        return;
    }
}

if ($route === 'pos/create-return') {
    if ($method !== 'POST') {
        respondJson($response, 'error', 'Metodo no permitido', [], 405);
        return;
    }

    $sessionUser = resolveAuthenticatedSessionUser();
    if (empty($sessionUser)) {
        respondJson($response, 'error', 'Acceso no autorizado para este recurso.', [], 401);
        return;
    }

    $tenantId = trim((string) ($sessionUser['tenant_id'] ?? ''));
    $payload = requestData();
    if (($payload['tenant_id'] ?? '') !== '' && trim((string) $payload['tenant_id']) !== $tenantId) {
        respondJson($response, 'error', 'Acceso no autorizado para este recurso.', [], 403);
        return;
    }

    setTenantContext(['tenant_id' => $tenantId], true);
    RoleContext::setRole((string) ($sessionUser['role'] ?? 'admin'));
    RoleContext::setUserId((string) ($sessionUser['id'] ?? 'anon'));
    RoleContext::setUserLabel((string) ($sessionUser['label'] ?? $sessionUser['name'] ?? ''));

    try {
        $result = (new POSService())->createReturnFromSale($payload + [
            'tenant_id' => $tenantId,
            'app_id' => trim((string) ($payload['project_id'] ?? $sessionUser['project_id'] ?? '')) ?: null,
            'created_by_user_id' => (string) ($sessionUser['id'] ?? 'anon'),
        ]);
        respondJson($response, 'success', 'Devolucion POS creada', $result + ['item' => $result['return'] ?? null]);
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 422);
        return;
    }
}

if ($route === 'pos/get-return') {
    if (!in_array($method, ['GET', 'POST'], true)) {
        respondJson($response, 'error', 'Metodo no permitido', [], 405);
        return;
    }

    $sessionUser = resolveAuthenticatedSessionUser();
    if (empty($sessionUser)) {
        respondJson($response, 'error', 'Acceso no autorizado para este recurso.', [], 401);
        return;
    }

    $tenantId = trim((string) ($sessionUser['tenant_id'] ?? ''));
    $payload = array_merge($_GET, requestData());
    setTenantContext(['tenant_id' => $tenantId], true);
    RoleContext::setRole((string) ($sessionUser['role'] ?? 'admin'));
    RoleContext::setUserId((string) ($sessionUser['id'] ?? 'anon'));
    RoleContext::setUserLabel((string) ($sessionUser['label'] ?? $sessionUser['name'] ?? ''));

    try {
        $return = (new POSService())->getReturn(
            $tenantId,
            trim((string) ($payload['return_id'] ?? $payload['id'] ?? '')),
            trim((string) ($payload['project_id'] ?? $sessionUser['project_id'] ?? '')) ?: null
        );
        respondJson($response, 'success', 'Devolucion POS cargada', ['return' => $return, 'item' => $return]);
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 404);
        return;
    }
}

if ($route === 'pos/list-returns') {
    if (!in_array($method, ['GET', 'POST'], true)) {
        respondJson($response, 'error', 'Metodo no permitido', [], 405);
        return;
    }

    $sessionUser = resolveAuthenticatedSessionUser();
    if (empty($sessionUser)) {
        respondJson($response, 'error', 'Acceso no autorizado para este recurso.', [], 401);
        return;
    }

    $tenantId = trim((string) ($sessionUser['tenant_id'] ?? ''));
    $payload = array_merge($_GET, requestData());
    setTenantContext(['tenant_id' => $tenantId], true);
    RoleContext::setRole((string) ($sessionUser['role'] ?? 'admin'));
    RoleContext::setUserId((string) ($sessionUser['id'] ?? 'anon'));
    RoleContext::setUserLabel((string) ($sessionUser['label'] ?? $sessionUser['name'] ?? ''));

    try {
        $items = (new POSService())->listReturns(
            $tenantId,
            $payload,
            trim((string) ($payload['project_id'] ?? $sessionUser['project_id'] ?? '')) ?: null
        );
        respondJson($response, 'success', 'Devoluciones POS cargadas', ['items' => $items, 'result_count' => count($items)]);
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 422);
        return;
    }
}

if ($route === 'pos/build-return-receipt') {
    if (!in_array($method, ['GET', 'POST'], true)) {
        respondJson($response, 'error', 'Metodo no permitido', [], 405);
        return;
    }

    $sessionUser = resolveAuthenticatedSessionUser();
    if (empty($sessionUser)) {
        respondJson($response, 'error', 'Acceso no autorizado para este recurso.', [], 401);
        return;
    }

    $tenantId = trim((string) ($sessionUser['tenant_id'] ?? ''));
    $payload = array_merge($_GET, requestData());
    setTenantContext(['tenant_id' => $tenantId], true);
    RoleContext::setRole((string) ($sessionUser['role'] ?? 'admin'));
    RoleContext::setUserId((string) ($sessionUser['id'] ?? 'anon'));
    RoleContext::setUserLabel((string) ($sessionUser['label'] ?? $sessionUser['name'] ?? ''));

    try {
        $receipt = (new POSService())->buildReturnReceiptPayload(
            $tenantId,
            trim((string) ($payload['return_id'] ?? $payload['id'] ?? '')),
            trim((string) ($payload['project_id'] ?? $sessionUser['project_id'] ?? '')) ?: null
        );
        respondJson($response, 'success', 'Ticket de devolucion POS preparado', ['receipt' => $receipt, 'item' => $receipt]);
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 422);
        return;
    }
}

if ($route === 'pos/open-cash-register') {
    if ($method !== 'POST') {
        respondJson($response, 'error', 'Metodo no permitido', [], 405);
        return;
    }

    $sessionUser = resolveAuthenticatedSessionUser();
    if (empty($sessionUser)) {
        respondJson($response, 'error', 'Acceso no autorizado para este recurso.', [], 401);
        return;
    }

    $tenantId = trim((string) ($sessionUser['tenant_id'] ?? ''));
    $payload = requestData();
    if (($payload['tenant_id'] ?? '') !== '' && trim((string) $payload['tenant_id']) !== $tenantId) {
        respondJson($response, 'error', 'Acceso no autorizado para este recurso.', [], 403);
        return;
    }

    setTenantContext(['tenant_id' => $tenantId], true);
    RoleContext::setRole((string) ($sessionUser['role'] ?? 'admin'));
    RoleContext::setUserId((string) ($sessionUser['id'] ?? 'anon'));
    RoleContext::setUserLabel((string) ($sessionUser['label'] ?? $sessionUser['name'] ?? ''));

    try {
        $session = (new POSService())->openCashRegister($payload + [
            'tenant_id' => $tenantId,
            'app_id' => trim((string) ($payload['project_id'] ?? $sessionUser['project_id'] ?? '')) ?: null,
            'opened_by_user_id' => (string) ($sessionUser['id'] ?? 'anon'),
        ]);
        respondJson($response, 'success', 'Caja POS abierta', ['session' => $session, 'item' => $session]);
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 422);
        return;
    }
}

if ($route === 'pos/get-open-cash-session') {
    if (!in_array($method, ['GET', 'POST'], true)) {
        respondJson($response, 'error', 'Metodo no permitido', [], 405);
        return;
    }

    $sessionUser = resolveAuthenticatedSessionUser();
    if (empty($sessionUser)) {
        respondJson($response, 'error', 'Acceso no autorizado para este recurso.', [], 401);
        return;
    }

    $tenantId = trim((string) ($sessionUser['tenant_id'] ?? ''));
    $payload = array_merge($_GET, requestData());
    setTenantContext(['tenant_id' => $tenantId], true);
    RoleContext::setRole((string) ($sessionUser['role'] ?? 'admin'));
    RoleContext::setUserId((string) ($sessionUser['id'] ?? 'anon'));
    RoleContext::setUserLabel((string) ($sessionUser['label'] ?? $sessionUser['name'] ?? ''));

    try {
        $cashRegisterId = trim((string) ($payload['cash_register_id'] ?? ''));
        $session = (new POSService())->getOpenCashSession(
            $tenantId,
            $cashRegisterId,
            trim((string) ($payload['project_id'] ?? $sessionUser['project_id'] ?? '')) ?: null
        );
        respondJson($response, 'success', 'Caja POS abierta cargada', ['session' => $session, 'item' => $session]);
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 404);
        return;
    }
}

if ($route === 'pos/close-cash-register') {
    if ($method !== 'POST') {
        respondJson($response, 'error', 'Metodo no permitido', [], 405);
        return;
    }

    $sessionUser = resolveAuthenticatedSessionUser();
    if (empty($sessionUser)) {
        respondJson($response, 'error', 'Acceso no autorizado para este recurso.', [], 401);
        return;
    }

    $tenantId = trim((string) ($sessionUser['tenant_id'] ?? ''));
    $payload = requestData();
    if (($payload['tenant_id'] ?? '') !== '' && trim((string) $payload['tenant_id']) !== $tenantId) {
        respondJson($response, 'error', 'Acceso no autorizado para este recurso.', [], 403);
        return;
    }

    setTenantContext(['tenant_id' => $tenantId], true);
    RoleContext::setRole((string) ($sessionUser['role'] ?? 'admin'));
    RoleContext::setUserId((string) ($sessionUser['id'] ?? 'anon'));
    RoleContext::setUserLabel((string) ($sessionUser['label'] ?? $sessionUser['name'] ?? ''));

    try {
        $result = (new POSService())->closeCashRegister($payload + [
            'tenant_id' => $tenantId,
            'app_id' => trim((string) ($payload['project_id'] ?? $sessionUser['project_id'] ?? '')) ?: null,
            'closed_by_user_id' => (string) ($sessionUser['id'] ?? 'anon'),
        ]);
        respondJson($response, 'success', 'Caja POS cerrada', $result + ['item' => $result['session'] ?? null]);
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 422);
        return;
    }
}

if ($route === 'pos/build-cash-summary') {
    if (!in_array($method, ['GET', 'POST'], true)) {
        respondJson($response, 'error', 'Metodo no permitido', [], 405);
        return;
    }

    $sessionUser = resolveAuthenticatedSessionUser();
    if (empty($sessionUser)) {
        respondJson($response, 'error', 'Acceso no autorizado para este recurso.', [], 401);
        return;
    }

    $tenantId = trim((string) ($sessionUser['tenant_id'] ?? ''));
    $payload = array_merge($_GET, requestData());
    setTenantContext(['tenant_id' => $tenantId], true);
    RoleContext::setRole((string) ($sessionUser['role'] ?? 'admin'));
    RoleContext::setUserId((string) ($sessionUser['id'] ?? 'anon'));
    RoleContext::setUserLabel((string) ($sessionUser['label'] ?? $sessionUser['name'] ?? ''));

    try {
        $summary = (new POSService())->buildCashSummary(
            $tenantId,
            trim((string) ($payload['session_id'] ?? '')),
            trim((string) ($payload['project_id'] ?? $sessionUser['project_id'] ?? '')) ?: null
        );
        respondJson($response, 'success', 'Arqueo POS preparado', ['summary' => $summary, 'item' => $summary]);
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 404);
        return;
    }
}

if ($route === 'pos/list-cash-sessions') {
    if (!in_array($method, ['GET', 'POST'], true)) {
        respondJson($response, 'error', 'Metodo no permitido', [], 405);
        return;
    }

    $sessionUser = resolveAuthenticatedSessionUser();
    if (empty($sessionUser)) {
        respondJson($response, 'error', 'Acceso no autorizado para este recurso.', [], 401);
        return;
    }

    $tenantId = trim((string) ($sessionUser['tenant_id'] ?? ''));
    $payload = array_merge($_GET, requestData());
    setTenantContext(['tenant_id' => $tenantId], true);
    RoleContext::setRole((string) ($sessionUser['role'] ?? 'admin'));
    RoleContext::setUserId((string) ($sessionUser['id'] ?? 'anon'));
    RoleContext::setUserLabel((string) ($sessionUser['label'] ?? $sessionUser['name'] ?? ''));

    try {
        $items = (new POSService())->listCashSessions(
            $tenantId,
            $payload,
            trim((string) ($payload['project_id'] ?? $sessionUser['project_id'] ?? '')) ?: null
        );
        respondJson($response, 'success', 'Sesiones de caja POS cargadas', ['items' => $items, 'result_count' => count($items)]);
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 422);
        return;
    }
}

if ($route === 'purchases/create-draft') {
    if ($method !== 'POST') {
        respondJson($response, 'error', 'Metodo no permitido', [], 405);
        return;
    }

    $sessionUser = resolveAuthenticatedSessionUser();
    if (empty($sessionUser)) {
        respondJson($response, 'error', 'Acceso no autorizado para este recurso.', [], 401);
        return;
    }

    $tenantId = trim((string) ($sessionUser['tenant_id'] ?? ''));
    $payload = requestData();
    if (($payload['tenant_id'] ?? '') !== '' && trim((string) $payload['tenant_id']) !== $tenantId) {
        respondJson($response, 'error', 'Acceso no autorizado para este recurso.', [], 403);
        return;
    }

    setTenantContext(['tenant_id' => $tenantId], true);
    RoleContext::setRole((string) ($sessionUser['role'] ?? 'admin'));
    RoleContext::setUserId((string) ($sessionUser['id'] ?? 'anon'));
    RoleContext::setUserLabel((string) ($sessionUser['label'] ?? $sessionUser['name'] ?? ''));

    try {
        $draft = (new PurchasesService())->createDraft($payload + [
            'tenant_id' => $tenantId,
            'app_id' => trim((string) ($payload['project_id'] ?? $sessionUser['project_id'] ?? '')) ?: null,
        ]);
        respondJson($response, 'success', 'Borrador de compra creado', ['draft' => $draft, 'item' => $draft]);
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 422);
        return;
    }
}

if ($route === 'purchases/get-draft') {
    if (!in_array($method, ['GET', 'POST'], true)) {
        respondJson($response, 'error', 'Metodo no permitido', [], 405);
        return;
    }

    $sessionUser = resolveAuthenticatedSessionUser();
    if (empty($sessionUser)) {
        respondJson($response, 'error', 'Acceso no autorizado para este recurso.', [], 401);
        return;
    }

    $tenantId = trim((string) ($sessionUser['tenant_id'] ?? ''));
    $payload = array_merge($_GET, requestData());
    setTenantContext(['tenant_id' => $tenantId], true);
    RoleContext::setRole((string) ($sessionUser['role'] ?? 'admin'));
    RoleContext::setUserId((string) ($sessionUser['id'] ?? 'anon'));
    RoleContext::setUserLabel((string) ($sessionUser['label'] ?? $sessionUser['name'] ?? ''));

    try {
        $draft = (new PurchasesService())->getDraft(
            $tenantId,
            trim((string) ($payload['draft_id'] ?? $payload['purchase_draft_id'] ?? '')),
            trim((string) ($payload['project_id'] ?? $sessionUser['project_id'] ?? '')) ?: null
        );
        respondJson($response, 'success', 'Borrador de compra cargado', ['draft' => $draft, 'item' => $draft]);
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 404);
        return;
    }
}

if ($route === 'purchases/add-draft-line') {
    if ($method !== 'POST') {
        respondJson($response, 'error', 'Metodo no permitido', [], 405);
        return;
    }

    $sessionUser = resolveAuthenticatedSessionUser();
    if (empty($sessionUser)) {
        respondJson($response, 'error', 'Acceso no autorizado para este recurso.', [], 401);
        return;
    }

    $tenantId = trim((string) ($sessionUser['tenant_id'] ?? ''));
    $payload = requestData();
    setTenantContext(['tenant_id' => $tenantId], true);
    RoleContext::setRole((string) ($sessionUser['role'] ?? 'admin'));
    RoleContext::setUserId((string) ($sessionUser['id'] ?? 'anon'));
    RoleContext::setUserLabel((string) ($sessionUser['label'] ?? $sessionUser['name'] ?? ''));

    try {
        $draft = (new PurchasesService())->addLineToDraft($payload + [
            'tenant_id' => $tenantId,
            'app_id' => trim((string) ($payload['project_id'] ?? $sessionUser['project_id'] ?? '')) ?: null,
        ]);
        respondJson($response, 'success', 'Linea de compra agregada', ['draft' => $draft, 'item' => $draft]);
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 422);
        return;
    }
}

if ($route === 'purchases/remove-draft-line') {
    if (!in_array($method, ['DELETE', 'POST'], true)) {
        respondJson($response, 'error', 'Metodo no permitido', [], 405);
        return;
    }

    $sessionUser = resolveAuthenticatedSessionUser();
    if (empty($sessionUser)) {
        respondJson($response, 'error', 'Acceso no autorizado para este recurso.', [], 401);
        return;
    }

    $tenantId = trim((string) ($sessionUser['tenant_id'] ?? ''));
    $payload = requestData();
    setTenantContext(['tenant_id' => $tenantId], true);
    RoleContext::setRole((string) ($sessionUser['role'] ?? 'admin'));
    RoleContext::setUserId((string) ($sessionUser['id'] ?? 'anon'));
    RoleContext::setUserLabel((string) ($sessionUser['label'] ?? $sessionUser['name'] ?? ''));

    try {
        $draft = (new PurchasesService())->removeLineFromDraft(
            $tenantId,
            trim((string) ($payload['draft_id'] ?? $payload['purchase_draft_id'] ?? '')),
            trim((string) ($payload['line_id'] ?? $payload['id'] ?? '')),
            trim((string) ($payload['project_id'] ?? $sessionUser['project_id'] ?? '')) ?: null
        );
        respondJson($response, 'success', 'Linea de compra eliminada', ['draft' => $draft, 'item' => $draft]);
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 422);
        return;
    }
}

if ($route === 'purchases/attach-supplier') {
    if ($method !== 'POST') {
        respondJson($response, 'error', 'Metodo no permitido', [], 405);
        return;
    }

    $sessionUser = resolveAuthenticatedSessionUser();
    if (empty($sessionUser)) {
        respondJson($response, 'error', 'Acceso no autorizado para este recurso.', [], 401);
        return;
    }

    $tenantId = trim((string) ($sessionUser['tenant_id'] ?? ''));
    $payload = requestData();
    setTenantContext(['tenant_id' => $tenantId], true);
    RoleContext::setRole((string) ($sessionUser['role'] ?? 'admin'));
    RoleContext::setUserId((string) ($sessionUser['id'] ?? 'anon'));
    RoleContext::setUserLabel((string) ($sessionUser['label'] ?? $sessionUser['name'] ?? ''));

    try {
        $draft = (new PurchasesService())->attachSupplierToDraft($payload + [
            'tenant_id' => $tenantId,
            'app_id' => trim((string) ($payload['project_id'] ?? $sessionUser['project_id'] ?? '')) ?: null,
        ]);
        respondJson($response, 'success', 'Proveedor asociado al borrador de compra', ['draft' => $draft, 'item' => $draft]);
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 422);
        return;
    }
}

if ($route === 'purchases/finalize') {
    if ($method !== 'POST') {
        respondJson($response, 'error', 'Metodo no permitido', [], 405);
        return;
    }

    $sessionUser = resolveAuthenticatedSessionUser();
    if (empty($sessionUser)) {
        respondJson($response, 'error', 'Acceso no autorizado para este recurso.', [], 401);
        return;
    }

    $tenantId = trim((string) ($sessionUser['tenant_id'] ?? ''));
    $payload = requestData();
    setTenantContext(['tenant_id' => $tenantId], true);
    RoleContext::setRole((string) ($sessionUser['role'] ?? 'admin'));
    RoleContext::setUserId((string) ($sessionUser['id'] ?? 'anon'));
    RoleContext::setUserLabel((string) ($sessionUser['label'] ?? $sessionUser['name'] ?? ''));

    try {
        $result = (new PurchasesService())->finalizeDraft($payload + [
            'tenant_id' => $tenantId,
            'app_id' => trim((string) ($payload['project_id'] ?? $sessionUser['project_id'] ?? '')) ?: null,
            'created_by_user_id' => (string) ($sessionUser['id'] ?? 'anon'),
        ]);
        respondJson($response, 'success', 'Compra registrada', $result + ['item' => $result['purchase'] ?? null]);
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 422);
        return;
    }
}

if ($route === 'purchases/get-purchase') {
    if (!in_array($method, ['GET', 'POST'], true)) {
        respondJson($response, 'error', 'Metodo no permitido', [], 405);
        return;
    }

    $sessionUser = resolveAuthenticatedSessionUser();
    if (empty($sessionUser)) {
        respondJson($response, 'error', 'Acceso no autorizado para este recurso.', [], 401);
        return;
    }

    $tenantId = trim((string) ($sessionUser['tenant_id'] ?? ''));
    $payload = array_merge($_GET, requestData());
    setTenantContext(['tenant_id' => $tenantId], true);
    RoleContext::setRole((string) ($sessionUser['role'] ?? 'admin'));
    RoleContext::setUserId((string) ($sessionUser['id'] ?? 'anon'));
    RoleContext::setUserLabel((string) ($sessionUser['label'] ?? $sessionUser['name'] ?? ''));

    try {
        $purchase = (new PurchasesService())->getPurchase(
            $tenantId,
            trim((string) ($payload['purchase_id'] ?? $payload['id'] ?? '')),
            trim((string) ($payload['project_id'] ?? $sessionUser['project_id'] ?? '')) ?: null
        );
        respondJson($response, 'success', 'Compra cargada', ['purchase' => $purchase, 'item' => $purchase]);
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 404);
        return;
    }
}

if ($route === 'purchases/list') {
    if (!in_array($method, ['GET', 'POST'], true)) {
        respondJson($response, 'error', 'Metodo no permitido', [], 405);
        return;
    }

    $sessionUser = resolveAuthenticatedSessionUser();
    if (empty($sessionUser)) {
        respondJson($response, 'error', 'Acceso no autorizado para este recurso.', [], 401);
        return;
    }

    $tenantId = trim((string) ($sessionUser['tenant_id'] ?? ''));
    $payload = array_merge($_GET, requestData());
    setTenantContext(['tenant_id' => $tenantId], true);
    RoleContext::setRole((string) ($sessionUser['role'] ?? 'admin'));
    RoleContext::setUserId((string) ($sessionUser['id'] ?? 'anon'));
    RoleContext::setUserLabel((string) ($sessionUser['label'] ?? $sessionUser['name'] ?? ''));

    try {
        $items = (new PurchasesService())->listPurchases(
            $tenantId,
            $payload,
            trim((string) ($payload['project_id'] ?? $sessionUser['project_id'] ?? '')) ?: null
        );
        respondJson($response, 'success', 'Compras cargadas', ['items' => $items, 'result_count' => count($items)]);
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 422);
        return;
    }
}

if ($route === 'purchases/get-by-number') {
    if (!in_array($method, ['GET', 'POST'], true)) {
        respondJson($response, 'error', 'Metodo no permitido', [], 405);
        return;
    }

    $sessionUser = resolveAuthenticatedSessionUser();
    if (empty($sessionUser)) {
        respondJson($response, 'error', 'Acceso no autorizado para este recurso.', [], 401);
        return;
    }

    $tenantId = trim((string) ($sessionUser['tenant_id'] ?? ''));
    $payload = array_merge($_GET, requestData());
    setTenantContext(['tenant_id' => $tenantId], true);
    RoleContext::setRole((string) ($sessionUser['role'] ?? 'admin'));
    RoleContext::setUserId((string) ($sessionUser['id'] ?? 'anon'));
    RoleContext::setUserLabel((string) ($sessionUser['label'] ?? $sessionUser['name'] ?? ''));

    try {
        $purchase = (new PurchasesService())->getPurchaseByNumber(
            $tenantId,
            trim((string) ($payload['purchase_number'] ?? $payload['number'] ?? '')),
            trim((string) ($payload['project_id'] ?? $sessionUser['project_id'] ?? '')) ?: null
        );
        respondJson($response, 'success', 'Compra cargada por numero', ['purchase' => $purchase, 'item' => $purchase]);
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 404);
        return;
    }
}

if ($route === 'purchases/attach-document-to-draft') {
    if ($method !== 'POST') {
        respondJson($response, 'error', 'Metodo no permitido', [], 405);
        return;
    }

    $sessionUser = resolveAuthenticatedSessionUser();
    if (empty($sessionUser)) {
        respondJson($response, 'error', 'Acceso no autorizado para este recurso.', [], 401);
        return;
    }

    $tenantId = trim((string) ($sessionUser['tenant_id'] ?? ''));
    $payload = requestData();
    setTenantContext(['tenant_id' => $tenantId], true);
    RoleContext::setRole((string) ($sessionUser['role'] ?? 'admin'));
    RoleContext::setUserId((string) ($sessionUser['id'] ?? 'anon'));
    RoleContext::setUserLabel((string) ($sessionUser['label'] ?? $sessionUser['name'] ?? ''));

    try {
        $document = (new PurchasesService())->attachDocumentToPurchaseDraft($payload + [
            'tenant_id' => $tenantId,
            'app_id' => trim((string) ($payload['project_id'] ?? $sessionUser['project_id'] ?? '')) ?: null,
        ]);
        respondJson($response, 'success', 'Documento asociado al borrador de compra', ['document' => $document, 'item' => $document]);
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 422);
        return;
    }
}

if ($route === 'purchases/attach-document') {
    if ($method !== 'POST') {
        respondJson($response, 'error', 'Metodo no permitido', [], 405);
        return;
    }

    $sessionUser = resolveAuthenticatedSessionUser();
    if (empty($sessionUser)) {
        respondJson($response, 'error', 'Acceso no autorizado para este recurso.', [], 401);
        return;
    }

    $tenantId = trim((string) ($sessionUser['tenant_id'] ?? ''));
    $payload = requestData();
    setTenantContext(['tenant_id' => $tenantId], true);
    RoleContext::setRole((string) ($sessionUser['role'] ?? 'admin'));
    RoleContext::setUserId((string) ($sessionUser['id'] ?? 'anon'));
    RoleContext::setUserLabel((string) ($sessionUser['label'] ?? $sessionUser['name'] ?? ''));

    try {
        $document = (new PurchasesService())->attachDocumentToPurchase($payload + [
            'tenant_id' => $tenantId,
            'app_id' => trim((string) ($payload['project_id'] ?? $sessionUser['project_id'] ?? '')) ?: null,
        ]);
        respondJson($response, 'success', 'Documento asociado a la compra', ['document' => $document, 'item' => $document]);
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 422);
        return;
    }
}

if ($route === 'purchases/list-documents') {
    if (!in_array($method, ['GET', 'POST'], true)) {
        respondJson($response, 'error', 'Metodo no permitido', [], 405);
        return;
    }

    $sessionUser = resolveAuthenticatedSessionUser();
    if (empty($sessionUser)) {
        respondJson($response, 'error', 'Acceso no autorizado para este recurso.', [], 401);
        return;
    }

    $tenantId = trim((string) ($sessionUser['tenant_id'] ?? ''));
    $payload = array_merge($_GET, requestData());
    setTenantContext(['tenant_id' => $tenantId], true);
    RoleContext::setRole((string) ($sessionUser['role'] ?? 'admin'));
    RoleContext::setUserId((string) ($sessionUser['id'] ?? 'anon'));
    RoleContext::setUserLabel((string) ($sessionUser['label'] ?? $sessionUser['name'] ?? ''));

    try {
        $items = (new PurchasesService())->listPurchaseDocuments(
            $tenantId,
            $payload,
            trim((string) ($payload['project_id'] ?? $sessionUser['project_id'] ?? '')) ?: null
        );
        respondJson($response, 'success', 'Documentos de compra cargados', ['items' => $items, 'result_count' => count($items)]);
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 422);
        return;
    }
}

if ($route === 'purchases/get-document') {
    if (!in_array($method, ['GET', 'POST'], true)) {
        respondJson($response, 'error', 'Metodo no permitido', [], 405);
        return;
    }

    $sessionUser = resolveAuthenticatedSessionUser();
    if (empty($sessionUser)) {
        respondJson($response, 'error', 'Acceso no autorizado para este recurso.', [], 401);
        return;
    }

    $tenantId = trim((string) ($sessionUser['tenant_id'] ?? ''));
    $payload = array_merge($_GET, requestData());
    setTenantContext(['tenant_id' => $tenantId], true);
    RoleContext::setRole((string) ($sessionUser['role'] ?? 'admin'));
    RoleContext::setUserId((string) ($sessionUser['id'] ?? 'anon'));
    RoleContext::setUserLabel((string) ($sessionUser['label'] ?? $sessionUser['name'] ?? ''));

    try {
        $document = (new PurchasesService())->getPurchaseDocument(
            $tenantId,
            trim((string) ($payload['purchase_document_id'] ?? $payload['document_id'] ?? $payload['id'] ?? '')),
            trim((string) ($payload['project_id'] ?? $sessionUser['project_id'] ?? '')) ?: null
        );
        respondJson($response, 'success', 'Documento de compra cargado', ['document' => $document, 'item' => $document]);
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 404);
        return;
    }
}

if ($route === 'purchases/detach-document') {
    if (!in_array($method, ['DELETE', 'POST'], true)) {
        respondJson($response, 'error', 'Metodo no permitido', [], 405);
        return;
    }

    $sessionUser = resolveAuthenticatedSessionUser();
    if (empty($sessionUser)) {
        respondJson($response, 'error', 'Acceso no autorizado para este recurso.', [], 401);
        return;
    }

    $tenantId = trim((string) ($sessionUser['tenant_id'] ?? ''));
    $payload = requestData();
    setTenantContext(['tenant_id' => $tenantId], true);
    RoleContext::setRole((string) ($sessionUser['role'] ?? 'admin'));
    RoleContext::setUserId((string) ($sessionUser['id'] ?? 'anon'));
    RoleContext::setUserLabel((string) ($sessionUser['label'] ?? $sessionUser['name'] ?? ''));

    try {
        $result = (new PurchasesService())->detachPurchaseDocument(
            $tenantId,
            trim((string) ($payload['purchase_document_id'] ?? $payload['document_id'] ?? $payload['id'] ?? '')),
            trim((string) ($payload['project_id'] ?? $sessionUser['project_id'] ?? '')) ?: null
        );
        respondJson($response, 'success', 'Documento de compra desvinculado', $result + ['item' => $result['document'] ?? null]);
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 422);
        return;
    }
}

if ($route === 'purchases/register-document-metadata') {
    if ($method !== 'POST') {
        respondJson($response, 'error', 'Metodo no permitido', [], 405);
        return;
    }

    $sessionUser = resolveAuthenticatedSessionUser();
    if (empty($sessionUser)) {
        respondJson($response, 'error', 'Acceso no autorizado para este recurso.', [], 401);
        return;
    }

    $tenantId = trim((string) ($sessionUser['tenant_id'] ?? ''));
    $payload = requestData();
    setTenantContext(['tenant_id' => $tenantId], true);
    RoleContext::setRole((string) ($sessionUser['role'] ?? 'admin'));
    RoleContext::setUserId((string) ($sessionUser['id'] ?? 'anon'));
    RoleContext::setUserLabel((string) ($sessionUser['label'] ?? $sessionUser['name'] ?? ''));

    try {
        $document = (new PurchasesService())->registerDocumentMetadata($payload + [
            'tenant_id' => $tenantId,
            'app_id' => trim((string) ($payload['project_id'] ?? $sessionUser['project_id'] ?? '')) ?: null,
        ]);
        respondJson($response, 'success', 'Metadata de documento de compra actualizada', ['document' => $document, 'item' => $document]);
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 422);
        return;
    }
}

if ($route === 'fiscal/create-document') {
    if ($method !== 'POST') {
        respondJson($response, 'error', 'Metodo no permitido', [], 405);
        return;
    }

    $sessionUser = resolveAuthenticatedSessionUser();
    if (empty($sessionUser)) {
        respondJson($response, 'error', 'Acceso no autorizado para este recurso.', [], 401);
        return;
    }

    $tenantId = trim((string) ($sessionUser['tenant_id'] ?? ''));
    $payload = requestData();
    if (($payload['tenant_id'] ?? '') !== '' && trim((string) $payload['tenant_id']) !== $tenantId) {
        respondJson($response, 'error', 'Acceso no autorizado para este recurso.', [], 403);
        return;
    }

    setTenantContext(['tenant_id' => $tenantId], true);
    RoleContext::setRole((string) ($sessionUser['role'] ?? 'admin'));
    RoleContext::setUserId((string) ($sessionUser['id'] ?? 'anon'));
    RoleContext::setUserLabel((string) ($sessionUser['label'] ?? $sessionUser['name'] ?? ''));

    try {
        $document = (new FiscalEngineService())->createDocumentFromSource($payload + [
            'tenant_id' => $tenantId,
            'app_id' => trim((string) ($payload['project_id'] ?? $sessionUser['project_id'] ?? '')) ?: null,
        ]);
        respondJson($response, 'success', 'Documento fiscal creado', ['document' => $document, 'item' => $document]);
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 422);
        return;
    }
}

if ($route === 'fiscal/create-sales-invoice-from-sale') {
    if ($method !== 'POST') {
        respondJson($response, 'error', 'Metodo no permitido', [], 405);
        return;
    }

    $sessionUser = resolveAuthenticatedSessionUser();
    if (empty($sessionUser)) {
        respondJson($response, 'error', 'Acceso no autorizado para este recurso.', [], 401);
        return;
    }

    $tenantId = trim((string) ($sessionUser['tenant_id'] ?? ''));
    $payload = requestData();
    setTenantContext(['tenant_id' => $tenantId], true);
    RoleContext::setRole((string) ($sessionUser['role'] ?? 'admin'));
    RoleContext::setUserId((string) ($sessionUser['id'] ?? 'anon'));
    RoleContext::setUserLabel((string) ($sessionUser['label'] ?? $sessionUser['name'] ?? ''));

    try {
        $document = (new FiscalEngineService())->createSalesInvoiceFromSale($payload + [
            'tenant_id' => $tenantId,
            'app_id' => trim((string) ($payload['project_id'] ?? $sessionUser['project_id'] ?? '')) ?: null,
        ]);
        respondJson($response, 'success', 'Factura electronica interna preparada', ['document' => $document, 'item' => $document]);
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 422);
        return;
    }
}

if ($route === 'fiscal/create-credit-note') {
    if ($method !== 'POST') {
        respondJson($response, 'error', 'Metodo no permitido', [], 405);
        return;
    }

    $sessionUser = resolveAuthenticatedSessionUser();
    if (empty($sessionUser)) {
        respondJson($response, 'error', 'Acceso no autorizado para este recurso.', [], 401);
        return;
    }

    $tenantId = trim((string) ($sessionUser['tenant_id'] ?? ''));
    $payload = requestData();
    setTenantContext(['tenant_id' => $tenantId], true);
    RoleContext::setRole((string) ($sessionUser['role'] ?? 'admin'));
    RoleContext::setUserId((string) ($sessionUser['id'] ?? 'anon'));
    RoleContext::setUserLabel((string) ($sessionUser['label'] ?? $sessionUser['name'] ?? ''));

    try {
        $document = (new FiscalEngineService())->createCreditNoteFromSaleOrReturn($payload + [
            'tenant_id' => $tenantId,
            'app_id' => trim((string) ($payload['project_id'] ?? $sessionUser['project_id'] ?? '')) ?: null,
        ]);
        respondJson($response, 'success', 'Nota credito interna preparada', ['document' => $document, 'item' => $document]);
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 422);
        return;
    }
}

if ($route === 'fiscal/create-support-document-from-purchase') {
    if ($method !== 'POST') {
        respondJson($response, 'error', 'Metodo no permitido', [], 405);
        return;
    }

    $sessionUser = resolveAuthenticatedSessionUser();
    if (empty($sessionUser)) {
        respondJson($response, 'error', 'Acceso no autorizado para este recurso.', [], 401);
        return;
    }

    $tenantId = trim((string) ($sessionUser['tenant_id'] ?? ''));
    $payload = requestData();
    setTenantContext(['tenant_id' => $tenantId], true);
    RoleContext::setRole((string) ($sessionUser['role'] ?? 'admin'));
    RoleContext::setUserId((string) ($sessionUser['id'] ?? 'anon'));
    RoleContext::setUserLabel((string) ($sessionUser['label'] ?? $sessionUser['name'] ?? ''));

    try {
        $document = (new FiscalEngineService())->createSupportDocumentFromPurchase($payload + [
            'tenant_id' => $tenantId,
            'app_id' => trim((string) ($payload['project_id'] ?? $sessionUser['project_id'] ?? '')) ?: null,
        ]);
        respondJson($response, 'success', 'Documento soporte interno preparado', ['document' => $document, 'item' => $document]);
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 422);
        return;
    }
}

if ($route === 'fiscal/get-document') {
    if (!in_array($method, ['GET', 'POST'], true)) {
        respondJson($response, 'error', 'Metodo no permitido', [], 405);
        return;
    }

    $sessionUser = resolveAuthenticatedSessionUser();
    if (empty($sessionUser)) {
        respondJson($response, 'error', 'Acceso no autorizado para este recurso.', [], 401);
        return;
    }

    $tenantId = trim((string) ($sessionUser['tenant_id'] ?? ''));
    $payload = array_merge($_GET, requestData());
    setTenantContext(['tenant_id' => $tenantId], true);
    RoleContext::setRole((string) ($sessionUser['role'] ?? 'admin'));
    RoleContext::setUserId((string) ($sessionUser['id'] ?? 'anon'));
    RoleContext::setUserLabel((string) ($sessionUser['label'] ?? $sessionUser['name'] ?? ''));

    try {
        $document = (new FiscalEngineService())->getDocument(
            $tenantId,
            trim((string) ($payload['fiscal_document_id'] ?? $payload['document_id'] ?? $payload['id'] ?? '')),
            trim((string) ($payload['project_id'] ?? $sessionUser['project_id'] ?? '')) ?: null
        );
        respondJson($response, 'success', 'Documento fiscal cargado', ['document' => $document, 'item' => $document]);
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 404);
        return;
    }
}

if ($route === 'fiscal/list-documents') {
    if (!in_array($method, ['GET', 'POST'], true)) {
        respondJson($response, 'error', 'Metodo no permitido', [], 405);
        return;
    }

    $sessionUser = resolveAuthenticatedSessionUser();
    if (empty($sessionUser)) {
        respondJson($response, 'error', 'Acceso no autorizado para este recurso.', [], 401);
        return;
    }

    $tenantId = trim((string) ($sessionUser['tenant_id'] ?? ''));
    $payload = array_merge($_GET, requestData());
    setTenantContext(['tenant_id' => $tenantId], true);
    RoleContext::setRole((string) ($sessionUser['role'] ?? 'admin'));
    RoleContext::setUserId((string) ($sessionUser['id'] ?? 'anon'));
    RoleContext::setUserLabel((string) ($sessionUser['label'] ?? $sessionUser['name'] ?? ''));

    try {
        $items = (new FiscalEngineService())->listDocuments(
            $tenantId,
            $payload,
            trim((string) ($payload['project_id'] ?? $sessionUser['project_id'] ?? '')) ?: null
        );
        respondJson($response, 'success', 'Documentos fiscales cargados', ['items' => $items, 'result_count' => count($items)]);
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 422);
        return;
    }
}

if ($route === 'fiscal/list-documents-by-type') {
    if (!in_array($method, ['GET', 'POST'], true)) {
        respondJson($response, 'error', 'Metodo no permitido', [], 405);
        return;
    }

    $sessionUser = resolveAuthenticatedSessionUser();
    if (empty($sessionUser)) {
        respondJson($response, 'error', 'Acceso no autorizado para este recurso.', [], 401);
        return;
    }

    $tenantId = trim((string) ($sessionUser['tenant_id'] ?? ''));
    $payload = array_merge($_GET, requestData());
    setTenantContext(['tenant_id' => $tenantId], true);
    RoleContext::setRole((string) ($sessionUser['role'] ?? 'admin'));
    RoleContext::setUserId((string) ($sessionUser['id'] ?? 'anon'));
    RoleContext::setUserLabel((string) ($sessionUser['label'] ?? $sessionUser['name'] ?? ''));

    try {
        $items = (new FiscalEngineService())->listDocumentsByType(
            $tenantId,
            trim((string) ($payload['document_type'] ?? '')),
            $payload,
            trim((string) ($payload['project_id'] ?? $sessionUser['project_id'] ?? '')) ?: null
        );
        respondJson($response, 'success', 'Documentos fiscales por tipo cargados', ['items' => $items, 'result_count' => count($items)]);
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 422);
        return;
    }
}

if ($route === 'fiscal/get-by-source') {
    if (!in_array($method, ['GET', 'POST'], true)) {
        respondJson($response, 'error', 'Metodo no permitido', [], 405);
        return;
    }

    $sessionUser = resolveAuthenticatedSessionUser();
    if (empty($sessionUser)) {
        respondJson($response, 'error', 'Acceso no autorizado para este recurso.', [], 401);
        return;
    }

    $tenantId = trim((string) ($sessionUser['tenant_id'] ?? ''));
    $payload = array_merge($_GET, requestData());
    setTenantContext(['tenant_id' => $tenantId], true);
    RoleContext::setRole((string) ($sessionUser['role'] ?? 'admin'));
    RoleContext::setUserId((string) ($sessionUser['id'] ?? 'anon'));
    RoleContext::setUserLabel((string) ($sessionUser['label'] ?? $sessionUser['name'] ?? ''));

    try {
        $document = (new FiscalEngineService())->getDocumentBySource(
            $tenantId,
            trim((string) ($payload['source_module'] ?? '')),
            trim((string) ($payload['source_entity_type'] ?? '')),
            trim((string) ($payload['source_entity_id'] ?? '')),
            $payload,
            trim((string) ($payload['project_id'] ?? $sessionUser['project_id'] ?? '')) ?: null
        );
        respondJson($response, 'success', 'Documento fiscal cargado por origen', ['document' => $document, 'item' => $document]);
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 404);
        return;
    }
}

if ($route === 'fiscal/build-document-payload') {
    if (!in_array($method, ['GET', 'POST'], true)) {
        respondJson($response, 'error', 'Metodo no permitido', [], 405);
        return;
    }

    $sessionUser = resolveAuthenticatedSessionUser();
    if (empty($sessionUser)) {
        respondJson($response, 'error', 'Acceso no autorizado para este recurso.', [], 401);
        return;
    }

    $tenantId = trim((string) ($sessionUser['tenant_id'] ?? ''));
    $payload = array_merge($_GET, requestData());
    setTenantContext(['tenant_id' => $tenantId], true);
    RoleContext::setRole((string) ($sessionUser['role'] ?? 'admin'));
    RoleContext::setUserId((string) ($sessionUser['id'] ?? 'anon'));
    RoleContext::setUserLabel((string) ($sessionUser['label'] ?? $sessionUser['name'] ?? ''));

    try {
        $documentPayload = (new FiscalEngineService())->buildDocumentPayload(
            $tenantId,
            trim((string) ($payload['fiscal_document_id'] ?? $payload['document_id'] ?? $payload['id'] ?? '')),
            trim((string) ($payload['project_id'] ?? $sessionUser['project_id'] ?? '')) ?: null
        );
        respondJson($response, 'success', 'Payload fiscal preparado', ['payload' => $documentPayload, 'item' => $documentPayload]);
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 422);
        return;
    }
}

if ($route === 'fiscal/record-event') {
    if ($method !== 'POST') {
        respondJson($response, 'error', 'Metodo no permitido', [], 405);
        return;
    }

    $sessionUser = resolveAuthenticatedSessionUser();
    if (empty($sessionUser)) {
        respondJson($response, 'error', 'Acceso no autorizado para este recurso.', [], 401);
        return;
    }

    $tenantId = trim((string) ($sessionUser['tenant_id'] ?? ''));
    $payload = requestData();
    setTenantContext(['tenant_id' => $tenantId], true);
    RoleContext::setRole((string) ($sessionUser['role'] ?? 'admin'));
    RoleContext::setUserId((string) ($sessionUser['id'] ?? 'anon'));
    RoleContext::setUserLabel((string) ($sessionUser['label'] ?? $sessionUser['name'] ?? ''));

    try {
        $event = (new FiscalEngineService())->recordEvent($payload + [
            'tenant_id' => $tenantId,
            'app_id' => trim((string) ($payload['project_id'] ?? $sessionUser['project_id'] ?? '')) ?: null,
        ]);
        respondJson($response, 'success', 'Evento fiscal registrado', ['event' => $event, 'item' => $event]);
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 422);
        return;
    }
}

if ($route === 'fiscal/update-status') {
    if ($method !== 'POST') {
        respondJson($response, 'error', 'Metodo no permitido', [], 405);
        return;
    }

    $sessionUser = resolveAuthenticatedSessionUser();
    if (empty($sessionUser)) {
        respondJson($response, 'error', 'Acceso no autorizado para este recurso.', [], 401);
        return;
    }

    $tenantId = trim((string) ($sessionUser['tenant_id'] ?? ''));
    $payload = requestData();
    setTenantContext(['tenant_id' => $tenantId], true);
    RoleContext::setRole((string) ($sessionUser['role'] ?? 'admin'));
    RoleContext::setUserId((string) ($sessionUser['id'] ?? 'anon'));
    RoleContext::setUserLabel((string) ($sessionUser['label'] ?? $sessionUser['name'] ?? ''));

    try {
        $document = (new FiscalEngineService())->updateStatus($payload + [
            'tenant_id' => $tenantId,
            'app_id' => trim((string) ($payload['project_id'] ?? $sessionUser['project_id'] ?? '')) ?: null,
        ]);
        respondJson($response, 'success', 'Estado fiscal actualizado', ['document' => $document, 'item' => $document]);
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 422);
        return;
    }
}

if ($route === 'ecommerce/create-store') {
    if ($method !== 'POST') {
        respondJson($response, 'error', 'Metodo no permitido', [], 405);
        return;
    }

    $sessionUser = resolveAuthenticatedSessionUser();
    if (empty($sessionUser)) {
        respondJson($response, 'error', 'Acceso no autorizado para este recurso.', [], 401);
        return;
    }

    $tenantId = trim((string) ($sessionUser['tenant_id'] ?? ''));
    $payload = requestData();
    if (($payload['tenant_id'] ?? '') !== '' && trim((string) $payload['tenant_id']) !== $tenantId) {
        respondJson($response, 'error', 'Acceso no autorizado para este recurso.', [], 403);
        return;
    }

    setTenantContext(['tenant_id' => $tenantId], true);
    RoleContext::setRole((string) ($sessionUser['role'] ?? 'admin'));
    RoleContext::setUserId((string) ($sessionUser['id'] ?? 'anon'));
    RoleContext::setUserLabel((string) ($sessionUser['label'] ?? $sessionUser['name'] ?? ''));

    try {
        $store = (new EcommerceHubService())->createStore($payload + [
            'tenant_id' => $tenantId,
            'app_id' => trim((string) ($payload['project_id'] ?? $sessionUser['project_id'] ?? '')) ?: null,
        ]);
        respondJson($response, 'success', 'Tienda ecommerce creada', ['store' => $store, 'item' => $store]);
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 422);
        return;
    }
}

if ($route === 'ecommerce/update-store') {
    if ($method !== 'POST') {
        respondJson($response, 'error', 'Metodo no permitido', [], 405);
        return;
    }

    $sessionUser = resolveAuthenticatedSessionUser();
    if (empty($sessionUser)) {
        respondJson($response, 'error', 'Acceso no autorizado para este recurso.', [], 401);
        return;
    }

    $tenantId = trim((string) ($sessionUser['tenant_id'] ?? ''));
    $payload = requestData();
    setTenantContext(['tenant_id' => $tenantId], true);
    RoleContext::setRole((string) ($sessionUser['role'] ?? 'admin'));
    RoleContext::setUserId((string) ($sessionUser['id'] ?? 'anon'));
    RoleContext::setUserLabel((string) ($sessionUser['label'] ?? $sessionUser['name'] ?? ''));

    try {
        $store = (new EcommerceHubService())->updateStore($payload + [
            'tenant_id' => $tenantId,
            'app_id' => trim((string) ($payload['project_id'] ?? $sessionUser['project_id'] ?? '')) ?: null,
        ]);
        respondJson($response, 'success', 'Tienda ecommerce actualizada', ['store' => $store, 'item' => $store]);
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 422);
        return;
    }
}

if ($route === 'ecommerce/register-credentials') {
    if ($method !== 'POST') {
        respondJson($response, 'error', 'Metodo no permitido', [], 405);
        return;
    }

    $sessionUser = resolveAuthenticatedSessionUser();
    if (empty($sessionUser)) {
        respondJson($response, 'error', 'Acceso no autorizado para este recurso.', [], 401);
        return;
    }

    $tenantId = trim((string) ($sessionUser['tenant_id'] ?? ''));
    $payload = requestData();
    setTenantContext(['tenant_id' => $tenantId], true);
    RoleContext::setRole((string) ($sessionUser['role'] ?? 'admin'));
    RoleContext::setUserId((string) ($sessionUser['id'] ?? 'anon'));
    RoleContext::setUserLabel((string) ($sessionUser['label'] ?? $sessionUser['name'] ?? ''));

    try {
        $credential = (new EcommerceHubService())->registerCredentials($payload + [
            'tenant_id' => $tenantId,
            'app_id' => trim((string) ($payload['project_id'] ?? $sessionUser['project_id'] ?? '')) ?: null,
        ]);
        respondJson($response, 'success', 'Credenciales ecommerce registradas', ['credential' => $credential, 'item' => $credential]);
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 422);
        return;
    }
}

if ($route === 'ecommerce/validate-store-setup') {
    if (!in_array($method, ['GET', 'POST'], true)) {
        respondJson($response, 'error', 'Metodo no permitido', [], 405);
        return;
    }

    $sessionUser = resolveAuthenticatedSessionUser();
    if (empty($sessionUser)) {
        respondJson($response, 'error', 'Acceso no autorizado para este recurso.', [], 401);
        return;
    }

    $tenantId = trim((string) ($sessionUser['tenant_id'] ?? ''));
    $payload = array_merge($_GET, requestData());
    setTenantContext(['tenant_id' => $tenantId], true);
    RoleContext::setRole((string) ($sessionUser['role'] ?? 'admin'));
    RoleContext::setUserId((string) ($sessionUser['id'] ?? 'anon'));
    RoleContext::setUserLabel((string) ($sessionUser['label'] ?? $sessionUser['name'] ?? ''));

    try {
        $setup = (new EcommerceHubService())->validateStoreSetup(
            $tenantId,
            trim((string) ($payload['store_id'] ?? $payload['id'] ?? '')),
            trim((string) ($payload['project_id'] ?? $sessionUser['project_id'] ?? '')) ?: null
        );
        respondJson($response, 'success', 'Configuracion ecommerce validada', ['setup' => $setup, 'item' => $setup]);
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 422);
        return;
    }
}

if ($route === 'ecommerce/list-stores') {
    if (!in_array($method, ['GET', 'POST'], true)) {
        respondJson($response, 'error', 'Metodo no permitido', [], 405);
        return;
    }

    $sessionUser = resolveAuthenticatedSessionUser();
    if (empty($sessionUser)) {
        respondJson($response, 'error', 'Acceso no autorizado para este recurso.', [], 401);
        return;
    }

    $tenantId = trim((string) ($sessionUser['tenant_id'] ?? ''));
    $payload = array_merge($_GET, requestData());
    setTenantContext(['tenant_id' => $tenantId], true);
    RoleContext::setRole((string) ($sessionUser['role'] ?? 'admin'));
    RoleContext::setUserId((string) ($sessionUser['id'] ?? 'anon'));
    RoleContext::setUserLabel((string) ($sessionUser['label'] ?? $sessionUser['name'] ?? ''));

    try {
        $items = (new EcommerceHubService())->listStores(
            $tenantId,
            $payload,
            trim((string) ($payload['project_id'] ?? $sessionUser['project_id'] ?? '')) ?: null
        );
        respondJson($response, 'success', 'Tiendas ecommerce cargadas', ['items' => $items, 'result_count' => count($items)]);
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 422);
        return;
    }
}

if ($route === 'ecommerce/get-store') {
    if (!in_array($method, ['GET', 'POST'], true)) {
        respondJson($response, 'error', 'Metodo no permitido', [], 405);
        return;
    }

    $sessionUser = resolveAuthenticatedSessionUser();
    if (empty($sessionUser)) {
        respondJson($response, 'error', 'Acceso no autorizado para este recurso.', [], 401);
        return;
    }

    $tenantId = trim((string) ($sessionUser['tenant_id'] ?? ''));
    $payload = array_merge($_GET, requestData());
    setTenantContext(['tenant_id' => $tenantId], true);
    RoleContext::setRole((string) ($sessionUser['role'] ?? 'admin'));
    RoleContext::setUserId((string) ($sessionUser['id'] ?? 'anon'));
    RoleContext::setUserLabel((string) ($sessionUser['label'] ?? $sessionUser['name'] ?? ''));

    try {
        $store = (new EcommerceHubService())->getStore(
            $tenantId,
            trim((string) ($payload['store_id'] ?? $payload['id'] ?? '')),
            trim((string) ($payload['project_id'] ?? $sessionUser['project_id'] ?? '')) ?: null
        );
        respondJson($response, 'success', 'Tienda ecommerce cargada', ['store' => $store, 'item' => $store]);
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 404);
        return;
    }
}

if ($route === 'ecommerce/create-sync-job') {
    if ($method !== 'POST') {
        respondJson($response, 'error', 'Metodo no permitido', [], 405);
        return;
    }

    $sessionUser = resolveAuthenticatedSessionUser();
    if (empty($sessionUser)) {
        respondJson($response, 'error', 'Acceso no autorizado para este recurso.', [], 401);
        return;
    }

    $tenantId = trim((string) ($sessionUser['tenant_id'] ?? ''));
    $payload = requestData();
    setTenantContext(['tenant_id' => $tenantId], true);
    RoleContext::setRole((string) ($sessionUser['role'] ?? 'admin'));
    RoleContext::setUserId((string) ($sessionUser['id'] ?? 'anon'));
    RoleContext::setUserLabel((string) ($sessionUser['label'] ?? $sessionUser['name'] ?? ''));

    try {
        $syncJob = (new EcommerceHubService())->createSyncJob($payload + [
            'tenant_id' => $tenantId,
            'app_id' => trim((string) ($payload['project_id'] ?? $sessionUser['project_id'] ?? '')) ?: null,
        ]);
        respondJson($response, 'success', 'Sync job ecommerce creado', ['sync_job' => $syncJob, 'item' => $syncJob]);
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 422);
        return;
    }
}

if ($route === 'ecommerce/list-sync-jobs') {
    if (!in_array($method, ['GET', 'POST'], true)) {
        respondJson($response, 'error', 'Metodo no permitido', [], 405);
        return;
    }

    $sessionUser = resolveAuthenticatedSessionUser();
    if (empty($sessionUser)) {
        respondJson($response, 'error', 'Acceso no autorizado para este recurso.', [], 401);
        return;
    }

    $tenantId = trim((string) ($sessionUser['tenant_id'] ?? ''));
    $payload = array_merge($_GET, requestData());
    setTenantContext(['tenant_id' => $tenantId], true);
    RoleContext::setRole((string) ($sessionUser['role'] ?? 'admin'));
    RoleContext::setUserId((string) ($sessionUser['id'] ?? 'anon'));
    RoleContext::setUserLabel((string) ($sessionUser['label'] ?? $sessionUser['name'] ?? ''));

    try {
        $items = (new EcommerceHubService())->listSyncJobs(
            $tenantId,
            $payload,
            trim((string) ($payload['project_id'] ?? $sessionUser['project_id'] ?? '')) ?: null
        );
        respondJson($response, 'success', 'Sync jobs ecommerce cargados', ['items' => $items, 'result_count' => count($items)]);
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 422);
        return;
    }
}

if ($route === 'ecommerce/list-order-refs') {
    if (!in_array($method, ['GET', 'POST'], true)) {
        respondJson($response, 'error', 'Metodo no permitido', [], 405);
        return;
    }

    $sessionUser = resolveAuthenticatedSessionUser();
    if (empty($sessionUser)) {
        respondJson($response, 'error', 'Acceso no autorizado para este recurso.', [], 401);
        return;
    }

    $tenantId = trim((string) ($sessionUser['tenant_id'] ?? ''));
    $payload = array_merge($_GET, requestData());
    setTenantContext(['tenant_id' => $tenantId], true);
    RoleContext::setRole((string) ($sessionUser['role'] ?? 'admin'));
    RoleContext::setUserId((string) ($sessionUser['id'] ?? 'anon'));
    RoleContext::setUserLabel((string) ($sessionUser['label'] ?? $sessionUser['name'] ?? ''));

    try {
        $items = (new EcommerceHubService())->getOrderRefsByStore(
            $tenantId,
            trim((string) ($payload['store_id'] ?? '')),
            $payload,
            trim((string) ($payload['project_id'] ?? $sessionUser['project_id'] ?? '')) ?: null
        );
        respondJson($response, 'success', 'Referencias de pedidos ecommerce cargadas', ['items' => $items, 'result_count' => count($items)]);
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 422);
        return;
    }
}

if ($route === 'media/access') {
    if ($method !== 'GET') {
        respondJson($response, 'error', 'Metodo no permitido', [], 405);
        return;
    }

    $mediaId = trim((string) ($_GET['id'] ?? $_GET['media_id'] ?? ''));
    $variant = trim((string) ($_GET['variant'] ?? 'original'));
    if ($mediaId === '') {
        respondJson($response, 'error', 'media_id requerido', [], 400);
        return;
    }

    $sessionUser = resolveAuthenticatedSessionUser();
    $tenantId = '';
    $appId = null;
    $actorId = 'token';

    if (!empty($sessionUser)) {
        $tenantId = trim((string) ($sessionUser['tenant_id'] ?? ''));
        if ($tenantId === '') {
            respondJson($response, 'error', 'Acceso no autorizado para este recurso.', [], 403);
            return;
        }
        $projectId = trim((string) ($_GET['project_id'] ?? $sessionUser['project_id'] ?? ''));
        $appId = $projectId !== '' ? $projectId : null;
        $actorId = (string) ($sessionUser['id'] ?? 'anon');
        setTenantContext(['tenant_id' => $tenantId], true);
        RoleContext::setRole((string) ($sessionUser['role'] ?? 'admin'));
        RoleContext::setUserId($actorId);
        RoleContext::setUserLabel((string) ($sessionUser['label'] ?? $sessionUser['name'] ?? ''));
    } else {
        $token = resolveRecordsReadToken();
        $verified = MediaAccessToken::verify($token, mediaAccessSecret());
        if (!(bool) ($verified['ok'] ?? false)) {
            respondJson($response, 'error', 'Acceso no autorizado para este recurso.', [], 401);
            return;
        }

        $tokenPayload = is_array($verified['payload'] ?? null) ? (array) $verified['payload'] : [];
        $scope = trim((string) ($tokenPayload['scope'] ?? ''));
        $tokenTenantId = trim((string) ($tokenPayload['tenant_id'] ?? ''));
        $tokenMediaId = trim((string) ($tokenPayload['media_id'] ?? ''));
        $tokenVariant = trim((string) ($tokenPayload['variant'] ?? ''));
        $path = trim((string) ($tokenPayload['path'] ?? ''));
        $exp = (int) ($tokenPayload['exp'] ?? 0);
        $now = time();
        if ($scope !== 'media:access' || $tokenTenantId === '' || $tokenMediaId !== $mediaId || $path !== 'media/access') {
            respondJson($response, 'error', 'Acceso no autorizado para este recurso.', [], 403);
            return;
        }
        if ($tokenVariant !== '' && $tokenVariant !== $variant) {
            respondJson($response, 'error', 'Acceso no autorizado para este recurso.', [], 403);
            return;
        }
        if ($exp <= 0 || $exp < $now || $exp > ($now + mediaAccessTtlSec() + 5)) {
            respondJson($response, 'error', 'Acceso no autorizado para este recurso.', [], 401);
            return;
        }

        $tenantId = $tokenTenantId;
        $tokenAppId = trim((string) ($tokenPayload['app_id'] ?? ''));
        $appId = $tokenAppId !== '' ? $tokenAppId : null;
        setTenantContext(['tenant_id' => $tenantId], true);
        RoleContext::setRole('guest');
        RoleContext::setUserId($actorId);
        RoleContext::setUserLabel('signed_token');
    }

    try {
        $access = (new MediaService())->resolveAccess($tenantId, $mediaId, $variant, $appId, $actorId);
        $absolutePath = (string) ($access['absolute_path'] ?? '');
        if ($absolutePath === '' || !is_file($absolutePath) || !is_readable($absolutePath)) {
            respondJson($response, 'error', 'Archivo no disponible', [], 404);
            return;
        }

        $fileName = preg_replace('/[^a-zA-Z0-9._-]+/', '_', (string) ($access['file_name'] ?? 'archivo')) ?: 'archivo';
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: ' . (string) ($access['mime_type'] ?? 'application/octet-stream'));
        if (is_numeric($access['file_size'] ?? null) && (int) $access['file_size'] > 0) {
            header('Content-Length: ' . (int) $access['file_size']);
        }
        header('Content-Disposition: inline; filename="' . $fileName . '"');
        header('X-Content-Type-Options: nosniff');
        $stream = fopen($absolutePath, 'rb');
        if (!is_resource($stream)) {
            http_response_code(500);
            exit;
        }
        fpassthru($stream);
        fclose($stream);
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 404);
        return;
    }
}

if (str_starts_with($route, 'records/')) {
    $parts = explode('/', $route);
    $entity = $parts[1] ?? '';
    $id = $parts[2] ?? null;
    $mutationContext = null;

    if ($entity === '') {
        respondJson($response, 'error', 'Entidad requerida', [], 400);
        return;
    }

    try {
        if ($method === 'GET') {
            $requestTenantId = trim((string) ($_GET['tenant_id'] ?? ($_SERVER['HTTP_X_TENANT_ID'] ?? '')));
            $sessionUser = is_array($_SESSION['auth_user'] ?? null) ? (array) $_SESSION['auth_user'] : [];
            $authTenantId = trim((string) ($sessionUser['tenant_id'] ?? ''));
            $authMode = '';

            if (!empty($sessionUser)) {
                if ($requestTenantId === '' && $authTenantId !== '') {
                    $_GET['tenant_id'] = $authTenantId;
                    $requestTenantId = $authTenantId;
                }
                if ($requestTenantId === '') {
                    auditRecordsReadAccess($route, 'denied', 'session', '', 'missing_tenant');
                    respondJson($response, 'error', 'Acceso no autorizado para este recurso.', [], 403);
                    return;
                }
                if ($authTenantId !== '' && $requestTenantId !== $authTenantId) {
                    auditRecordsReadAccess($route, 'denied', 'session', $requestTenantId, 'tenant_mismatch');
                    respondJson($response, 'error', 'Acceso no autorizado para este recurso.', [], 403);
                    return;
                }
                $authMode = 'session';
            } else {
                $tokenCheck = verifySignedRecordsReadToken(
                    resolveRecordsReadToken(),
                    $method,
                    $route,
                    $requestTenantId !== '' ? $requestTenantId : null,
                    $id !== null && $id !== '' ? (string) $id : null
                );
                if (!(bool) ($tokenCheck['ok'] ?? false)) {
                    $code = (int) ($tokenCheck['code'] ?? 401);
                    $reason = (string) ($tokenCheck['reason'] ?? 'invalid_token');
                    auditRecordsReadAccess($route, 'denied', 'signed_token', '', $reason);
                    respondJson($response, 'error', 'Acceso no autorizado para este recurso.', [], $code);
                    return;
                }
                $tokenTenantId = trim((string) ($tokenCheck['tenant_id'] ?? ''));
                if ($tokenTenantId === '') {
                    auditRecordsReadAccess($route, 'denied', 'signed_token', '', 'missing_tenant');
                    respondJson($response, 'error', 'Acceso no autorizado para este recurso.', [], 403);
                    return;
                }
                if ($requestTenantId === '') {
                    $_GET['tenant_id'] = $tokenTenantId;
                    $requestTenantId = $tokenTenantId;
                }
                if ($requestTenantId !== $tokenTenantId) {
                    auditRecordsReadAccess($route, 'denied', 'signed_token', $requestTenantId, 'tenant_mismatch');
                    respondJson($response, 'error', 'Acceso no autorizado para este recurso.', [], 403);
                    return;
                }
                $authMode = 'signed_token';
            }

            setTenantContext($_GET);
            auditRecordsReadAccess($route, 'allowed', $authMode, $requestTenantId, 'ok');
            $command = new CommandLayer();
            if ($id !== null && $id !== '') {
                $data = $command->readRecord($entity, $id, true);
                respondJson($response, 'success', 'Registro cargado', $data);
            } else {
                $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 100;
                $offset = isset($_GET['offset']) ? (int) $_GET['offset'] : 0;
                $filters = $_GET['filter'] ?? [];
                if (!is_array($filters)) {
                    $filters = [];
                }
                $data = $command->queryRecords($entity, $filters, $limit, $offset);
                respondJson($response, 'success', 'Registros cargados', $data);
            }
            return;
        }

        if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $mutationPayload = in_array($method, ['POST', 'PUT', 'PATCH'], true) ? requestData() : [];
            $mutationContext = requireAuthenticatedRecordsMutation($route, $method, $mutationPayload);
            if (!(bool) ($mutationContext['ok'] ?? false)) {
                $reason = (string) ($mutationContext['reason'] ?? 'unauthorized');
                $authMode = (string) ($mutationContext['auth_mode'] ?? 'none');
                $code = (int) ($mutationContext['code'] ?? 403);
                auditRecordsMutationAccess($route, $method, 'denied', $authMode, '', $reason);
                respondJson($response, 'error', 'Acceso no autorizado para este recurso.', [], $code);
                return;
            }

            $boundTenantId = trim((string) ($mutationContext['tenant_id'] ?? ''));
            if ($boundTenantId !== '') {
                setTenantContext(['tenant_id' => $boundTenantId], true);
            }
            auditRecordsMutationAccess($route, $method, 'allowed', 'session', $boundTenantId, 'ok');
        }

        if ($method === 'POST') {
            $payload = is_array($mutationContext['payload'] ?? null) ? (array) $mutationContext['payload'] : requestData();
            $command = new CommandLayer();
            $data = $command->createRecord($entity, $payload);
            respondJson($response, 'success', 'Registro creado', $data);
            return;
        }

        if ($method === 'PUT' || $method === 'PATCH') {
            if ($id === null || $id === '') {
                respondJson($response, 'error', 'ID requerido', [], 400);
                return;
            }
            $payload = is_array($mutationContext['payload'] ?? null) ? (array) $mutationContext['payload'] : requestData();
            $command = new CommandLayer();
            $data = $command->updateRecord($entity, $id, $payload);
            respondJson($response, 'success', 'Registro actualizado', $data);
            return;
        }

        if ($method === 'DELETE') {
            if ($id === null || $id === '') {
                respondJson($response, 'error', 'ID requerido', [], 400);
                return;
            }
            $command = new CommandLayer();
            $data = $command->deleteRecord($entity, $id);
            respondJson($response, 'success', 'Registro eliminado', $data);
            return;
        }

        respondJson($response, 'error', 'Metodo no soportado', [], 405);
        return;
    } catch (\InvalidArgumentException $e) {
        respondJson($response, 'error', $e->getMessage(), [], 422);
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 500);
        return;
    }
}

if ($route === 'command') {
    $payload = requestData();
    setTenantContext($payload);
    $commandName = $payload['command'] ?? '';
    $entity = $payload['entity'] ?? '';
    $command = new CommandLayer();

    if ($commandName === '' && (isset($payload['message']) || isset($payload['text']))) {
        $parsed = parseChatMessage($payload);
        if (isset($parsed['error'])) {
            respondJson($response, 'error', $parsed['error'], [], 422);
            return;
        }
        $commandName = $parsed['command'];
        $entity = $parsed['entity'];
        if (!empty($parsed['data'])) {
            $payload['data'] = $parsed['data'];
        }
        if (!empty($parsed['filters'])) {
            $payload['filters'] = $parsed['filters'];
        }
        if (!empty($parsed['id'])) {
            $payload['id'] = $parsed['id'];
        }
    }

    try {
        switch ($commandName) {
            case 'CreateRecord':
                $data = $command->createRecord($entity, $payload['data'] ?? $payload);
                respondJson($response, 'success', 'Registro creado', [
                    'data' => $data,
                    'view_compact' => "Registro creado en {$entity}",
                    'view_link' => "/{$entity}",
                ]);
                return;
            case 'QueryRecords':
                $filters = $payload['filters'] ?? [];
                $limit = (int) ($payload['limit'] ?? 100);
                $offset = (int) ($payload['offset'] ?? 0);
                $data = $command->queryRecords($entity, $filters, $limit, $offset);
                respondJson($response, 'success', 'Registros cargados', [
                    'data' => $data,
                    'view_compact' => "Registros de {$entity}",
                    'view_link' => "/{$entity}",
                ]);
                return;
            case 'ReadRecord':
                $id = $payload['id'] ?? null;
                $data = $command->readRecord($entity, $id, true);
                respondJson($response, 'success', 'Registro cargado', [
                    'data' => $data,
                    'view_compact' => "Registro de {$entity}",
                    'view_link' => "/{$entity}",
                ]);
                return;
            case 'UpdateRecord':
                $id = $payload['id'] ?? null;
                $data = $command->updateRecord($entity, $id, $payload['data'] ?? $payload);
                respondJson($response, 'success', 'Registro actualizado', [
                    'data' => $data,
                    'view_compact' => "Registro actualizado en {$entity}",
                    'view_link' => "/{$entity}",
                ]);
                return;
            case 'DeleteRecord':
                $id = $payload['id'] ?? null;
                $data = $command->deleteRecord($entity, $id);
                respondJson($response, 'success', 'Registro eliminado', [
                    'data' => $data,
                    'view_compact' => "Registro eliminado en {$entity}",
                    'view_link' => "/{$entity}",
                ]);
                return;
            default:
                respondJson($response, 'error', 'Comando no soportado', [], 400);
                return;
        }
    } catch (\InvalidArgumentException $e) {
        respondJson($response, 'error', $e->getMessage(), [], 422);
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 500);
        return;
    }
}

// --------------------------------
// 2. Resolver controlador y método
// --------------------------------
$parts = explode('/', $route);

$controllerClass = 'App\\Controller\\' . ucfirst(strtolower($parts[0])) . 'Controller';
$method          = strtolower($parts[1] ?? 'index');

// --------------------------------
// 3. Validar controlador
// --------------------------------
if (!class_exists($controllerClass)) {
    echo $response->json(
        'error',
        "El controlador {$controllerClass} no existe"
    );
    return;
}

// --------------------------------
// 4. Instanciar controlador
// --------------------------------
$controller = new $controllerClass();

// --------------------------------
// 5. Validar método
// --------------------------------
if (!method_exists($controller, $method)) {
    echo $response->json(
        'error',
        "El método {$method} no existe en {$controllerClass}"
    );
    return;
}

// --------------------------------
// 6. Ejecutar acción
// --------------------------------
$result = $controller->$method($_POST);

// Si el controlador retorna algo
if (is_string($result)) {
    echo $result;
}







