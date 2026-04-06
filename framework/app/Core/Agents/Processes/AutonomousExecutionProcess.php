<?php
declare(strict_types=1);
// framework/app/Core/Agents/Processes/AutonomousExecutionProcess.php

namespace App\Core\Agents\Processes;

use App\Core\Agents\Memory\MemoryWindow;
use App\Core\Agents\Memory\TokenBudgeter;
use App\Core\Agents\Memory\PersistentMemoryLoader;
use App\Core\IntentRouter;
use App\Core\LLM\LLMRouter;
use App\Core\LLM\Adapters\ClaudeToolAdapter;
use App\Core\SkillRegistry;
use RuntimeException;

/**
 * AutonomousExecutionProcess
 * 
 * Implementa un ciclo de ejecución "Tool-First" (estilo Claude Code).
 * A diferencia del AppExecutionProcess estándar, este agente tiene autonomía
 * total para decidir qué herramientas llamar y cómo encadenarlas.
 */
class AutonomousExecutionProcess
{
    private ClaudeToolAdapter $toolAdapter;
    private TokenBudgeter $budgeter;
    private int $maxIterations = 5;

    public function __construct()
    {
        $this->toolAdapter = new ClaudeToolAdapter();
        $this->budgeter = new TokenBudgeter();
    }

    /**
     * @param string $userText
     * @param MemoryWindow $memory
     * @param IntentRouter $router
     * @param LLMRouter $llm
     * @param SkillRegistry $skillRegistry
     * @return array
     */
    public function execute(
        string $userText,
        MemoryWindow $memory,
        IntentRouter $router,
        LLMRouter $llm,
        SkillRegistry $skillRegistry
    ): array {
        $tools = $this->toolAdapter->map($skillRegistry);
        
        // Cargar memoria persistente autónoma
        $persistentLoader = new PersistentMemoryLoader();
        $persistentContext = $persistentLoader->loadAll();

        // Inicializamos historial para este ciclo autónomo
        $history = [];
        if (!empty($persistentContext)) {
            $history[] = ['role' => 'system', 'content' => $persistentContext];
        }

        // Fusionar con historial de ventana (Short Term)
        foreach ($memory->getShortTermHistory() as $msg) {
            $history[] = [
                'role' => $msg['role'] ?? 'user',
                'content' => $msg['text'] ?? ($msg['content'] ?? '')
            ];
        }

        $history[] = ['role' => 'user', 'content' => $userText];

        $iteration = 0;
        $allToolCalls = [];
        $totalInputTokens = 0;
        $totalOutputTokens = 0;

        while ($iteration < $this->maxIterations) {
            $iteration++;

            // Estimar tokens de entrada (Prompt)
            $totalInputTokens += $this->budgeter->estimate(json_encode($history));

            $response = $llm->complete($history, ['tools' => $tools]);

            $text = $response['text'] ?? '';
            $toolCalls = $response['tool_calls'] ?? [];

            // Estimar tokens de salida (Completion)
            $totalOutputTokens += $this->budgeter->estimate($text . json_encode($toolCalls));

            if ($text) {
                // Si hay texto, lo añadimos al historial para coherencia
                $history[] = ['role' => 'assistant', 'content' => $text];
            }

            if (empty($toolCalls)) {
                // No hay más herramientas que llamar, terminamos
                return [
                    'action' => 'respond_local',
                    'reply' => $text ?: 'Proceso completado.',
                    'telemetry' => [
                        'agent' => 'AutonomousExecutionProcess',
                        'iterations' => $iteration,
                        'tools_called' => $allToolCalls,
                        'estimated_tokens' => [
                            'input' => $totalInputTokens,
                            'output' => $totalOutputTokens,
                            'total' => $totalInputTokens + $totalOutputTokens
                        ]
                    ]
                ];
            }

            // Procesar llamadas a herramientas
            foreach ($toolCalls as $call) {
                $toolName = $call['name'] ?? ($call['function']['name'] ?? 'unknown');
                $toolInput = $call['input'] ?? (isset($call['function']['arguments']) ? json_decode($call['function']['arguments'], true) : []);
                $callId = $call['id'] ?? uniqid('call_');

                $allToolCalls[] = $toolName;

                try {
                    // Ejecutamos la skill a través del router
                    $result = $router->executeSkill($toolName, $toolInput);
                    
                    // Añadimos el resultado al historial como 'tool' result (formato Anthropic)
                    // Nota: El ClaudeProvider actual espera que nosotros manejemos el formato de mensajes.
                    // Anthropic requiere un mensaje de asistente con el tool_use seguido de un mensaje de usuario con el tool_result.
                    
                    // 1. Añadimos el assistant message con el tool_use si no estaba ya (aunque ClaudeProvider lo devuelve plano por ahora)
                    // Por simplicidad en este prototipo, simulamos el feedback loop:
                    $history[] = [
                        'role' => 'user', 
                        'content' => "RESULTADO HERRAMIENTA ($toolName): " . json_encode($result, JSON_UNESCAPED_UNICODE)
                    ];

                } catch (\Throwable $e) {
                    $history[] = [
                        'role' => 'user',
                        'content' => "ERROR EJECUTANDO HERRAMIENTA ($toolName): " . $e->getMessage()
                    ];
                }
            }
        }

        return [
            'action' => 'respond_local',
            'reply' => ($text ?: 'Lo siento, el proceso autónomo alcanzó el límite de iteraciones sin una respuesta final.'),
            'telemetry' => [
                'agent' => 'AutonomousExecutionProcess',
                'iterations' => $iteration,
                'status' => 'max_iterations_reached'
            ]
        ];
    }
}
