<?php
declare(strict_types=1);
// app/Core/Agents/Orchestrator/ToolExecutionLoop.php

namespace App\Core\Agents\Orchestrator;

use App\Core\Agents\Memory\MemoryWindow;
use Exception;

/**
 * ToolExecutionLoop
 * AutoGen Self-Healing Pattern: Si la invocación a una DB o Tool 
 * falla (ej: LLM mandó un String en vez de un Integer), NO le mostramos
 * el error de MySQL al usuario. En su lugar, el sistema atrapa la excepción
 * y vuelve a pedirle al LLM que corrija su propio JSON pasándole el mensaje
 * de error técnico en un turno invisible para el humano.
 */
class ToolExecutionLoop
{
    private int $maxAttempts;

    public function __construct(int $maxAttempts = 2)
    {
        $this->maxAttempts = max(1, $maxAttempts);
    }

    /**
     * Ejecuta una herramienta "riesgosa" encapsulando el LLM Action.
     * 
     * @param callable $toolExecutionCallback Función anónima de PHP que ejecuta la lógica dura
     * @param callable $llmRecoveryCallback Función que invoca al LLM enviándole el mensaje de error para reintento
     * @param MemoryWindow $memory
     * @return array
     */
    public function executeWithHealing(
        callable $toolExecutionCallback,
        callable $llmRecoveryCallback,
        array $lastLlmOutput,
        MemoryWindow $memory
    ): array {
        $attempts = 0;
        $currentLlmOutput = $lastLlmOutput;

        while ($attempts < $this->maxAttempts) {
            $attempts++;
            try {
                // Intenta ejecutar la acción (ej: insertar en DB, llamar API)
                return $toolExecutionCallback($currentLlmOutput);

            } catch (Exception $e) {
                // Ocurrió un error técnico (Validación, Tipo de Dato, Constraint de DB)
                if ($attempts >= $this->maxAttempts) {
                    // Si ya acabamos los reintentos, abortar graceful
                    return [
                        'action' => 'ask_user',
                        'reply' => 'Tuve un inconveniente técnico al guardar esa información. ¿Podemos revisar los datos e intentar de nuevo?',
                        'telemetry' => ['self_healing' => 'failed', 'error' => $e->getMessage()]
                    ];
                }

                // Append invisible al short-term memory para que el LLM vea su error
                $errorMessage = "SYSTEM FAILURE: Tool execution failed because: " . $e->getMessage() . ". Please correct your JSON parameters and try again.";
                $memory->appendShortTerm('system', $errorMessage);

                // Llamar al LLM de nuevo para que se "autocure"
                $currentLlmOutput = $llmRecoveryCallback($memory);
            }
        }

        // Falla de seguridad (nunca debería llegar acá sin retornar antes)
        return [
            'action' => 'ask_user',
            'reply' => 'No pudimos procesar tu solicitud esta vez.',
            'telemetry' => ['self_healing' => 'aborted']
        ];
    }
}
