<?php
require_once __DIR__ . '/framework/app/autoload.php';
use App\Core\QdrantVectorStore;

$store = new QdrantVectorStore(null, null, null, null, null, null, null, 'agent_training');
$stats = $store->inspectCollection();
echo json_encode($stats, JSON_PRETTY_PRINT) . "\n";
