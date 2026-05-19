<?php
declare(strict_types=1);
// app/Core/Agents/Processes/BuilderOnboardingProcess.php

namespace App\Core\Agents\Processes;

use App\Core\Agents\Memory\MemoryWindow;
use App\Core\CommandBus;
use App\Core\LLM\LLMRouter;

/**
 * BuilderOnboardingProcess
 *
 * Conduce la entrevista de creación de app en el Builder Chat.
 * 8 pasos core + soporte de pasos dinámicos que el LLM puede agregar en tiempo real.
 *
 * Diseño LLM-driven:
 * - Los pasos y sus goals vienen de builder_interview_steps.json (sin hardcode en PHP)
 * - PHP valida y extrae los datos estructurados (autoridad de validación)
 * - El LLM genera preguntas naturales usando el goal del paso en el system prompt
 * - Pasos dinámicos: LLM llama add_interview_step cuando detecta necesidad no cubierta
 */
class BuilderOnboardingProcess
{
    private ?array $stepsConfig = null;
    private string $contractPath;

    public function __construct()
    {
        $this->contractPath = dirname(__DIR__, 4) . '/contracts/agents/builder_interview_steps.json';
    }

    /**
     * @param string $userText Lo que dijo el user
     * @param MemoryWindow $memory
     * @param CommandBus|null $bus Ejecutor de acciones reales
     * @param LLMRouter|null $llm Motor de IA estructural
     * @return array
     */
    public function execute(
        string $userText,
        MemoryWindow $memory,
        ?CommandBus $bus = null,
        ?LLMRouter $llm = null
    ): array {
        $context    = $memory->compileLlmContext(new \App\Core\Agents\Memory\TokenBudgeter(), 400);
        $activeTask = $context['active_task'] ?? 'business_type';

        $allSteps = $this->getAllStepKeys($context);
        if (!in_array($activeTask, $allSteps, true)) {
            $activeTask = $allSteps[0] ?? 'business_type';
        }

        // Fast-path: confirmaciones simples (sí/no) no necesitan LLM
        $fastPath = $this->isFastPathConfirmation($userText);
        if ($fastPath !== null) {
            if ($fastPath === false) {
                return [
                    'action'        => 'ask_user',
                    'reply'         => 'Entendido, ¿qué te gustaría cambiar?',
                    'state_updates' => [],
                ];
            }
            $nextStep = $this->getNextStep($activeTask, $allSteps);
            if ($nextStep === 'completed') {
                return [
                    'action'        => 'execute_command',
                    'command'       => [
                        'command' => 'InstallPlaybook',
                        'sector'  => $context['long_term_facts']['sector'] ?? 'GENERIC',
                        'options' => [],
                    ],
                    'reply'         => '¡Todo listo! Construyendo tu aplicación...',
                    'state_updates' => ['active_task' => 'completed'],
                    'telemetry'     => ['agent' => 'BuilderOnboardingProcess', 'fast_path' => true],
                ];
            }
            return [
                'action'        => 'ask_user',
                'reply'         => 'Perfecto. Continuamos. ' . $this->getStepQuestion($nextStep),
                'state_updates' => ['active_task' => $nextStep],
                'telemetry'     => ['agent' => 'BuilderOnboardingProcess', 'fast_path' => true],
            ];
        }

        // Llamada REAL al LLM para extraer datos estructurados del paso
        $llmOutput = [];
        if ($llm) {
            $capsule = [
                'policy' => [
                    'requires_strict_json'   => true,
                    'system_prompt_override' => $this->getSystemPrompt($activeTask, $context['long_term_facts'] ?? []),
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

        if (!isset($llmOutput['intent'], $llmOutput['reply'])) {
            return [
                'action'        => 'ask_user',
                'reply'         => 'No pude procesar tu solicitud. ¿Podrías dar más detalles sobre tu negocio?',
                'state_updates' => [],
            ];
        }

        if ($llmOutput['needs_clarification'] ?? false) {
            return [
                'action'        => 'ask_user',
                'reply'         => $llmOutput['reply'],
                'state_updates' => [],
            ];
        }

        // Si el LLM detectó una necesidad adicional → agregar paso dinámico
        if (!empty($llmOutput['add_step']['key']) && !empty($llmOutput['add_step']['question'])) {
            $this->addDynamicStepToContext(
                $context,
                (string) $llmOutput['add_step']['key'],
                (string) $llmOutput['add_step']['question']
            );
            $allSteps = $this->getAllStepKeys($context);
        }

        $nextStep  = $this->getNextStep($activeTask, $allSteps);
        $collected = $llmOutput['mapped_fields'] ?? [];

        if ($nextStep === 'completed') {
            // Consolidate all long_term_facts gathered throughout the interview
            $allFacts = array_merge($context['long_term_facts'] ?? [], $collected);

            return [
                'action'        => 'execute_command',
                'command'       => [
                    'command'         => 'InstallPlaybook',
                    'sector'          => $collected['sector'] ?? $context['long_term_facts']['sector'] ?? 'GENERIC',
                    'options'         => $collected,
                    // Pre-populate app_tenant_config with data gathered during Builder interview.
                    // Keys must match field_definitions in app_catalog.json.
                    'initial_config'  => $this->extractInitialAppConfig($allFacts),
                ],
                'reply'         => '¡Todo listo! Estoy construyendo tu aplicación de '
                                 . ($collected['business_type'] ?? $context['long_term_facts']['business_type'] ?? 'negocio')
                                 . '. En unos segundos verás las tablas en el panel.',
                'state_updates' => ['active_task' => 'completed'],
                'telemetry'     => ['agent' => 'BuilderOnboardingProcess', 'onboarding_complete' => true],
            ];
        }

        return [
            'action'        => 'save_step',
            'reply'         => $llmOutput['reply'],
            'state_updates' => ['active_task' => $nextStep, 'collected' => $collected],
            'telemetry'     => ['agent' => 'BuilderOnboardingProcess', 'llm_called' => true],
        ];
    }

    /**
     * Returns the goal for a given step — used when injecting context into system prompt.
     */
    public function getStepGoal(string $stepKey): string
    {
        $config = $this->loadStepsConfig();
        foreach ($config['core_steps'] ?? [] as $step) {
            if (($step['key'] ?? '') === $stepKey) {
                return (string) ($step['goal'] ?? '');
            }
        }
        return '';
    }

    /**
     * Returns all step keys (8 core + dynamic steps from context state).
     */
    public function getAllStepKeys(array $context = []): array
    {
        $config       = $this->loadStepsConfig();
        $coreKeys     = array_map(fn($s) => $s['key'], $config['core_steps'] ?? []);
        $dynamicSteps = $context['long_term_facts']['dynamic_steps'] ?? [];
        $dynamicKeys  = is_array($dynamicSteps) ? array_column($dynamicSteps, 'key') : [];
        return array_values(array_unique(array_merge($coreKeys, $dynamicKeys)));
    }

    /**
     * Register a new dynamic step detected by the LLM at runtime.
     */
    public function addDynamicStepToContext(array &$context, string $stepKey, string $stepQuestion): void
    {
        $dynamic = $context['long_term_facts']['dynamic_steps'] ?? [];
        if (!is_array($dynamic)) {
            $dynamic = [];
        }
        if (!in_array($stepKey, array_column($dynamic, 'key'), true)) {
            $dynamic[] = ['key' => $stepKey, 'question' => $stepQuestion, 'added_at' => date('c')];
            $context['long_term_facts']['dynamic_steps'] = $dynamic;
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function getNextStep(string $current, array $allSteps): string
    {
        $idx = array_search($current, $allSteps, true);
        if ($idx !== false && isset($allSteps[$idx + 1])) {
            return $allSteps[$idx + 1];
        }
        return 'completed';
    }

    /**
     * Question text for a step — reads from JSON contract (no hardcode in PHP).
     * Only used in fast-path confirmations where LLM is bypassed.
     */
    private function getStepQuestion(string $step): string
    {
        $config = $this->loadStepsConfig();
        foreach ($config['core_steps'] ?? [] as $s) {
            if (($s['key'] ?? '') === $step) {
                return (string) ($s['question'] ?? '¿Cuál es el siguiente dato de tu negocio?');
            }
        }
        return '¿Puedes contarme más sobre ese aspecto de tu negocio?';
    }

    private function getSystemPrompt(string $step, array $facts): string
    {
        $config     = $this->loadStepsConfig();
        $stepConfig = null;
        foreach ($config['core_steps'] ?? [] as $s) {
            if (($s['key'] ?? '') === $step) {
                $stepConfig = $s;
                break;
            }
        }

        $goal           = $stepConfig['goal'] ?? "Capturar información del paso {$step}";
        $extractionHint = $stepConfig['extraction_hint'] ?? '';
        $dynamicHint    = $config['dynamic_steps_instructions'] ?? '';
        $factsJson      = json_encode($facts, JSON_UNESCAPED_UNICODE);

        return "Eres un Arquitecto de Software experto en onboarding de negocios para SUKI AI-AOS.
Paso actual: [{$step}]
Objetivo del paso: {$goal}
Datos ya recopilados: {$factsJson}
Guía de extracción: {$extractionHint}

{$dynamicHint}

INSTRUCCIONES:
1. Extrae la información del paso actual en 'mapped_fields'.
2. Si la info es ambigua, pon 'needs_clarification': true y pregunta en 'reply'.
3. Si detectas necesidad de negocio no cubierta por los pasos actuales, ponla en 'add_step'.
4. El campo 'reply' debe ser conversacional y empático, no robótico.

RESPONDE ÚNICAMENTE CON JSON VÁLIDO:
{
  \"intent\": \"onboarding_step_resolved\",
  \"reply\": \"Mensaje empático de confirmación o pregunta\",
  \"mapped_fields\": {},
  \"needs_clarification\": false,
  \"add_step\": null
}";
    }

    private function isFastPathConfirmation(string $text): ?bool
    {
        $normalized = strtolower(trim($text));
        $normalized = trim(preg_replace('/[^a-záéíóúüñ\s]/u', '', $normalized) ?? $normalized);
        $positives  = ['si', 'sí', 'ok', 'listo', 'dale', 'vamos', 'claro', 'correcto', 'exacto', 'adelante', 'procede', 'hazlo'];
        $negatives  = ['no', 'nada', 'cancela', 'cancelar', 'otro', 'cambiar', 'cambio', 'diferente'];
        if (in_array($normalized, $positives, true)) return true;
        if (in_array($normalized, $negatives, true)) return false;
        return null;
    }

    /**
     * Extracts app config fields from gathered interview facts so app_tenant_config
     * can be pre-populated after the Builder interview completes.
     * This avoids asking the user again in App Chat for data already provided.
     */
    private function extractInitialAppConfig(array $facts): array
    {
        $config = [];

        // Map Builder interview fields → app_catalog field_definitions keys
        $mappings = [
            'business_name'  => 'razon_social',
            'razon_social'   => 'razon_social',
            'nit'            => 'nit',
            'municipio'      => 'municipio',
            'ciudad'         => 'municipio',
            'telefono'       => 'telefono',
            'regimen'        => 'regimen_tributario',
            'regimen_tributario' => 'regimen_tributario',
            'actividad_economica' => 'actividad_economica',
            'actividad'      => 'actividad_economica',
        ];

        // Also extract from nested fiscal config
        $fiscal = is_array($facts['fiscal'] ?? null) ? $facts['fiscal'] : [];
        if (!empty($fiscal['regimen'])) {
            $config['regimen_tributario'] = (string) $fiscal['regimen'];
        }
        if (!empty($fiscal['nit'])) {
            $config['nit'] = (string) $fiscal['nit'];
        }

        foreach ($mappings as $factKey => $configKey) {
            if (!empty($facts[$factKey]) && !isset($config[$configKey])) {
                $config[$configKey] = (string) $facts[$factKey];
            }
        }

        return array_filter($config, static fn($v) => $v !== '' && $v !== null);
    }

    private function loadStepsConfig(): array
    {
        if ($this->stepsConfig !== null) {
            return $this->stepsConfig;
        }
        if (file_exists($this->contractPath)) {
            $raw     = file_get_contents($this->contractPath);
            $decoded = $raw !== false ? json_decode($raw, true) : null;
            if (is_array($decoded)) {
                $this->stepsConfig = $decoded;
                return $this->stepsConfig;
            }
        }
        // Emergency fallback — 8 pasos sin strings hardcodeadas (solo keys y goals mínimos)
        $this->stepsConfig = [
            'core_steps' => [
                ['key' => 'business_type',     'goal' => 'Sector y actividad del negocio',    'question' => '¿A qué se dedica el negocio?',                         'extraction_hint' => 'Extraer sector y tipo de negocio.'],
                ['key' => 'operation_model',   'goal' => 'Modelo de pago del negocio',        'question' => '¿Pagos de contado, crédito o mixto?',                  'extraction_hint' => 'contado/credito/mixto'],
                ['key' => 'needs_scope',       'goal' => 'Módulos prioritarios',              'question' => '¿Qué quieres controlar primero?',                      'extraction_hint' => 'Lista de módulos.'],
                ['key' => 'documents',         'goal' => 'Tipos de documentos comerciales',   'question' => '¿Qué documentos usas?',                                'extraction_hint' => 'factura, cotización, remisión, etc.'],
                ['key' => 'user_roles',        'goal' => 'Roles de usuario y permisos',       'question' => '¿Quiénes usarán el sistema y qué puede hacer cada uno?','extraction_hint' => 'Array de roles con permisos.'],
                ['key' => 'fiscal_config',     'goal' => 'Configuración tributaria',          'question' => '¿Cobras IVA? ¿Cuál es tu régimen tributario?',          'extraction_hint' => 'regimen, iva_rate, retefuente, ica.'],
                ['key' => 'formulas_and_rules','goal' => 'Reglas de negocio especiales',      'question' => '¿Tienes reglas especiales de precio o comisiones?',     'extraction_hint' => 'Array de reglas {name, type, condition, action}.'],
                ['key' => 'integrations',      'goal' => 'Integraciones externas necesarias', 'question' => '¿Necesitas factura DIAN, tienda virtual o pasarela?',   'extraction_hint' => 'Array: dian, woocommerce, wompi, etc.'],
            ],
        ];
        return $this->stepsConfig;
    }
}
