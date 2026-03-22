<?php
require __DIR__ . '/framework/app/autoload.php';
require_once __DIR__ . '/framework/app/Core/Agents/Memory/TokenBudgeter.php';

use App\Core\Agents\Memory\TokenBudgeter;

$budgeter = new TokenBudgeter();

echo "--- Text Estimation ---\n";
$text = "Hola, necesito crear una aplicacion para mi restaurante. Vendemos hamburguesas.";
$tokens = $budgeter->estimate($text);
echo "Text (strlen=" . mb_strlen($text) . "): Estimados $tokens tokens.\n";

echo "\n--- Text Cropping (End) ---\n";
$long = str_repeat("abc def ", 100); // 800 chars = 200 tokens
echo "Original Tokens: " . $budgeter->estimate($long) . "\n";
$cropped = $budgeter->cropText($long, 50, 'end');
echo "Cropped Tokens: " . $budgeter->estimate($cropped) . "\n";

echo "\n--- Text Cropping (Start - History) ---\n";
$croppedStart = $budgeter->cropText($long, 50, 'start');
echo "Cropped Tokens: " . $budgeter->estimate($croppedStart) . "\n";

echo "\n--- JSON Array Cropping ---\n";
$items = [
    ['id' => 1, 'name' => 'Burger 1', 'desc' => 'very long description here to take up tokens and space...'],
    ['id' => 2, 'name' => 'Burger 2', 'desc' => 'another very long description that consumes precious api budget...'],
    ['id' => 3, 'name' => 'Burger 3', 'desc' => 'and a third one just in case the llm wants to read more text forever...'],
    ['id' => 4, 'name' => 'Burger 4', 'desc' => 'this one should definitely be cropped if the budget is tight!']
];
$jsonTokens = $budgeter->estimate(json_encode($items));
echo "Original JSON Tokens: $jsonTokens (Items: " . count($items) . ")\n";

$croppedJson = $budgeter->cropJsonArray($items, 50);
$newTokens = $budgeter->estimate(json_encode($croppedJson));
echo "Cropped JSON Tokens: $newTokens (Items: " . count($croppedJson) . ")\n";

echo "\nDone.\n";
