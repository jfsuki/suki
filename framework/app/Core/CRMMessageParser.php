<?php
// framework/app/Core/CRMMessageParser.php

declare(strict_types=1);

namespace App\Core;

final class CRMMessageParser
{
    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function parse(string $skillName, array $context): array
    {
        $message = trim((string) ($context['message_text'] ?? ''));
        $pairs = $this->extractKeyValuePairs($message);
        $telemetry = ['module_used' => 'crm', 'action' => $skillName];

        return match ($skillName) {
            'crm_register_lead' => $this->parseRegisterLead($message, $pairs, $telemetry),
            'crm_search_customers' => $this->parseSearchCustomers($message, $pairs, $telemetry),
            'crm_update_customer' => $this->parseUpdateCustomer($message, $pairs, $telemetry),
            'crm_stats' => ['kind' => 'command', 'action' => 'crm_stats', 'telemetry' => $telemetry],
            default => [
                'kind' => 'ask_user', 
                'reply' => 'No pude interpretar la operación CRM.', 
                'telemetry' => $telemetry
            ],
        };
    }

    private function parseRegisterLead(string $message, array $pairs, array $telemetry): array
    {
        $nombre = $pairs['nombre'] ?? $pairs['name'] ?? '';
        $email = $pairs['email'] ?? '';
        $empresa = $pairs['empresa'] ?? $pairs['company'] ?? '';

        if (empty($nombre)) {
            return [
                'kind' => 'ask_user',
                'reply' => 'Necesito al menos el nombre del cliente para registrar el lead.',
                'telemetry' => $telemetry
            ];
        }

        return [
            'kind' => 'command',
            'action' => 'register_lead',
            'data' => [
                'nombre' => $nombre,
                'email' => $email,
                'empresa' => $empresa,
                'telefono' => $pairs['telefono'] ?? $pairs['phone'] ?? null,
                'status' => 'LEAD'
            ],
            'telemetry' => $telemetry
        ];
    }

    private function parseSearchCustomers(string $message, array $pairs, array $telemetry): array
    {
        $nombre = $pairs['nombre'] ?? $pairs['name'] ?? null;
        if (empty($nombre) && strlen($message) > 5 && !str_contains($message, ':')) {
            $nombre = $message; // Fallback
        }

        return [
            'kind' => 'command',
            'action' => 'search_customers',
            'filters' => [
                'nombre' => $nombre,
                'status' => $pairs['status'] ?? null,
                'empresa' => $pairs['empresa'] ?? null
            ],
            'telemetry' => $telemetry
        ];
    }

    private function parseUpdateCustomer(string $message, array $pairs, array $telemetry): array
    {
        $id = $pairs['id'] ?? $pairs['customer_id'] ?? '';
        if (empty($id)) {
            return [
                'kind' => 'ask_user',
                'reply' => 'Dime el ID del cliente para actualizar su información.',
                'telemetry' => $telemetry
            ];
        }

        unset($pairs['id'], $pairs['customer_id']);

        return [
            'kind' => 'command',
            'action' => 'update_customer',
            'customer_id' => $id,
            'updates' => $pairs,
            'telemetry' => $telemetry
        ];
    }

    private function extractKeyValuePairs(string $message): array
    {
        $pairs = [];
        preg_match_all('/([a-zA-Z_]+):\s*([a-zA-Z0-9.@_-]+)/', $message, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $pairs[strtolower($match[1])] = $match[2];
        }
        return $pairs;
    }
}
