<?php
// framework/tests/inspect_training_data.php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\QdrantVectorStore;

echo "--- Inspeccionando Colección 'agent_training' ---\n";

try {
    $store = (new QdrantVectorStore())->forMemoryType('agent_training');
    $results = $store->scroll(5);

    if (empty($results['result']['points'])) {
        echo "❌ La colección está vacía.\n";
    } else {
        foreach ($results['result']['points'] as $point) {
            echo "ID: " . $point['id'] . "\n";
            echo "Payload: " . json_encode($point['payload'], JSON_PRETTY_PRINT) . "\n";
            echo "---------------------------------\n";
        }
    }
} catch (\Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
