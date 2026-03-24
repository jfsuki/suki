<?php
// framework/tests/unit_gateway_history_capsule.php
require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Agents\ConversationGateway;

$gateway = new ConversationGateway();
$state = [
    'last_messages' => [
        ['u' => 'Hola', 'a' => 'Holis', 'ts' => time()],
    ]
];

$reflector = new ReflectionClass(ConversationGateway::class);
$method = $reflector->getMethod('buildContextCapsule');
$method->setAccessible(true);
$capsule = $method->invoke($gateway, '¿Cómo me llamo?', $state, [], [], 'question');

echo "Capsule History:\n";
print_r($capsule['last_messages'] ?? []);

if (!empty($capsule['last_messages']) && $capsule['last_messages'][0]['u'] === 'Hola') {
    echo "\nSUCCESS: ConversationGateway history inclusion in capsule is correct.\n";
} else {
    echo "\nFAILURE: ConversationGateway history MISSING from capsule.\n";
    exit(1);
}
