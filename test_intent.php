<?php
// test_intent.php

require_once __DIR__ . '/framework/vendor/autoload.php';
require_once __DIR__ . '/framework/app/autoload.php';

use App\Core\Agents\ConversationGateway;
use App\Core\SqlMemoryRepository;

try {
    $gateway = new ConversationGateway();
    $result = $gateway->handle('default', 'test_user', 'HOLA', 'builder', 'test_proj');
    echo "Gateway Response: \n";
    print_r($result);
} catch (\Throwable $e) {
    echo "Gateway Crash: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}
