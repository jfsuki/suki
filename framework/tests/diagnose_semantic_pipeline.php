<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\Agents\ConversationGateway;

$gateway = new ConversationGateway('c:/laragon/www/suki/project');

$scenarios = [
    'Scenario 13: quiero crear una app' => 'quiero crear una app',
    'Scenario 14: crear producto' => 'crear producto',
    'Scenario 9: crear tabla clientes nombre:texto nit:texto' => 'crear tabla clientes nombre:texto nit:texto',
];

foreach ($scenarios as $name => $text) {
    echo "--- $name ---\n";
    $hasBuild = $gateway->hasBuildSignals($text);
    $isTrigger = $gateway->isBuilderOnboardingTrigger($text);
    
    echo "Text: $text\n";
    echo "hasBuildSignals: " . ($hasBuild ? 'TRUE' : 'FALSE') . "\n";
    echo "isBuilderOnboardingTrigger: " . ($isTrigger ? 'TRUE' : 'FALSE') . "\n";
    echo "\n";
}

// Reflection to check method origin
$rc = new ReflectionClass($gateway);
$mBuild = $rc->getMethod('hasBuildSignals');
$mTrigger = $rc->getMethod('isBuilderOnboardingTrigger');

echo "Reflection Result:\n";
echo "hasBuildSignals is in " . $mBuild->getDeclaringClass()->getName() . " (File: " . basename($mBuild->getFileName()) . " Line: " . $mBuild->getStartLine() . ")\n";
echo "isBuilderOnboardingTrigger is in " . $mTrigger->getDeclaringClass()->getName() . " (File: " . basename($mTrigger->getFileName()) . " Line: " . $mTrigger->getStartLine() . ")\n";
