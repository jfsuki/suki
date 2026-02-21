<?php
// app/Core/AlanubeIntegrationAdapter.php

namespace App\Core;

use RuntimeException;

final class AlanubeIntegrationAdapter implements IntegrationAdapterInterface
{
    public function execute(string $action, array $integration, array $payload, array $context = []): array
    {
        $token = $this->resolveToken($integration, $payload);
        $baseUrl = (string) ($integration['base_url'] ?? '');
        $client = new AlanubeClient($baseUrl, $token);
        $endpoint = (string) ($payload['endpoint'] ?? '/documents');

        switch ($action) {
            case 'test_connection':
                return $client->testConnection();
            case 'emit_document':
                $body = is_array($payload['body'] ?? null) ? (array) $payload['body'] : [];
                return $client->emitDocument($endpoint, $body);
            case 'get_status':
                $externalId = (string) ($payload['external_id'] ?? '');
                if ($externalId === '') {
                    throw new RuntimeException('external_id requerido para consultar estado.');
                }
                return $client->getDocument($endpoint, $externalId);
            case 'cancel_document':
                $externalId = (string) ($payload['external_id'] ?? '');
                if ($externalId === '') {
                    throw new RuntimeException('external_id requerido para anular.');
                }
                $body = is_array($payload['body'] ?? null) ? (array) $payload['body'] : [];
                return $client->cancelDocument($endpoint, $externalId, $body);
            default:
                throw new RuntimeException('Accion Alanube no soportada: ' . $action);
        }
    }

    /**
     * @param array<string,mixed> $integration
     * @param array<string,mixed> $payload
     */
    private function resolveToken(array $integration, array $payload): string
    {
        $inline = trim((string) ($payload['token'] ?? ''));
        if ($inline !== '') {
            return $inline;
        }
        $envKey = (string) ($payload['token_env'] ?? ($integration['auth']['token_env'] ?? 'ALANUBE_TOKEN'));
        $token = trim((string) getenv($envKey));
        if ($token === '') {
            throw new RuntimeException('Token no encontrado para Alanube (env: ' . $envKey . ').');
        }
        return $token;
    }
}

