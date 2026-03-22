<?php
require __DIR__ . '/framework/app/autoload.php';
require_once __DIR__ . '/framework/app/Core/Agents/Memory/TokenBudgeter.php';
require_once __DIR__ . '/framework/app/Core/Agents/Memory/MemoryWindow.php';

use App\Core\Agents\Memory\TokenBudgeter;
use App\Core\Agents\Memory\MemoryWindow;

$budgeter = new TokenBudgeter();
$window = new MemoryWindow(2); // Keep only last 2 turns (4 messages)

echo "--- Memory Window Test ---\n";

// MOCK the SUKI state
$mockState = [
    'tenant_id' => '101',
    'project_id' => 'proj_x',
    'mode' => 'builder',
    'collected' => ['business_type' => 'restaurant'],
    'active_task' => 'operation_model',
    'history_log' => [
        ['role' => 'user', 'text' => 'Hola'],
        ['role' => 'assistant', 'text' => 'Hola, que vas a crear?'],
        ['role' => 'user', 'text' => 'Un restaurante'],
        ['role' => 'assistant', 'text' => 'Entendido, restaurante. Como cobras?'],
        ['role' => 'user', 'text' => 'Efectivo'],
        ['role' => 'assistant', 'text' => 'Anotado. Tienes mesas?'],
    ]
];

$mockProfile = ['sector' => 'food_beverage', 'business_profile' => ['tables' => true]];

// 1. Hydrate
$window->hydrateFromState($mockState, $mockProfile);

// 2. Add current user message
$window->appendShortTerm('user', 'Sí claro, tengo 10 mesas acá y también ' . str_repeat('muy grandes ', 50)); // Very long message

// 3. Compile context
$context = $window->compileLlmContext($budgeter, 50); // Hard budget of 50 tokens for the entire chat history

echo ">> Long-Term Facts:\n";
print_r($context['long_term_facts']);
echo "\n>> Active Task: " . $context['active_task'] . "\n";

echo "\n>> Short-Term History Compiled (Max 50 tokens):\n";
echo "====================\n";
echo $context['recent_history'] . "\n";
echo "====================\n";

echo "Estimated tokens of Compiled History: " . $budgeter->estimate($context['recent_history']) . "\n";
echo "Done.\n";
