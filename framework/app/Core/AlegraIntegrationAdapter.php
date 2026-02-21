<?php
// app/Core/AlegraIntegrationAdapter.php

namespace App\Core;

use RuntimeException;

final class AlegraIntegrationAdapter implements IntegrationAdapterInterface
{
    public function execute(string $action, array $integration, array $payload, array $context = []): array
    {
        $baseUrl = (string) ($integration['base_url'] ?? '');
        $token = $this->resolveToken($integration, $payload);
        $headerName = (string) ($integration['auth']['header'] ?? 'Authorization');
        $headers = [$headerName => str_contains(strtolower($headerName), 'authorization') ? ('Bearer ' . $token) : $token];
        $http = new IntegrationHttpClient($baseUrl, (int) ($payload['timeout'] ?? 20));

        switch ($action) {
            case 'test_connection':
                $path = (string) ($payload['endpoint'] ?? '/company');
                return $http->request('GET', $path, $headers, [], true);
            case 'emit_document':
            case 'create_invoice':
                $path = (string) ($payload['endpoint'] ?? '/invoices');
                $body = is_array($payload['body'] ?? null) ? (array) $payload['body'] : [];
                return $http->request('POST', $path, $headers, $body);
            case 'get_status':
            case 'read_invoice':
                $path = (string) ($payload['endpoint'] ?? '/invoices');
                $externalId = (string) ($payload['external_id'] ?? '');
                if ($externalId !== '') {
                    $path = rtrim($path, '/') . '/' . rawurlencode($externalId);
                }
                return $http->request('GET', $path, $headers, [], true);
            default:
                throw new RuntimeException('Accion Alegra no soportada: ' . $action);
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
        $envKey = (string) ($payload['token_env'] ?? ($integration['auth']['token_env'] ?? 'ALEGRA_TOKEN'));
        $token = trim((string) getenv($envKey));
        if ($token === '') {
            throw new RuntimeException('Token no encontrado para Alegra (env: ' . $envKey . ').');
        }
        return $token;
    }
}

