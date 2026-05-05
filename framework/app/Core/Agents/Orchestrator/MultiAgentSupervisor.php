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
        $ruleType = $rule['rule_type'] ?? $rule['id'];

        // Legacy named rules (backward compat)
        if ($rule['id'] === 'check_stock_availability') {
            $qty   = (float) ($payload['quantity'] ?? 0);
            $stock = (float) ($payload['available_stock'] ?? 0);
            if ($qty > $stock) {
                return ['valid' => false, 'message' => 'Stock insuficiente detectado por el Supervisor.'];
            }
        }

        if ($rule['id'] === 'check_fiscal_margin') {
            $margin    = (float) ($payload['margin'] ?? 0);
            $minMargin = (float) ($rule['params']['min_margin'] ?? 0.10);
            if ($margin < $minMargin) {
                return ['valid' => false, 'message' => 'Margen inferior al limite permitido (' . ($minMargin * 100) . '%).'];
            }
        }

        // Generic: range_check — validates a numeric field is within [min, max]
        if ($ruleType === 'range_check') {
            $field = (string) ($rule['params']['field'] ?? '');
            $value = isset($payload[$field]) ? (float) $payload[$field] : null;
            if ($value === null) {
                return ['valid' => false, 'message' => "Campo requerido ausente: {$field}."];
            }
            $min = isset($rule['params']['min']) ? (float) $rule['params']['min'] : null;
            $max = isset($rule['params']['max']) ? (float) $rule['params']['max'] : null;
            if ($min !== null && $value < $min) {
                return ['valid' => false, 'message' => "Campo {$field} ({$value}) menor al mínimo ({$min})."];
            }
            if ($max !== null && $value > $max) {
                return ['valid' => false, 'message' => "Campo {$field} ({$value}) supera el máximo ({$max})."];
            }
        }

        // Generic: require_fields — all listed fields must be present and non-empty
        if ($ruleType === 'require_fields') {
            $fields = is_array($rule['params']['fields'] ?? null) ? (array) $rule['params']['fields'] : [];
            foreach ($fields as $f) {
                if (empty($payload[(string) $f])) {
                    return ['valid' => false, 'message' => "Campo obligatorio faltante: {$f}."];
                }
            }
        }

        // Generic: deny_if_role — block if actor role is in the denied list
        if ($ruleType === 'deny_if_role') {
            $actorRole   = (string) ($payload['actor_role'] ?? '');
            $deniedRoles = is_array($rule['params']['denied_roles'] ?? null) ? (array) $rule['params']['denied_roles'] : [];
            if ($actorRole !== '' && in_array($actorRole, $deniedRoles, true)) {
                return ['valid' => false, 'message' => "Rol '{$actorRole}' no tiene permiso para esta operación."];
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
        $path = dirname(__DIR__, 4) . '/data/workflow_registry.json';
        if (!file_exists($path)) {
            $this->workflowRegistry = [];
            return;
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        $this->workflowRegistry = is_array($decoded['workflows'] ?? null) ? (array) $decoded['workflows'] : [];
    }

    private function loadBusinessRules(): void
    {
        $path = dirname(__DIR__, 4) . '/data/business_rules.json';
        if (!file_exists($path)) {
            $this->businessRules = [];
            return;
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        $this->businessRules = is_array($decoded['rules'] ?? null) ? (array) $decoded['rules'] : [];
    }
}
