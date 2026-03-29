<?php
// framework/app/Core/Agents/ConversationGatewayRoutingPolicyTrait.php

namespace App\Core\Agents;

trait ConversationGatewayRoutingPolicyTrait
{
    private array $routingLog = [];

    public function validateIngressEnvelope(array $payload): array
    {
        return ['ok' => !empty($payload['message'] ?? '')];
    }

    private function logRoutingEvent(string $event, array $data = []): void
    {
        $this->routingLog[] = [
            'event' => $event,
            'data' => $data,
            'at' => microtime(true)
        ];
    }
}
