<?php
// Trazar exactamente qué pasa dentro del BuilderOnboardingProcess
define('PROJECT_ROOT', 'c:/laragon/www/suki/project');
require_once PROJECT_ROOT . '/config/env_loader.php';
$frameworkRoot = getenv('SUKI_FRAMEWORK_ROOT') ?: dirname(PROJECT_ROOT) . '/framework';
define('APP_ROOT', $frameworkRoot);
define('FRAMEWORK_ROOT', $frameworkRoot);
require_once APP_ROOT . '/vendor/autoload.php';
require_once APP_ROOT . '/app/autoload.php';

$llm = new \App\Core\LLM\LLMRouter();
$memory = new \App\Core\Agents\Memory\MemoryWindow(3);
$budgeter = new \App\Core\Agents\Memory\TokenBudgeter();

$memory->hydrateFromState(['tenant_id' => 'demo', 'mode' => 'builder'], []);
$context = $memory->compileLlmContext($budgeter, 400);

echo "=== CONTEXTO DE MEMORIA ===\n";
echo json_encode($context, JSON_PRETTY_PRINT) . "\n";

$activeTask = $context['active_task'] ?? 'business_type';
echo "\nactive_task: $activeTask\n";

$prompt = "Eres un Arquitecto de Software experto en Onboarding de Negocios para SUKI AI-AOS.
Actualmente estás en el paso: [{$activeTask}].
Hechos conocidos del negocio: " . json_encode($context['long_term_facts'] ?? []) . "

INSTRUCCIONES:
1. Analiza el texto del usuario para extraer la información del paso actual.
2. Si la información es clara, mapea los campos en 'mapped_fields'.
3. Si es ambigua, pon 'needs_clarification': true y pregunta en 'reply'.

DEBES RESPONDER ÚNICAMENTE CON JSON VÁLIDO con exactamente este formato, sin texto adicional:
{
  \"intent\": \"onboarding_step_resolved\",
  \"reply\": \"Frase empática confirmando lo recibido o preguntando al usuario\",
  \"mapped_fields\": {},
  \"needs_clarification\": false
}";

echo "\n=== PROMPT SISTEMA (primeros 200 chars) ===\n";
echo substr($prompt, 0, 200) . "\n";

$capsule = [
    'policy' => ['requires_strict_json' => true],
    'messages' => [
        ['role' => 'system', 'content' => $prompt],
        ['role' => 'user', 'content' => 'quiero crear una tienda de ropa']
    ]
];

echo "\n=== LLAMADA AL LLM ===\n";
try {
    $raw = $llm->chat($capsule);
    echo "provider: " . $raw['provider'] . "\n";
    echo "text: " . substr($raw['text'] ?? '', 0, 300) . "\n";
    echo "json: " . json_encode($raw['json'] ?? null) . "\n";
    
    $llmOutput = is_array($raw['json'] ?? null) ? $raw['json'] : (json_decode($raw['text'] ?? '{}', true) ?? []);
    echo "\nllmOutput: " . json_encode($llmOutput) . "\n";
    echo "intent: " . ($llmOutput['intent'] ?? 'MISSING') . "\n";
    echo "reply: " . ($llmOutput['reply'] ?? 'MISSING') . "\n";
    echo "Pipeline would " . (isset($llmOutput['intent'], $llmOutput['reply']) ? '✓ SUCCEED' : '✗ FAIL (fallback)') . "\n";
} catch (\Throwable $e) {
    echo "LLM ERROR: " . $e->getMessage() . "\n";
}
