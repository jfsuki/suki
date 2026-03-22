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
        $context    = $memory->compileLlmContext(new \App\Core\Agents\Memory\TokenBudgeter(), 400);
        $activeTask = $context['active_task'] ?? 'business_type';
        if (!in_array($activeTask, $this->allowedSteps, true)) {
            $activeTask = 'business_type';
        }

        // FIX A3: FastPath para confirmaciones simples (si/no/ok) — sin LLM
        // Evita gastar tokens en respuestas obvias del usuario
        $fastPath = $this->isFastPathConfirmation($userText);
        if ($fastPath !== null) {
            if ($fastPath === false) {
                // Usuario niega: volver a preguntar el paso actual
                return [
                    'action' => 'ask_user',
                    'reply'  => 'Entendido, ¿qué te gustaría cambiar?',
                    'state_updates' => [],
                ];
            }
            // Usuario confirma: avanzar al siguiente paso sin LLM
            $nextStep = $this->getNextStep($activeTask);
            if ($nextStep === 'completed') {
                return [
                    'action'  => 'execute_command',
                    'command' => ['command' => 'InstallPlaybook', 'sector' => $context['long_term_facts']['sector'] ?? 'GENERIC', 'options' => []],
                    'reply'   => '¡Todo listo! Construyendo tu aplicación...',
                    'state_updates' => ['active_task' => 'completed'],
                    'telemetry' => ['agent' => 'BuilderOnboardingProcess', 'fast_path' => true],
                ];
            }
            return [
                'action' => 'ask_user',
                'reply'  => 'Perfecto. Continuamos. ' . $this->getStepQuestion($nextStep),
                'state_updates' => ['active_task' => $nextStep],
                'telemetry' => ['agent' => 'BuilderOnboardingProcess', 'fast_path' => true],
            ];
        }

        // Llamada REAL a LLMRouter (CrewAI Step)
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

    /**
     * Detecta si el usuario confirmó o negó sin ambigüedad.
     * Retorna: true=confirmación, false=negación, null=no es fast-path.
     */
    private function isFastPathConfirmation(string $text): ?bool
    {
        $normalized = strtolower(trim($text));
        // Remover puntuación
        $normalized = trim(preg_replace('/[^a-záéíóúüñ\s]/u', '', $normalized) ?? $normalized);

        $positives = ['si', 'sí', 'ok', 'listo', 'dale', 'vamos', 'claro', 'correcto', 'exacto', 'adelante', 'procede', 'hazlo'];
        $negatives = ['no', 'nada', 'cancela', 'cancelar', 'otro', 'cambiar', 'cambio', 'diferente'];

        if (in_array($normalized, $positives, true)) {
            return true;
        }
        if (in_array($normalized, $negatives, true)) {
            return false;
        }
        return null; // Requiere análisis LLM
    }

    /**
     * Genera la pregunta guía para el paso del onboarding.
     */
    private function getStepQuestion(string $step): string
    {
        $questions = [
            'business_type'    => '¿Cuál es el tipo de negocio? (ej: ferretería, veterinaria, restaurante)',
            'operation_model'  => '¿Cómo manejas los pagos? (contado, crédito o mixto)',
            'needs_scope'      => '¿Qué necesitas controlar primero? (inventario, clientes, facturación, etc.)',
            'documents'        => '¿Emites facturas o manejas documentos fiscales?',
        ];
        return $questions[$step] ?? '¿Cuál es el siguiente dato de tu negocio?';
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
