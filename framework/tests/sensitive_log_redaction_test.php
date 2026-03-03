<?php
// framework/tests/sensitive_log_redaction_test.php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\Agents\Telemetry;
use App\Core\AuditLogger;
use App\Core\IntegrationStore;

$failures = [];
$runId = (string) time();
$previousAllow = getenv('ALLOW_RUNTIME_SCHEMA');
$previousAppEnv = getenv('APP_ENV');
putenv('APP_ENV=local');
putenv('ALLOW_RUNTIME_SCHEMA=1');

$tmpBase = __DIR__ . '/tmp/sensitive_log_redaction_' . $runId;
$projectRoot = $tmpBase . '/project';
if (!is_dir($projectRoot . '/storage') && !mkdir($projectRoot . '/storage', 0775, true) && !is_dir($projectRoot . '/storage')) {
    $failures[] = 'No se pudo crear directorio temporal para telemetry.';
}

$apiKey = 'AIza' . str_repeat('A', 24);
$bearer = 'Bearer sk-test-abcdefghijklmnopqrstuvwxyz1234';
$privateKey = "-----BEGIN PRIVATE KEY-----\nabc123\n-----END PRIVATE KEY-----";

$telemetry = new Telemetry($projectRoot);
$telemetry->record('tenant_demo', [
    'route_path' => 'cache>rules>rag>llm',
    'gate_decision' => 'allow',
    'versions' => ['router_policy' => '1.0.0'],
    'headers' => [
        'Authorization' => $bearer,
        'X-Api-Key' => 'wa-secret-key',
    ],
    'query' => 'foo=1&token=my_runtime_token',
    'payload' => [
        'secret' => 'super-secret-value',
        'details' => $privateKey,
        'provider_key' => 'provider-key-value',
    ],
    'raw' => 'key=abc123 sk-live-123456789012345678',
    'gemini_key' => $apiKey,
]);

$telemetryFile = $projectRoot . '/storage/tenants/tenant_demo/telemetry/' . date('Y-m-d') . '.log.jsonl';
$telemetryRaw = is_file($telemetryFile) ? (string) file_get_contents($telemetryFile) : '';
if ($telemetryRaw === '') {
    $failures[] = 'Telemetry no genero log para verificar redaccion.';
} else {
    assertContainsRedacted($telemetryRaw, 'telemetry', $failures);
    assertNoSensitiveLeak($telemetryRaw, 'telemetry', $failures);
}

$auditDb = new \PDO('sqlite::memory:');
$auditDb->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
$audit = new AuditLogger($auditDb);
$audit->log('integration_action', 'integration', 'row-1', [
    'Authorization' => $bearer,
    'password' => 'plain-password',
    'webhook_secret' => 'webhook-raw-secret',
]);

$auditPayload = (string) ($auditDb->query('SELECT payload FROM audit_log ORDER BY id DESC LIMIT 1')->fetchColumn() ?: '');
if ($auditPayload === '') {
    $failures[] = 'AuditLogger no persistio payload para validar redaccion.';
} else {
    assertContainsRedacted($auditPayload, 'audit_log', $failures);
    assertNoSensitiveLeak($auditPayload, 'audit_log', $failures);
}

$integrationDb = new \PDO('sqlite::memory:');
$integrationDb->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
$store = new IntegrationStore($integrationDb);
$store->logWebhook('wa_main', 'message.received', 'wamid.100', [
    'headers' => [
        'Authorization' => $bearer,
        'Cookie' => 'session=plain-cookie',
    ],
    'raw_text' => 'webhook_secret=my_hook_secret',
    'private' => $privateKey,
]);
$store->saveDocument(
    'wa_main',
    'invoice',
    '1',
    'external-1',
    'sent',
    [
        'token' => 'request-token-value',
        'Authorization' => $bearer,
        'query' => 'access_token=abc123',
    ],
    [
        'api_key' => 'response-api-key',
        'text' => 'token=respuesta',
    ]
);

$webhookPayload = (string) ($integrationDb->query('SELECT payload FROM integration_webhooks ORDER BY id DESC LIMIT 1')->fetchColumn() ?: '');
$docRow = $integrationDb->query('SELECT request_payload, response_payload FROM integration_documents ORDER BY id DESC LIMIT 1');
$docPayload = is_object($docRow) ? $docRow->fetch(\PDO::FETCH_ASSOC) : null;
$requestPayload = is_array($docPayload) ? (string) ($docPayload['request_payload'] ?? '') : '';
$responsePayload = is_array($docPayload) ? (string) ($docPayload['response_payload'] ?? '') : '';

if ($webhookPayload === '' || $requestPayload === '' || $responsePayload === '') {
    $failures[] = 'IntegrationStore no persistio payloads esperados para validar redaccion.';
} else {
    assertContainsRedacted($webhookPayload, 'integration_webhooks', $failures);
    assertNoSensitiveLeak($webhookPayload, 'integration_webhooks', $failures);
    assertContainsRedacted($requestPayload, 'integration_documents.request_payload', $failures);
    assertNoSensitiveLeak($requestPayload, 'integration_documents.request_payload', $failures);
    assertContainsRedacted($responsePayload, 'integration_documents.response_payload', $failures);
    assertNoSensitiveLeak($responsePayload, 'integration_documents.response_payload', $failures);
}

$ok = empty($failures);
echo json_encode([
    'ok' => $ok,
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

if ($previousAllow === false) {
    putenv('ALLOW_RUNTIME_SCHEMA');
} else {
    putenv('ALLOW_RUNTIME_SCHEMA=' . $previousAllow);
}
if ($previousAppEnv === false) {
    putenv('APP_ENV');
} else {
    putenv('APP_ENV=' . $previousAppEnv);
}

exit($ok ? 0 : 1);

/**
 * @param array<int, string> $failures
 */
function assertContainsRedacted(string $content, string $scope, array &$failures): void
{
    if (!str_contains($content, '[REDACTED]')) {
        $failures[] = $scope . ' debe contener marcador [REDACTED].';
    }
}

/**
 * @param array<int, string> $failures
 */
function assertNoSensitiveLeak(string $content, string $scope, array &$failures): void
{
    $patterns = [
        '/\bsk-[A-Za-z0-9_-]{12,}\b/i' => 'token tipo sk-*',
        '/\bAIza[0-9A-Za-z\-_]{20,}\b/' => 'API key tipo AIza*',
        '/-----BEGIN [A-Z ]*PRIVATE KEY-----/i' => 'private key block',
        '/Bearer\s+sk-/i' => 'bearer token sensible',
        '/\b(?:access_)?token=(?!\[REDACTED\])[^\s"&]+/i' => 'token en query/string',
        '/\bwebhook_secret=(?!\[REDACTED\])[^\s"&]+/i' => 'webhook secret en texto',
    ];

    foreach ($patterns as $regex => $label) {
        if (preg_match($regex, $content) === 1) {
            $failures[] = $scope . ' expone ' . $label . ' sin redaccion.';
        }
    }
}
