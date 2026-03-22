<?php
declare(strict_types=1);
// app/Core/Agents/Processes/AppExecutionProcess.php

namespace App\Core\Agents\Processes;

use App\Core\Agents\Memory\MemoryWindow;
use App\Core\Agents\Memory\TokenBudgeter;
use App\Core\IntentRouter;
use App\Core\LLM\LLMRouter;

/**
 * Agente Especialista: Operación Pura de Apps (SUKI Business)
 * Reemplaza el paso directo al IntentRouter, controlando el flujo y 
 * herramientas de una manera estructurada (Tool Execution Loop).
 */
class AppExecutionProcess
{
    public function __construct()
    {
        // Require IntentRouter, CommandBus, ToolCompressor here
    }

    /**
     * @param string $userText Lo que dijo el user
     * @param MemoryWindow $memory 
     * @param IntentRouter|null $router Invoca búsqueda semántica (Qdrant)
     * @return array
     */
    /**
     * @param string      $userText Texto del usuario
     * @param MemoryWindow $memory  Ventana de memoria del turno
     * @param IntentRouter|null $router Clasificador Qdrant/NLU
     * @param LLMRouter|null    $llm    Fallback semántico si el router no resuelve (FIX A4)
     */
    public function execute(
        string $userText,
        MemoryWindow $memory,
        ?IntentRouter $router = null,
        ?LLMRouter $llm = null
    ): array {
        $context = $memory->compileLlmContext(new TokenBudgeter(), 600);

        // --- FIX A1: pasar el mensaje real al IntentRouter ---
        // El router lee 'message_text' del contexto Y del gatewayResult.
        // Poblamos ambos para garantizar compatibilidad con las rutas internas.
        if ($router) {
            $gatewayResult = [
                'intent'       => 'unknown',
                'action'       => 'respond_local',
                'reply'        => '',
                'message_text' => $userText,   // FIX A1: texto real en gatewayResult
            ];
            $route = $router->route($gatewayResult, [
                'message_text' => $userText,
                'request_mode' => 'operation',
            ]);

            if ($route->isCommand()) {
                $cmd = $route->command();
                return [
                    'action'    => 'execute_command',
                    'command'   => $cmd,
                    'reply'     => 'Ejecutando: ' . ($cmd['command'] ?? 'acción'),
                    'telemetry' => $route->telemetry(),
                ];
            }

            if (in_array($route->kind(), ['ask_user', 'respond_local'], true)) {
                return [
                    'action'    => $route->kind(),
                    'reply'     => $route->reply(),
                    'telemetry' => $route->telemetry(),
                ];
            }
        }

        // FIX A4: si el IntentRouter no resolvió, usar LLM como fallback semántico
        if ($llm) {
            try {
                $result = $llm->chat([
                    'policy'       => ['requires_strict_json' => false],
                    'user_message' => $userText,
                ]);
                $reply = trim((string) ($result['text'] ?? ''));
                if ($reply !== '') {
                    return [
                        'action'    => 'respond_local',
                        'reply'     => $reply,
                        'telemetry' => ['agent' => 'AppExecutionProcess', 'llm_fallback' => true],
                    ];
                }
            } catch (\Throwable $e) {
                error_log('[AppExecutionProcess] LLM fallback error: ' . $e->getMessage());
            }
        }

        return [
            'action'    => 'ask_user',
            'reply'     => 'Aún estoy aprendiendo a usar las herramientas de tu ERP. ¿Qué transacción deseas hacer? (Prueba decir "crear factura")',
            'telemetry' => ['agent' => 'AppExecutionProcess', 'fallback' => true],
        ];
    }
}
