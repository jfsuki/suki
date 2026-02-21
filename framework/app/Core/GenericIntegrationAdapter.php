<?php
// app/Core/GenericIntegrationAdapter.php

namespace App\Core;

use RuntimeException;

final class GenericIntegrationAdapter implements IntegrationAdapterInterface
{
    public function execute(string $action, array $integration, array $payload, array $context = []): array
    {
        $baseUrl = (string) ($integration['base_url'] ?? '');
        $http = new IntegrationHttpClient($baseUrl, (int) ($payload['timeout'] ?? 20));
        $method = strtoupper((string) ($payload['method'] ?? 'POST'));
        $path = (string) ($payload['endpoint'] ?? '/');
        $body = is_array($payload['body'] ?? null) ? (array) $payload['body'] : [];
        $headers = $this->buildHeaders($integration, $payload);

        if ($action === 'test_connection') {
            $method = 'GET';
            $body = [];
        } elseif ($action === 'webhook_ack') {
            return ['status' => 200, 'data' => ['ok' => true, 'message' => 'ack']];
        }

        if ($path === '') {
            throw new RuntimeException('endpoint requerido para integracion generica.');
        }

        return $http->request($method, $path, $headers, $body, true);
    }

    /**
     * @param array<string,mixed> $integration
     * @param array<string,mixed> $payload
     * @return array<string,string>
     */
    private function buildHeaders(array $integration, array $payload): array
    {
        $headers = [];
        if (!empty($payload['headers']) && is_array($payload['headers'])) {
            foreach ($payload['headers'] as $k => $v) {
                if ((string) $k !== '') {
                    $headers[(string) $k] = (string) $v;
                }
            }
        }

        $token = trim((string) ($payload['token'] ?? ''));
        if ($token === '') {
            $envKey = (string) ($payload['token_env'] ?? ($integration['auth']['token_env'] ?? ''));
            if ($envKey !== '') {
                $token = trim((string) getenv($envKey));
            }
        }
        if ($token !== '') {
            $headerName = (string) ($integration['auth']['header'] ?? 'Authorization');
            $headers[$headerName] = str_contains(strtolower($headerName), 'authorization') ? ('Bearer ' . $token) : $token;
        }
        return $headers;
    }
}

