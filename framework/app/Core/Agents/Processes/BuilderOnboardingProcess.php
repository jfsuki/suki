<?php
declare(strict_types=1);
// app/Core/Agents/Processes/BuilderOnboardingProcess.php

namespace App\Core\Agents\Processes;

use App\Core\Agents\Memory\MemoryWindow;
use App\Core\CommandBus;
use App\Core\LLM\LLMRouter;

/**
 * Agente Especialista: Onboarding de Negocios
 * Reemplaza los miles de IF/ELSE de Regex en ConversationGatewayBuilderOnboardingTrait.
 * Usa un enfoque de prompts estrictos y llamadas locales limitadas.
 */
class BuilderOnboardingProcess
{
    private array $allowedSteps = [
        'business_type', 'operation_model', 'needs_scope', 'documents'
    ];

    public function __construct()
    {
        // Require LLMRouter / API Clients here
    }

    /**
     * @param string $userText Lo que dijo el user
     * @param MemoryWindow $memory 
     * @param CommandBus|null $bus Ejecutor de acciones reales
     * @param LLMRouter|null $llm Motor de IA estructural
     * @return array
     */
    public function execute(string $userText, MemoryWindow $memory, ?CommandBus $bus = null, ?LLMRouter $llm = null): array
    {
        $context = $memory->compileLlmContext(new \App\Core\Agents\Memory\TokenBudgeter(), 400); // Max 400 tokens
        
        $activeTask = $context['active_task'] ?? 'business_type';
        if (!in_array($activeTask, $this->allowedSteps, true)) {
            $activeTask = 'business_type';
        }

        // 1. Simulación de "Soft Parsing". Aquí iría la carga del Prompt JSON Estricto y la llamada a Mistral API
        // Example: load file Core/Prompts/builder_step_{$activeTask}.json
        // En esta reconstrucción, mockeamos el core local para aislar la arquitectura:
        
        // 1. Llamada REAL a LLMRouter (CrewAI Step)
        $llmOutput = [];
        if ($llm) {
            $capsule = [
                'policy' => [
                    'requires_strict_json'   => true,
                    'system_prompt_override' => $this->getSystemPrompt($activeTask, $context['long_term_facts']),
                ],
                'user_message' => $userText,
            ];
            $llmResponse = $llm->chat($capsule);
            // LLMRouter devuelve: {provider, text, json, usage, ...}
            // NO tiene 'reply' ni 'data'. Leer 'json' primero, luego parsear 'text'.
            if (is_array($llmResponse['json'] ?? null)) {
                $llmOutput = $llmResponse['json'];
            } else {
                $llmOutput = json_decode($llmResponse['text'] ?? '{}', true) ?? [];
            }
        }

        // 2. Validación de Contrato (PHP tiene la autoridad)
        if (!isset($llmOutput['intent'], $llmOutput['reply'])) {
            return [
                'action' => 'ask_user',
                'reply' => 'No pude procesar tu solicitud de construcción. ¿Podrías ser más específico con el tipo de negocio?',
                'state_updates' => []
            ];
        }

        if ($llmOutput['needs_clarification'] ?? false) {
            return [
                'action' => 'ask_user',
                'reply' => $llmOutput['reply'],
                'state_updates' => []
            ];
        }

        // 3. Ejecución Exitosa
        $nextStep = $this->getNextStep($activeTask);
        $collected = $llmOutput['mapped_fields'] ?? [];

        if ($nextStep === 'completed') {
            return [
                'action' => 'execute_command',
                'command' => [
                    'command' => 'InstallPlaybook',
                    'sector' => $collected['sector'] ?? $context['long_term_facts']['sector'] ?? 'GENERIC',
                    'options' => $collected
                ],
                'reply' => '¡Todo listo! Estoy construyendo tu aplicación de ' . ($collected['business_type'] ?? 'negocio') . '. En unos segundos verás las tablas en el panel izquierdo.',
                'state_updates' => ['active_task' => 'completed'],
                'telemetry' => ['agent' => 'BuilderOnboardingProcess', 'onboarding_complete' => true]
            ];
        }

        return [
            'action' => 'save_step',
            'reply' => $llmOutput['reply'],
            'state_updates' => [
                'active_task' => $nextStep,
                'collected' => $collected
            ],
            'telemetry' => ['agent' => 'BuilderOnboardingProcess', 'llm_called' => true]
        ];
    }

    private function getNextStep(string $current): string
    {
        $idx = array_search($current, $this->allowedSteps, true);
        if ($idx !== false && isset($this->allowedSteps[$idx + 1])) {
            return $this->allowedSteps[$idx + 1];
        }
        return 'completed';
    }

    private function getSystemPrompt(string $step, array $facts): string
    {
        $factsJson = json_encode($facts, JSON_UNESCAPED_UNICODE);
        return "Eres un Arquitecto de Software experto en Onboarding de Negocios para SUKI AI-AOS.
Actualmente estás en el paso: [{$step}].
Hechos conocidos del negocio: {$factsJson}

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
    }
}
