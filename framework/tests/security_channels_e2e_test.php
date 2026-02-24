<?php
// framework/tests/security_channels_e2e_test.php

declare(strict_types=1);

$helper = __DIR__ . '/api_route_turn.php';

/**
 * @return array{raw:string,json:array<string,mixed>|null}
 */
function runApiRouteE2E(string $helper, array $request): array
{
    $encoded = base64_encode((string) json_encode($request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($helper) . ' ' . escapeshellarg($encoded);
    $raw = (string) shell_exec($cmd);
    $json = json_decode($raw, true);
    return [
        'raw' => trim($raw),
        'json' => is_array($json) ? $json : null,
    ];
}

$failures = [];
$authSession = [
    'auth_user' => [
        'id' => 'sec_e2e_user',
        'role' => 'admin',
        'tenant_id' => 'default',
        'project_id' => 'default',
    ],
];

$openApiSpec = [
    'openapi' => '3.0.1',
    'info' => ['title' => 'Payments API', 'version' => '1.0.0'],
    'servers' => [['url' => 'https://api.payments.example.com/v1']],
    'components' => ['securitySchemes' => ['BearerAuth' => ['type' => 'http', 'scheme' => 'bearer']]],
    'paths' => [
        '/charges' => [
            'post' => ['operationId' => 'createCharge', 'summary' => 'Crear cobro'],
            'get' => ['operationId' => 'listCharges', 'summary' => 'Listar cobros'],
        ],
    ],
];

$openApiNoAuth = runApiRouteE2E($helper, [
    'route' => 'integrations/import_openapi',
    'method' => 'POST',
    'payload' => [
        'api_name' => 'payments_x',
        'persist' => false,
        'openapi_json' => json_encode($openApiSpec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ],
]);
$openApiNoAuthJson = $openApiNoAuth['json'];
if (!is_array($openApiNoAuthJson) || ($openApiNoAuthJson['status'] ?? '') !== 'error' || !str_contains((string) ($openApiNoAuthJson['message'] ?? ''), 'iniciar sesion')) {
    $failures[] = 'integrations/import_openapi debe bloquear POST sin sesion.';
}

$openApiAuth = runApiRouteE2E($helper, [
    'route' => 'integrations/import_openapi',
    'method' => 'POST',
    'session' => $authSession,
    'payload' => [
        'api_name' => 'payments_x',
        'persist' => false,
        'openapi_json' => json_encode($openApiSpec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ],
]);
$openApiAuthJson = $openApiAuth['json'];
if (!is_array($openApiAuthJson) || ($openApiAuthJson['status'] ?? '') !== 'success') {
    $failures[] = 'integrations/import_openapi autenticado debe responder success.';
} else {
    $endpointCount = (int) (($openApiAuthJson['data']['summary']['endpoint_count'] ?? 0));
    $persisted = (bool) ($openApiAuthJson['data']['summary']['persisted'] ?? true);
    if ($endpointCount < 1) {
        $failures[] = 'integrations/import_openapi debe detectar endpoints del OpenAPI.';
    }
    if ($persisted) {
        $failures[] = 'integrations/import_openapi con persist=false no debe guardar contrato.';
    }
}

$idor = runApiRouteE2E($helper, [
    'route' => 'chat/message',
    'method' => 'POST',
    'session' => $authSession,
    'payload' => [
        'tenant_id' => 'default',
        'project_id' => 'default',
        'user_id' => 'otro_usuario',
        'mode' => 'app',
        'message' => 'hola',
    ],
]);
$idorJson = $idor['json'];
if (!is_array($idorJson) || ($idorJson['status'] ?? '') !== 'error' || !str_contains((string) ($idorJson['message'] ?? ''), 'user_id diferente')) {
    $failures[] = 'chat/message debe bloquear intento IDOR con user_id distinto.';
}

$telegramSecret = runApiRouteE2E($helper, [
    'route' => 'channels/telegram/webhook',
    'method' => 'POST',
    'env' => [
        'TELEGRAM_WEBHOOK_SECRET' => 'expected-secret',
    ],
    'headers' => [
        'X-Telegram-Bot-Api-Secret-Token' => 'invalid-secret',
    ],
    'payload' => [
        'message' => [
            'chat' => ['id' => '12345'],
            'text' => 'hola',
        ],
    ],
]);
$telegramSecretJson = $telegramSecret['json'];
if (!is_array($telegramSecretJson) || ($telegramSecretJson['status'] ?? '') !== 'error' || !str_contains((string) ($telegramSecretJson['message'] ?? ''), 'secret invalido')) {
    $failures[] = 'channels/telegram/webhook debe validar secret cuando esta configurado.';
}

$whatsAppVerify = runApiRouteE2E($helper, [
    'route' => 'channels/whatsapp/webhook',
    'method' => 'GET',
    'env' => [
        'WHATSAPP_VERIFY_TOKEN' => 'wa-verify-token',
    ],
    'query' => [
        'hub_mode' => 'subscribe',
        'hub_verify_token' => 'wa-verify-token',
        'hub_challenge' => '123456',
    ],
]);
if ($whatsAppVerify['raw'] !== '123456') {
    $failures[] = 'channels/whatsapp/webhook GET debe responder challenge cuando verify token es valido.';
}

$ok = empty($failures);
echo json_encode([
    'ok' => $ok,
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 1);

