<?php
require __DIR__ . '/framework/app/autoload.php';
require_once __DIR__ . '/framework/app/Core/Agents/Memory/TokenBudgeter.php';
require_once __DIR__ . '/framework/app/Core/Agents/Memory/MemoryWindow.php';
require_once __DIR__ . '/framework/app/Core/Agents/Tools/ToolCompressor.php';
require_once __DIR__ . '/framework/app/Core/Agents/Orchestrator/ToolExecutionLoop.php';

use App\Core\Agents\Memory\TokenBudgeter;
use App\Core\Agents\Memory\MemoryWindow;
use App\Core\Agents\Tools\ToolCompressor;
use App\Core\Agents\Orchestrator\ToolExecutionLoop;

echo "=== Phase 3: Tools & Self-Healing Test ===\n\n";

$budgeter = new TokenBudgeter();
$compressor = new ToolCompressor($budgeter);

// 1. ToolCompressor Test
echo ">> Testing ToolCompressor (Simulating 20 large DB rows)...\n";
$mockDbResults = [];
for ($i = 1; $i <= 20; $i++) {
    $mockDbResults[] = ['id' => $i, 'sku' => 'ITM-' . $i, 'name' => 'Producto de prueba ' . $i, 'price' => random_int(100, 500), 'description' => str_repeat("lorem ipsum dolor sit amet ", 10)];
}

$estimatedOriginal = $budgeter->estimate(json_encode($mockDbResults));
echo "   - Original Array tokens: " . $estimatedOriginal . " (20 items)\n";

$compressed = $compressor->compress($mockDbResults, 200, 5); // Hard limit 5, token budget 200
$jsonCompressed = json_encode($compressed, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
echo "   - Compressed Array tokens: " . $budgeter->estimate($jsonCompressed) . " (Items: " . count($compressed) . ")\n";
echo "   - Last Item (Notice): " . json_encode(end($compressed)) . "\n\n";

// 2. ToolExecutionLoop Test
echo ">> Testing Self-Healing ToolExecutionLoop (Simulating LLM Type Mismatch)...\n";
$loop = new ToolExecutionLoop(2); // Max 2 intents
$memory = new MemoryWindow(2);

$initialLlmOutput = [
    'intent' => 'create_invoice',
    'mapped_fields' => ['amount' => 'Mil quinientos dolares'] // Error! DB expects Integer
];

// PHP execution callback
$executionCallback = function($llmOutput) {
    echo "   [Tool] PHP intentando procesar invoice...\n";
    $amount = $llmOutput['mapped_fields']['amount'] ?? 0;
    if (!is_numeric($amount)) {
        throw new Exception("Validation Error: 'amount' must be numeric. Received: '$amount'");
    }
    echo "   [Tool] ÉXITO: Grabado monto $amount.\n";
    return ['action' => 'success', 'reply' => 'Factura creada exitosamente.'];
};

// LLM Recovery callback (mocking the LLM self-correcting)
$recoveryCallback = function($memory) {
    echo "   [LLM Recovery] Leyendo Short-Term Memory:\n";
    $history = $memory->getShortTermHistory();
    $lastMessage = end($history);
    echo "      > '{$lastMessage['text']}'\n";
    echo "   [LLM Recovery] Autocuración: LLM se da cuenta del error y corrige el JSON.\n";
    return [
        'intent' => 'create_invoice',
        'mapped_fields' => ['amount' => 1500]
    ];
};

$result = $loop->executeWithHealing($executionCallback, $recoveryCallback, $initialLlmOutput, $memory);
echo "\n   Final Orchestrator Result:\n";
print_r($result);
echo "Done.\n";
