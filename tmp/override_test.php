<?php
// Verificar si system_prompt_override llega correctamente a Mistral
define('PROJECT_ROOT', 'c:/laragon/www/suki/project');
require_once PROJECT_ROOT . '/config/env_loader.php';
$frameworkRoot = getenv('SUKI_FRAMEWORK_ROOT') ?: dirname(PROJECT_ROOT) . '/framework';
define('APP_ROOT', $frameworkRoot);
define('FRAMEWORK_ROOT', $frameworkRoot);
require_once APP_ROOT . '/vendor/autoload.php';
require_once APP_ROOT . '/app/autoload.php';

$llm = new \App\Core\LLM\LLMRouter();

// Test 1: system_prompt_override llega al LLM?
echo "=== TEST 1: system_prompt_override ===\n";
$result1 = $llm->chat([
    'policy' => [
        'requires_strict_json' => true,
        'system_prompt_override' => 'Eres un asistente de onboarding. Responde SOLO con JSON estricto en este formato exacto sin explicaciones adicionales: {"intent":"onboarding_step_resolved","reply":"aqui va tu respuesta","mapped_fields":{},"needs_clarification":false}',
    ],
    'user_message' => 'quiero crear una tienda de ropa',
]);
echo "provider: " . $result1['provider'] . "\n";
echo "text: " . substr($result1['text'] ?? '', 0, 300) . "\n";
echo "json keys: " . implode(', ', array_keys($result1['json'] ?? [])) . "\n";
echo "has intent: " . (isset($result1['json']['intent']) ? 'YES: '.$result1['json']['intent'] : 'NO') . "\n";
echo "has reply: " . (isset($result1['json']['reply']) ? 'YES: '.substr($result1['json']['reply'] ?? '', 0, 80) : 'NO') . "\n";

echo "\n=== TEST 2: SIN override (default) ===\n";
$result2 = $llm->chat([
    'policy' => ['requires_strict_json' => true],
    'user_message' => 'quiero crear una tienda de ropa',
]);
echo "text: " . substr($result2['text'] ?? '', 0, 200) . "\n";
echo "json keys: " . implode(', ', array_keys($result2['json'] ?? [])) . "\n";
