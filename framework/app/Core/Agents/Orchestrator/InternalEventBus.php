<?php

namespace App\Core\Agents\Orchestrator;

use App\Core\ProjectRegistry;
use DateTime;

/**
 * InternalEventBus
 *
 * El sistema nervioso del Multi-Agent ERP OS.
 * Permite la comunicación asíncrona (simulada) y síncrona entre especialistas.
 */
class InternalEventBus
{
    private ProjectRegistry $registry;
    private array $listeners = [];
    private MultiAgentSupervisor $supervisor;

    public function __construct(ProjectRegistry $registry, MultiAgentSupervisor $supervisor)
    {
        $this->registry = $registry;
        $this->supervisor = $supervisor;
    }

    /**
     * Emite un evento al bus.
     * Pasa primero por el Supervisor antes de ser distribuido.
     */
    public function emit(array $eventData): array
    {
        // 1. Enriquecer evento
        $eventData['event_id'] = bin2hex(random_bytes(8));
        $eventData['timestamp'] = (new DateTime())->format(DateTime::ATOM);

        // 2. Supervisión Determinista Obligatoria
        $validation = $this->supervisor->validateAction($eventData);
        
        if ($validation['status'] === 'REJECTED') {
            $this->logActivity('SUPERVISOR_BLOCK', $eventData, $validation['message']);
            return $validation;
        }

        // 3. Persistir Log de Actividad (para Mission Control)
        $this->logActivity($eventData['type'], $eventData);

        // 4. Distribuir a Listeners
        $this->dispatch($eventData);

        return [
            'status' => 'DISPATCHED',
            'event_id' => $eventData['event_id'],
            'supervisor_trace' => $validation['supervisor_trace'] ?? null
        ];
    }

    private function dispatch(array $event): void
    {
        $type = $event['type'];
        if (isset($this->listeners[$type])) {
            foreach ($this->listeners[$type] as $callback) {
                call_user_func($callback, $event);
            }
        }
    }

    public function subscribe(string $eventType, callable $callback): void
    {
        $this->listeners[$eventType][] = $callback;
    }

    private function logActivity(string $type, array $event, ?string $error = null): void
    {
        // Por ahora lo guardamos en un log JSONL persistente por Tenant
        $tenantId = $event['trace']['tenant_id'] ?? 'global';
        $logPath = dirname(__DIR__, 5) . "/storage/logs/agents/{$tenantId}_activity.log.jsonl";
        
        if (!is_dir(dirname($logPath))) {
            mkdir(dirname($logPath), 0775, true);
        }

        $entry = json_encode([
            'timestamp' => $event['timestamp'],
            'event_id' => $event['event_id'] ?? null,
            'type' => $type,
            'source' => $event['source_agent_id'] ?? 'SYSTEM',
            'payload_preview' => array_keys($event['payload'] ?? []),
            'status' => $error ? 'ERROR' : 'SUCCESS',
            'message' => $error ?: 'OK'
        ]);

        file_put_contents($logPath, $entry . PHP_EOL, FILE_APPEND);
    }
}
