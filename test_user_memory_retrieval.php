<?php
require_once 'framework/app/autoload.php';

use App\Core\SemanticMemoryService;

$semantic = new SemanticMemoryService();
$query = "hola";
$scope = [
    'tenant_id' => 'default',
    'user_id' => 'user_1',
    'memory_type' => 'user_memory'
];

try {
    echo "Probando retrieval de user_memory...\n";
    $result = $semantic->retrieve($query, $scope, 3);
    echo "Resultado: " . json_encode($result, JSON_PRETTY_PRINT) . "\n";
} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}
