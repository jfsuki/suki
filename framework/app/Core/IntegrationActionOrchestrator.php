<?php
// app/Core/IntegrationActionOrchestrator.php

namespace App\Core;

use RuntimeException;

final class IntegrationActionOrchestrator
{
    private IntegrationRegistry $registry;
    private IntegrationStore $store;
    private IntegrationAdapterFactory $factory;
    private AuditLogger $audit;

    public function __construct(
        ?IntegrationRegistry $registry = null,
        ?IntegrationStore $store = null,
        ?IntegrationAdapterFactory $factory = null,
        ?AuditLogger $audit = null
    ) {
        $this->registry = $registry ?? new IntegrationRegistry();
        $this->store = $store ?? new IntegrationStore();
        $this->factory = $factory ?? new IntegrationAdapterFactory();
        $this->audit = $audit ?? new AuditLogger();
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public function execute(array $payload, array $context = []): array
    {
        $intent = strtolower(trim((string) ($payload['intent'] ?? '')));
        $action = $this->resolveAction(
            strtolower(trim((string) ($payload['action'] ?? ''))),
            $intent
        );
        if ($action === '') {
            throw new RuntimeException('action o intent requerido para ejecutar integracion.');
        }

        $tenantId = trim((string) ($context['tenant_id'] ?? $payload['tenant_id'] ?? 'default'));
        $requestedEnvironment = strtolower(trim((string) ($payload['environment'] ?? ($context['environment'] ?? ''))));
        $integration = $this->resolveIntegration($payload, $tenantId, $requestedEnvironment);
        $integration = $this->applyEnvironment($integration, $requestedEnvironment);

        $adapter = $this->factory->make($integration);
        $adapterPayload = is_array($payload['payload'] ?? null) ? (array) $payload['payload'] : $payload;
        $result = $adapter->execute($action, $integration, $adapterPayload, $context);

        $integrationId = (string) ($integration['id'] ?? '');
        $externalId = $this->resolveExternalId($result, $payload);
        $entity = trim((string) ($payload['entity'] ?? ''));
        $recordId = isset($payload['record_id']) ? (string) $payload['record_id'] : null;

        if (in_array($action, ['emit_document', 'create_invoice'], true)) {
            $this->store->saveDocument(
                $integrationId,
                $entity !== '' ? $entity : null,
                $recordId !== '' ? $recordId : null,
                $externalId !== '' ? $externalId : null,
                'sent',
                $this->sanitizeForStore($adapterPayload),
                is_array($result['data'] ?? null) ? (array) $result['data'] : []
            );
        } elseif ($action === 'get_status' && $integrationId !== '' && $externalId !== '') {
            $this->store->updateDocumentStatus(
                $integrationId,
                $externalId,
                'status',
                is_array($result['data'] ?? null) ? (array) $result['data'] : []
            );
        } elseif ($action === 'cancel_document' && $integrationId !== '' && $externalId !== '') {
            $this->store->updateDocumentStatus(
                $integrationId,
                $externalId,
                'cancelled',
                is_array($result['data'] ?? null) ? (array) $result['data'] : []
            );
        }

        $this->audit->log(
            'integration_action',
            'integration',
            $integrationId,
            [
                'tenant_id' => $tenantId,
                'integration_id' => $integrationId,
                'provider' => $integration['provider'] ?? '',
                'environment' => $integration['environment'] ?? '',
                'intent' => $intent !== '' ? $intent : null,
                'action' => $action,
                'entity' => $entity !== '' ? $entity : null,
                'record_id' => $recordId !== '' ? $recordId : null,
                'external_id' => $externalId !== '' ? $externalId : null,
                'status' => $result['status'] ?? null,
                'ok' => ((int) ($result['status'] ?? 0) >= 200 && (int) ($result['status'] ?? 0) < 300),
            ]
        );

        return [
            'intent' => $intent,
            'action' => $action,
            'integration_id' => $integrationId,
            'provider' => (string) ($integration['provider'] ?? ''),
            'environment' => (string) ($integration['environment'] ?? ''),
            'status' => (int) ($result['status'] ?? 0),
            'data' => $result['data'] ?? [],
            'external_id' => $externalId !== '' ? $externalId : null,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function resolveIntegration(array $payload, string $tenantId, string $environment): array
    {
        $integrationId = trim((string) ($payload['integration_id'] ?? ''));
        if ($integrationId !== '') {
            return $this->registry->get($integrationId);
        }

        $provider = strtolower(trim((string) ($payload['provider'] ?? '')));
        $all = $this->registry->all();
        $matches = [];
        foreach ($all as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }
            $candidateProvider = strtolower((string) ($candidate['provider'] ?? ''));
            if ($provider !== '' && $candidateProvider !== $provider) {
                continue;
            }
            $candidateEnv = strtolower((string) ($candidate['environment'] ?? ''));
            if ($environment !== '' && $candidateEnv !== '' && $candidateEnv !== $environment) {
                continue;
            }
            $ownerTenant = trim((string) ($candidate['metadata']['tenant_id'] ?? ''));
            if ($ownerTenant !== '' && $ownerTenant !== $tenantId) {
                continue;
            }
            $matches[] = $candidate;
        }

        if (empty($matches)) {
            throw new RuntimeException('No existe integracion activa para el tenant/proveedor solicitado.');
        }

        usort($matches, static function (array $a, array $b): int {
            $aEnabled = !empty($a['enabled']) ? 1 : 0;
            $bEnabled = !empty($b['enabled']) ? 1 : 0;
            return $bEnabled <=> $aEnabled;
        });

        return $matches[0];
    }

    /**
     * @param array<string,mixed> $integration
     * @return array<string,mixed>
     */
    private function applyEnvironment(array $integration, string $requestedEnvironment): array
    {
        if ($requestedEnvironment === '') {
            return $integration;
        }

        $currentEnvironment = strtolower((string) ($integration['environment'] ?? ''));
        if ($currentEnvironment === $requestedEnvironment) {
            return $integration;
        }

        $meta = is_array($integration['metadata'] ?? null) ? (array) $integration['metadata'] : [];
        $environments = is_array($meta['environments'] ?? null) ? (array) $meta['environments'] : [];
        $override = is_array($environments[$requestedEnvironment] ?? null) ? (array) $environments[$requestedEnvironment] : [];
        if (empty($override)) {
            throw new RuntimeException(
                'La integracion ' . (string) ($integration['id'] ?? '')
                . ' no tiene configuracion para ambiente ' . $requestedEnvironment . '.'
            );
        }

        if (!empty($override['base_url'])) {
            $integration['base_url'] = (string) $override['base_url'];
        }
        if (!empty($override['auth']) && is_array($override['auth'])) {
            $integration['auth'] = array_merge(
                is_array($integration['auth'] ?? null) ? (array) $integration['auth'] : [],
                (array) $override['auth']
            );
        }
        $integration['environment'] = $requestedEnvironment;
        return $integration;
    }

    private function resolveAction(string $action, string $intent): string
    {
        if ($action !== '') {
            return $action;
        }
        $map = [
            'emitir_factura' => 'emit_document',
            'crear_factura' => 'emit_document',
            'invoice_emit' => 'emit_document',
            'invoice_status' => 'get_status',
            'consultar_estado' => 'get_status',
            'anular_factura' => 'cancel_document',
            'cancelar_documento' => 'cancel_document',
            'probar_conexion' => 'test_connection',
            'test_connection' => 'test_connection',
        ];
        return (string) ($map[$intent] ?? '');
    }

    /**
     * @param array<string,mixed> $result
     * @param array<string,mixed> $payload
     */
    private function resolveExternalId(array $result, array $payload): string
    {
        $externalId = trim((string) ($payload['external_id'] ?? ''));
        if ($externalId !== '') {
            return $externalId;
        }
        $data = is_array($result['data'] ?? null) ? (array) $result['data'] : [];
        $candidates = ['id', 'documentId', 'uuid', 'external_id'];
        foreach ($candidates as $field) {
            if (!empty($data[$field])) {
                return trim((string) $data[$field]);
            }
        }
        return '';
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function sanitizeForStore(array $payload): array
    {
        $copy = $payload;
        foreach (['token', 'api_key', 'password', 'secret', 'Authorization'] as $secretKey) {
            if (array_key_exists($secretKey, $copy)) {
                $copy[$secretKey] = '***';
            }
        }
        return $copy;
    }
}

