<?php
require_once __DIR__ . '/vendor/autoload.php';

try {
    // Attempting to instantiate the gateway
    echo "Instantiating ConversationGateway...\n";
    $gateway = new \App\Core\Agents\ConversationGateway();
    echo "Success!\n";

    echo "Attempting to instantiate LLMRouter...\n";
    $router = new \App\Core\LLM\LLMRouter();
    echo "Success!\n";

    echo "Checking if resolveBuilderEntityConfusionWithLLM exists...\n";
    if (method_exists($gateway, 'resolveBuilderEntityConfusionWithLLM')) {
        echo "Method exists!\n";
    }
    else {
        echo "Method MISSING!\n";
    }

}
catch (\Throwable $t) {
    echo "FATAL ERROR found: " . $t->getMessage() . " in " . $t->getFile() . ":" . $t->getLine() . "\n";
    echo $t->getTraceAsString();
}
