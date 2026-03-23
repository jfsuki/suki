<?php
require_once __DIR__ . '/framework/app/autoload.php';

$url = getenv('QDRANT_URL');
$key = getenv('QDRANT_API_KEY');
$collection = 'agent_training';

$ch = curl_init($url . "/collections/$collection/points/scroll");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'limit' => 50,
    'with_payload' => true
]));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'api-key: ' . $key
]);

$response = curl_exec($ch);
$data = json_decode($response, true);
curl_close($ch);

$tenants = [];
$samples = [];
foreach ($data['result']['points'] ?? [] as $p) {
    $t = $p['payload']['tenant_id'] ?? 'none';
    $tenants[$t] = ($tenants[$t] ?? 0) + 1;
    $samples[] = [
        'id' => $p['id'],
        'tenant' => $t,
        'memory_type' => $p['payload']['memory_type'] ?? 'none',
        'text' => mb_substr($p['payload']['text'] ?? '', 0, 50) . '...'
    ];
}

echo json_encode([
    'tenants_count' => $tenants,
    'sample_points' => array_slice($samples, 0, 5)
], JSON_PRETTY_PRINT) . "\n";
