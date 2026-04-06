<?php
// framework/tests/count_tenants.php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\QdrantVectorStore;

echo "--- Distribución de Tenant en 'agent_training' ---\n";

try {
    $store = (new QdrantVectorStore())->forMemoryType('agent_training');
    $offset = null;
    $counts = [];

    do {
        $results = $store->scroll(100, $offset);
        foreach ($results['result']['points'] as $point) {
            $t = $point['payload']['tenant_id'] ?? 'NULL';
            $counts[$t] = ($counts[$t] ?? 0) + 1;
        }
        $offset = $results['result']['next_page_offset'] ?? null;
    } while ($offset !== null);

    print_r($counts);

} catch (\Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
