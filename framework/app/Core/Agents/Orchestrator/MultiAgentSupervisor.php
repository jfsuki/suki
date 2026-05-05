<?php

namespace App\Core\Agents\Orchestrator;

use App\Core\Agents\BusinessRulesRepository;
use App\Core\ProjectRegistry;

/**
 * MultiAgentSupervisor
 *
 * Agente líder determinista.
 * Valida que las acciones de los agentes especialistas no violen reglas de negocio.
 *
 * Reglas multi-scope cargadas desde MySQL (BusinessRulesRepository):
 *   universal → aplica a todos los tenants/apps  (creadas desde Torre)
 *   app       → aplica a todas las empresas de una app  (creadas desde Builder)
 *   tenant    → aplica solo a esa empresa  (creadas desde chat/training del tenant)
 *
 * Uso:
 *   $supervisor = new MultiAgentSupervisor($registry);
 *   $supervisor->forContext($tenantId, $appId);
 *   $result = $supervisor->validateAction($event);
 *
 * Para escribir reglas:
 *   $supervisor->saveRuleUniversal($rule);         // Torre
 *   $supervisor->saveRuleForApp($appId, $rule);    // Builder
 *   $supervisor->saveRuleForTenant($tenantId, $rule); // Chat del tenant
 */
class MultiAgentSupervisor
{
    private ProjectRegistry $registry;
    private BusinessRulesRepository $rulesRepo;
    private array $businessRules = [];
    private array $workflowRegistry = [];
    private string $tenantId = '';
    private string $appId = '';
    private bool $rulesLoaded = false;

    public function __construct(ProjectRegistry $registry, ?BusinessRulesRepository $rulesRepo = null)
    {
        $this->registry  = $registry;
        $this->rulesRepo = $rulesRepo ?? new BusinessRulesRepository();
        $this->loadWorkflowRegistry();
    }

    /**
     * Establece el contexto tenant/app para filtrar reglas por scope.
     * Llamar antes de validateAction() cuando el contexto es conocido.
     */
    public function forContext(string $tenantId, string $appId = ''): self
    {
        if ($tenantId !== $this->tenantId || $appId !== $this->appId) {
            $this->tenantId   = $tenantId;
            $this->appId      = $appId;
            $this->rulesLoaded = false; // Forzar recarga para el nuevo contexto
        }
        return $this;
    }

    // ─── Validación ─────────────────────────────────────────────────────────────

    /**
     * Valida una propuesta de acción de un agente.
     * Si la validación determinista falla, se marca para revisión.
     */
    public function validateAction(array $event): array
    {
        $this->ensureRulesLoaded();

        $type    = $event['type'] ?? 'UNKNOWN';
        $payload = $event['payload'] ?? [];

        if (empty($type) || empty($payload)) {
            return $this->rejection('Evento inválido: faltan datos críticos.');
        }

        foreach ($this->businessRules as $rule) {
            if ($rule['event_type'] === $type) {
                $result = $this->evaluateRule($rule, $payload);
                if (!$result['valid']) {
                    return $this->rejection('Regla violada: ' . $result['message'], true);
                }
            }
        }

        return [
            'status'           => 'APPROVED',
            'supervisor_trace' => 'Validated via deterministic rules. No AI fallback needed.',
            'event'            => $event,
        ];
    }

    // ─── Escritura de reglas por origen ─────────────────────────────────────────

    /**
     * Torre → regla universal (aplica a todos los tenants y apps).
     */
    public function saveRuleUniversal(array $rule): bool
    {
        $ok = $this->rulesRepo->saveUniversal($rule);
        if ($ok) {
            $this->rulesLoaded = false;
        }
        return $ok;
    }

    /**
     * Builder → regla de app (aplica a todas las empresas que usen esa app).
     */
    public function saveRuleForApp(string $appId, array $rule): bool
    {
        $ok = $this->rulesRepo->saveForApp($appId, $rule);
        if ($ok) {
            $this->rulesLoaded = false;
        }
        return $ok;
    }

    /**
     * Chat/training del tenant → regla de empresa (solo esa empresa).
     */
    public function saveRuleForTenant(string $tenantId, array $rule): bool
    {
        $ok = $this->rulesRepo->saveForTenant($tenantId, $rule);
        if ($ok) {
            $this->rulesLoaded = false;
        }
        return $ok;
    }

    /**
     * Desactiva una regla sin borrarla.
     */
    public function disableRule(string $ruleId, string $scope, string $tenantId = '', string $appId = ''): bool
    {
        $ok = $this->rulesRepo->disable($ruleId, $scope, $tenantId, $appId);
        if ($ok) {
            $this->rulesLoaded = false;
        }
        return $ok;
    }

    // ─── Workflow ────────────────────────────────────────────────────────────────

    /**
     * Coordina un flujo de trabajo entre múltiples agentes basado en la intención detectada.
     */
    public function coordinateWorkflow(string $intent, array $args): ?array
    {
        $intentKey = strtoupper($intent);
        if (!isset($this->workflowRegistry[$intentKey])) {
            return null;
        }

        $workflow = $this->workflowRegistry[$intentKey];
        return [
            'workflow_id'  => 'wf_' . bin2hex(random_bytes(4)),
            'sequence'     => $workflow['sequence'],
            'description'  => $workflow['description'],
            'initial_args' => $args,
        ];
    }

    // ─── Privado ────────────────────────────────────────────────────────────────

    private function ensureRulesLoaded(): void
    {
        if (!$this->rulesLoaded) {
            $this->businessRules = $this->rulesRepo->loadForContext($this->tenantId, $this->appId);
            $this->rulesLoaded   = true;
        }
    }

    private function evaluateRule(array $rule, array $payload): array
    {
        $ruleType = $rule['rule_type'] ?? $rule['id'];

        // Legacy named rules (backward compat con seeds pre-refactor)
        if ($rule['id'] === 'check_stock_availability' || $ruleType === 'check_stock_availability') {
            $qty   = (float) ($payload['quantity'] ?? 0);
            $stock = (float) ($payload['available_stock'] ?? 0);
            if ($qty > $stock) {
                return ['valid' => false, 'message' => 'Stock insuficiente detectado por el Supervisor.'];
            }
        }

        if ($rule['id'] === 'check_fiscal_margin' || $ruleType === 'check_fiscal_margin') {
            $margin    = (float) ($payload['margin'] ?? 0);
            $minMargin = (float) ($rule['params']['min_margin'] ?? 0.10);
            if ($margin < $minMargin) {
                return ['valid' => false, 'message' => 'Margen inferior al límite permitido (' . ($minMargin * 100) . '%).'];
            }
        }

        // Generic: range_check — campo numérico dentro de [min, max]
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

        // Generic: require_fields — todos los campos listados deben estar presentes y no vacíos
        if ($ruleType === 'require_fields') {
            $fields = is_array($rule['params']['fields'] ?? null) ? (array) $rule['params']['fields'] : [];
            foreach ($fields as $f) {
                if (empty($payload[(string) $f])) {
                    return ['valid' => false, 'message' => "Campo obligatorio faltante: {$f}."];
                }
            }
        }

        // Generic: deny_if_role — bloquea si el rol del actor está en la lista prohibida
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
            'status'              => 'REJECTED',
            'message'             => $message,
            'needs_ai_resolution' => $needsAiConflictResolution,
        ];
    }

    private function loadWorkflowRegistry(): void
    {
        $path    = dirname(__DIR__, 4) . '/data/workflow_registry.json';
        $decoded = file_exists($path) ? json_decode((string) file_get_contents($path), true) : null;
        $this->workflowRegistry = is_array($decoded['workflows'] ?? null) ? (array) $decoded['workflows'] : [];
    }
}
