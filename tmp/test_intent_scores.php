<?php
require_once 'framework/vendor/autoload.php';
require_once 'framework/app/autoload.php';

use App\Core\SemanticMemoryService;

$memory = new SemanticMemoryService();
$queries = ["que hay hecho", "Ana", "hola"];

foreach ($queries as $q) {
    echo "--- QUERY: $q ---\n";
    try {
        $scope = ['memory_type' => 'agent_training', 'tenant_id' => '1'];
        $results = $memory->retrieve($q, $scope, 5);
        foreach ($results['hits'] as $r) {
            $meta = $r['metadata'] ?? [];
            echo sprintf("[%0.4f] Intent: %s, Action: %s, Content: %s\n", 
                $r['score'], 
                $meta['intent'] ?? '?', 
                $meta['action'] ?? '?',
                substr($r['content'] ?? '?', 0, 80)
            );
        }
    } catch (\Throwable $e) {
        echo "ERROR: " . $e->getMessage() . "\n";
    }
    echo "\n";
}
