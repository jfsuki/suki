<?php
// framework/app/Core/InternalEventBus.php

declare(strict_types=1);

namespace App\Core;

/**
 * Bus de eventos internos para la coordinacion de agentes ERP.
 * Permite que una accion detonada por un agente sea suscrita por otros dominios.
 */
final class InternalEventBus
{
    private static ?InternalEventBus $instance = null;
    private array $listeners = [];
    private array $log = [];

    private function __construct() {}

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Suscribe un callback a un evento.
     */
    public function subscribe(string $event, callable $callback): void
    {
        $this->listeners[$event][] = $callback;
    }

    /**
     * Dispara un evento con datos. Sincrono para asegurar consistencia ERP.
     */
    public function dispatch(string $event, array $payload): void
    {
        $payload['event_id'] = bin2hex(random_bytes(8));
        $payload['timestamp'] = date('c');

        $this->log[] = [
            'event' => $event,
            'payload' => $payload,
            'status' => 'DISPATCHED'
        ];

        if (isset($this->listeners[$event])) {
            foreach ($this->listeners[$event] as $callback) {
                try {
                    $callback($payload);
                } catch (\Throwable $e) {
                    $this->log[] = [
                        'event' => $event,
                        'payload' => $payload,
                        'status' => 'ERROR',
                        'error' => $e->getMessage()
                    ];
                }
            }
        }
    }

    /**
     * Historial de eventos para monitoreo y auditoria.
     */
    public function getHistory(): array
    {
        return $this->log;
    }
}
