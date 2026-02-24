<?php
// framework/tests/openapi_integration_importer_test.php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\OpenApiIntegrationImporter;

$openapi = [
    'openapi' => '3.0.1',
    'servers' => [
        ['url' => 'https://api.payments.example.com/v1'],
    ],
    'components' => [
        'securitySchemes' => [
            'BearerAuth' => [
                'type' => 'http',
                'scheme' => 'bearer',
            ],
        ],
    ],
    'paths' => [
        '/charges' => [
            'post' => ['operationId' => 'createCharge', 'summary' => 'Create charge'],
            'get' => ['operationId' => 'listCharges', 'summary' => 'List charges'],
        ],
    ],
];

$importer = new OpenApiIntegrationImporter();
$failures = [];
try {
    $result = $importer->import([
        'api_name' => 'paymentsx',
        'provider' => 'PaymentsX',
        'country' => 'CO',
        'environment' => 'sandbox',
        'type' => 'payments',
        'openapi' => $openapi,
    ], false);
    $contract = is_array($result['contract'] ?? null) ? (array) $result['contract'] : [];
    if ((string) ($contract['id'] ?? '') !== 'paymentsx') {
        $failures[] = 'Imported contract id mismatch.';
    }
    if ((string) ($contract['auth']['type'] ?? '') !== 'bearer') {
        $failures[] = 'Auth detection should map to bearer.';
    }
    $endpoints = is_array($contract['metadata']['endpoints'] ?? null) ? (array) $contract['metadata']['endpoints'] : [];
    if (count($endpoints) !== 2) {
        $failures[] = 'Endpoint extraction should detect 2 operations.';
    }
} catch (\Throwable $e) {
    $failures[] = $e->getMessage();
}

$ok = empty($failures);
echo json_encode(['ok' => $ok, 'failures' => $failures], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 1);

