<?php
// app/Core/AlanubeIntegrationAdapter.php

namespace App\Core;

use RuntimeException;

final class AlanubeIntegrationAdapter implements IntegrationAdapterInterface
{
    public function execute(string $action, array $integration, array $payload, array $context = []): array
    {
        $token = $this->resolveToken($integration, $payload);
        $baseUrl = $this->resolveBaseUrl($integration);
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
            case 'list_received':
                $companyId = trim((string) ($payload['company_id'] ?? $integration['company_id'] ?? ''));
                if ($companyId === '') {
                    $metaCompany = is_array($integration['metadata'] ?? null) ? (array) $integration['metadata'] : [];
                    $companyId = trim((string) ($metaCompany['company_id'] ?? ''));
                }
                if ($companyId === '') {
                    throw new RuntimeException('company_id requerido para listar documentos recibidos.');
                }
                $filters = is_array($payload['filters'] ?? null) ? (array) $payload['filters'] : [];
                return $client->listReceivedDocuments($companyId, $filters);
            case 'get_received':
                $documentId = (string) ($payload['document_id'] ?? '');
                if ($documentId === '') {
                    throw new RuntimeException('document_id requerido para consultar documento recibido.');
                }
                return $client->getReceivedDocument($documentId);
            default:
                throw new RuntimeException('Accion Alanube no soportada: ' . $action);
        }
    }

    /**
     * @param array<string,mixed> $integration
     */
    private function resolveBaseUrl(array $integration): string
    {
        $url = trim((string) ($integration['base_url'] ?? ''));
        if ($url !== '') {
            return $url;
        }
        $envUrl = trim((string) getenv('ALANUBE_BASE_URL'));
        if ($envUrl !== '') {
            return $envUrl;
        }
        return 'https://api.alanube.co/co/v1';
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

