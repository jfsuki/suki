<?php

namespace App\Core\Agents\Orchestrator;

use App\Core\ProjectRegistry;
use Exception;

/**
 * MultiAgentSupervisor
 *
 * Actúa como el Agente Líder determinista.
 * Valida que las respuestas de los agentes especialistas no violen contratos ni reglas de negocio.
 */
class MultiAgentSupervisor
{
    private ProjectRegistry $registry;
    private array $businessRules = [];
    private array $workflowRegistry = [];

    public function __construct(ProjectRegistry $registry)
    {
        $this->registry = $registry;
        $this->loadBusinessRules();
        $this->loadWorkflowRegistry();
    }

    /**
     * Valida una propuesta de acción de un agente.
     * Si la validación determinista falla, se marca para revisión por IA o Humano.
     */
    public function validateAction(array $event): array
    {
        $type = $event['type'] ?? 'UNKNOWN';
        $payload = $event['payload'] ?? [];
        $source = $event['source_agent_id'] ?? 'SYSTEM';

        // 1. Validación de Esquema (Simulada por ahora)
        if (empty($type) || empty($payload)) {
            return $this->rejection("Evento inválido: Faltan datos críticos.");
        }

        // 2. Validación de Reglas de Negocio Deterministas
        foreach ($this->businessRules as $rule) {
            if ($rule['event_type'] === $type) {
                $result = $this->evaluateRule($rule, $payload);
                if (!$result['valid']) {
                    return $this->rejection("Regla violada: " . $result['message'], true);
                }
            }
        }

        return [
            'status' => 'APPROVED',
            'supervisor_trace' => 'Validated via deterministic rules. No AI fallback needed.',
            'event' => $event
        ];
    }

    private function evaluateRule(array $rule, array $payload): array
    {
        // Ejemplo: Regla de Facturación - No vender sin stock
        if ($rule['id'] === 'check_stock_availability') {
            $qty = $payload['quantity'] ?? 0;
            $stock = $payload['available_stock'] ?? 0;
            if ($qty > $stock) {
                return ['valid' => false, 'message' => "Stock insuficiente detectado por el Supervisor."];
            }
        }

        // Ejemplo: Regla de Margen Fiscal
        if ($rule['id'] === 'check_fiscal_margin') {
            $margin = $payload['margin'] ?? 0;
            $minMargin = $rule['params']['min_margin'] ?? 0.10;
            if ($margin < $minMargin) {
                return ['valid' => false, 'message' => "Margen inferior al limite permitido (" . ($minMargin * 100) . "%)."];
            }
        }

        return ['valid' => true];
    }

    private function rejection(string $message, bool $needsAiConflictResolution = false): array
    {
        return [
            'status' => 'REJECTED',
            'message' => $message,
            'needs_ai_resolution' => $needsAiConflictResolution
        ];
    }

    /**
     * Coordina un flujo de trabajo entre múltiples agentes basado en la intención detectada.
     */
    public function coordinateWorkflow(string $intent, array $args): ?array
    {
        $intentKey = strtoupper($intent);
        if (!isset($this->workflowRegistry[$intentKey])) {
            return null; // No hay flujo predefinido para esta intención
        }

        $workflow = $this->workflowRegistry[$intentKey];
        return [
            'workflow_id' => 'wf_' . bin2hex(random_bytes(4)),
            'sequence' => $workflow['sequence'],
            'description' => $workflow['description'],
            'initial_args' => $args
        ];
    }

    private function loadWorkflowRegistry(): void
    {
        $this->workflowRegistry = [
            'PURCHASE' => [
                'sequence' => ['SALES', 'FINANCES'],
                'description' => 'Validación de Stock -> Validación de Margen Fiscal'
            ],
            'QUOTATION' => [
                'sequence' => ['SALES', 'FINANCES'],
                'description' => 'Disponibilidad de Catálogo -> Optimización de Precios'
            ],
            'SCHEMA_UPDATE' => [
                'sequence' => ['ARCHITECT', 'FINANCES'],
                'description' => 'Diseño de Tabla -> Validación de Impacto Contable'
            ]
        ];
    }

    private function loadBusinessRules(): void
    {
        // En una fase posterior, esto vendrá de un JSON o de la DB por Tenant.
        $this->businessRules = [
            [
                'id' => 'check_stock_availability',
                'event_type' => 'STOCK_RESERVED',
                'params' => []
            ],
            [
                'id' => 'check_fiscal_margin',
                'event_type' => 'ENTITY_CREATED',
                'params' => ['min_margin' => 0.25] // Ejemplo solicitado: 25%
            ]
        ];
    }
}
