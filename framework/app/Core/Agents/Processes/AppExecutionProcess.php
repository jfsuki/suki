<?php
declare(strict_types=1);
// app/Core/Agents/Processes/AppExecutionProcess.php

namespace App\Core\Agents\Processes;

use App\Core\Agents\Memory\MemoryWindow;
use App\Core\IntentRouter;

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
    public function execute(string $userText, MemoryWindow $memory, ?IntentRouter $router = null): array
    {
        // Esto enruta hacia las funciones de negocio de SUKI
        // utilizando la misma filosofía estricta.
        
        $context = $memory->compileLlmContext(new \App\Core\Agents\Memory\TokenBudgeter(), 600);
        
        // --- CONEXIÓN REAL QDRANT/NLU ---
        if ($router) {
            // Intent Router espera un array tipo "gatewayResult"
            $pseudoResult = ['intent' => 'unknown', 'action' => 'respond_local', 'reply' => ''];
            $route = $router->route($pseudoResult, [
                'message_text' => $userText,
                'request_mode' => 'operation'
            ]);

            if ($route->isCommand()) {
                $cmd = $route->command();
                return [
                    'action' => 'execute_command',
                    'command' => $cmd,
                    'reply' => 'Ejecutando: ' . ($cmd['command'] ?? 'acción'),
                    'telemetry' => $route->telemetry()
                ];
            }

            if ($route->kind() === 'ask_user' || $route->kind() === 'respond_local') {
                return [
                    'action' => $route->kind(),
                    'reply' => $route->reply(),
                    'telemetry' => $route->telemetry()
                ];
            }
        }

        return [
            'action' => 'ask_user',
            'reply' => 'Aún estoy aprendiendo a usar las herramientas de tu ERP. ¿Qué transacción deseas hacer? (Prueba decir "crear factura")',
            'telemetry' => ['agent' => 'AppExecutionProcess', 'fallback' => true]
        ];
    }
}
