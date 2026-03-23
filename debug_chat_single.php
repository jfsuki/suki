<?php
require_once 'framework/app/autoload.php';

use App\Core\ChatAgent;

$agent = new ChatAgent();
$payload = [
    'message' => 'hola',
    'user_id' => 'user_test',
    'session_id' => 'sess_test_' . time(),
    'tenant_id' => 'default',
    'mode' => 'app'
];

try {
    echo "Enviando mensaje 'hola' al agente...\n";
    $reply = $agent->handle($payload);
    echo "Respuesta: " . json_encode($reply, JSON_PRETTY_PRINT) . "\n";
} catch (\Throwable $e) {
    echo "CRASH: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}
