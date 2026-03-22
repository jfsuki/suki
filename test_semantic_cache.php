<?php
require __DIR__ . '/framework/app/autoload.php';
require_once __DIR__ . '/framework/app/Core/RuntimeSchemaPolicy.php';
require_once __DIR__ . '/framework/app/Core/Agents/Memory/SemanticCache.php';

use App\Core\Agents\Memory\SemanticCache;

putenv('ALLOW_RUNTIME_SCHEMA=1');
putenv('APP_ENV=local');
$dbPath = __DIR__ . '/project/storage/meta/project_registry.sqlite';
$db = new \PDO('sqlite:' . $dbPath);
$db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

$cache = new SemanticCache($db, 3600);

echo "--- Semantic Cache Test ---\n";
$tenant = "101";
$mode = "builder";
$text = "quiero crear una factura";
$context = ['step' => 'operation_model', 'missing' => ['tipo_pago']];

$signature = $cache->generateSignature($tenant, $mode, $text, $context);
echo "Generated Signature: $signature\n";

$result = $cache->get($signature);
if ($result === null) {
    echo "Cache MISS! Simulating LLM Call...\n";
    $fakeLlmResponse = [
        'intent' => 'set_operation_model',
        'mapped_fields' => ['operation_model' => 'facturacion'],
        'reply' => 'Entendido, usarás facturación. ¿Cómo te pagan?',
        'confidence' => 0.95
    ];
    $cache->set($signature, $tenant, $mode, $fakeLlmResponse);
    echo "Saved to cache.\n";
} else {
    echo "Cache HIT! Previous response:\n";
    print_r($result);
}

// Check hit immediately after
$result2 = $cache->get($signature);
if ($result2 !== null) {
    echo "Second query: Cache HIT guaranteed!\n";
}

echo "Done.\n";
