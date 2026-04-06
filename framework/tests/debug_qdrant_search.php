<?php
// framework/tests/debug_qdrant_search.php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\GeminiEmbeddingService;
use App\Core\QdrantVectorStore;

$text = 'pierdo plata con los cables porque no se cuanta cantidad me queda';
$tenantId = 'suki_core';

echo "--- Debugging Qdrant Search ---\n";
echo "Query: $text\n";
echo "Tenant: $tenantId\n\n";

try {
    $embedder = new GeminiEmbeddingService();
    $store = new QdrantVectorStore();
    
    $embedding = $embedder->embed($text, ['task_type' => 'RETRIEVAL_QUERY']);
    
    $must = [
        ['key' => 'memory_type', 'match' => ['value' => 'agent_training']],
    ];
    $must[] = [
        'should' => [
            ['key' => 'tenant_id', 'match' => ['value' => $tenantId]],
            ['key' => 'tenant_id', 'match' => ['value' => 'system']],
        ],
    ];

    $results = $store->query($embedding['vector'], ['must' => $must], 3, true);

    if (empty($results)) {
        echo "❌ No se encontraron resultados en Qdrant.\n";
    } else {
        foreach ($results as $idx => $hit) {
            echo "Hit #" . ($idx + 1) . ":\n";
            echo "  Score: " . $hit['score'] . "\n";
            echo "  Intent: " . ($hit['payload']['metadata']['intent'] ?? 'N/A') . "\n";
            echo "  Content: " . mb_substr($hit['payload']['content'] ?? '', 0, 100) . "...\n";
            echo "  Metadata: " . json_encode($hit['payload']['metadata'] ?? []) . "\n\n";
        }
    }
} catch (\Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
