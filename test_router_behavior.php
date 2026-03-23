<?php
require_once __DIR__ . '/framework/app/autoload.php';

use App\Core\IntentRouter;
use App\Core\Contracts\ContractRepository;

$router = new IntentRouter();

$cases = [
    "que hay hecho",
    "hola",
    "Hola, soy Ana"
];

foreach ($cases as $text) {
    echo "--- Testing: '$text' ---\n";
    $gatewayResult = [
        'intent' => 'unknown',
        'action' => 'respond_local', // Simulating what ConversationGateway does
        'reply' => 'Hola, soy Cami. Dime que necesitas crear o consultar.',
        'message_text' => $text
    ];

    $context = [
        'tenant_id' => 'system',
        'user_id' => 'test_agent',
        'project_id' => 'suki',
        'role' => 'admin',
        'mode' => 'app',
        'message_text' => $text
    ];

    $route = $router->route($gatewayResult, $context);

    echo "Action: " . $route->kind() . "\n";
    echo "Reply: " . $route->reply() . "\n";
    echo "Telemetry: " . json_encode($route->telemetry(), JSON_PRETTY_PRINT) . "\n\n";
}
