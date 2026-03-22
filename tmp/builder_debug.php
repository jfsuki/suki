<?php
// Diagnóstico específico del BuilderOnboardingProcess con LLM real
define('PROJECT_ROOT', 'c:/laragon/www/suki/project');
require_once PROJECT_ROOT . '/config/env_loader.php';
$frameworkRoot = getenv('SUKI_FRAMEWORK_ROOT') ?: dirname(PROJECT_ROOT) . '/framework';
define('APP_ROOT', $frameworkRoot);
define('FRAMEWORK_ROOT', $frameworkRoot);
require_once APP_ROOT . '/vendor/autoload.php';
require_once APP_ROOT . '/app/autoload.php';

echo "=== LLM CHAT CON PROMPT DEL BUILDER ===\n";
$llm = new \App\Core\LLM\LLMRouter();

$capsule = [
    'policy' => ['requires_strict_json' => true],
    'messages' => [
        ['role' => 'system', 'content' => 'Eres un Arquitecto de Software. Responde ÚNICAMENTE en JSON: {"intent":"onboarding_step_resolved","reply":"...","mapped_fields":{},"needs_clarification":false}'],
        ['role' => 'user', 'content' => 'hola quiero crear una tienda de ropa']
    ]
];

$raw = $llm->chat($capsule);

echo "KEYS DE RESPUESTA: " . implode(', ', array_keys($raw)) . "\n";
echo "provider: " . ($raw['provider'] ?? 'N/A') . "\n";
echo "text: " . substr($raw['text'] ?? '', 0, 200) . "\n";
echo "json: " . json_encode($raw['json'] ?? null) . "\n";
echo "reply key?: " . (isset($raw['reply']) ? 'YES' : 'NO') . "\n";
echo "data key?: " . (isset($raw['data']) ? 'YES' : 'NO') . "\n";

echo "\n=== SIMULACIÓN DE BuilderOnboardingProcess->execute() ===\n";
// Simular la línea exacta que está fallando en BuilderOnboardingProcess
$llmResponse = $raw;
$llmOutput = is_array($llmResponse['data'] ?? null) ? $llmResponse['data'] : json_decode($llmResponse['reply'] ?? '{}', true);
echo "llmOutput (de 'data' o 'reply'): " . json_encode($llmOutput) . "\n";
echo "\nProblema: la key real es 'json', no 'reply' ni 'data'\n";
$llmOutputFixed = $llmResponse['json'] ?? json_decode($llmResponse['text'] ?? '{}', true);
echo "llmOutput correcto (de 'json' o 'text'): " . json_encode($llmOutputFixed) . "\n";
echo "\nintent: " . ($llmOutputFixed['intent'] ?? 'MISSING') . "\n";
echo "reply: " . ($llmOutputFixed['reply'] ?? 'MISSING') . "\n";
