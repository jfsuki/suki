<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class EcommerceHubService
{
    /** @var array<int, string> */
    private const PLATFORM_TYPES = [
        'woocommerce',
        'tiendanube',
        'prestashop',
        'custom_store',
        'unknown',
    ];

    /** @var array<int, string> */
    private const STORE_STATUSES = [
        'active',
        'inactive',
        'disconnected',
    ];

    /** @var array<int, string> */
    private const CONNECTION_STATUSES = [
        'not_configured',
        'pending_validation',
        'validated',
        'failed',
    ];

    /** @var array<int, string> */
    private const SYNC_JOB_STATUSES = [
        'queued',
        'running',
        'completed',
        'failed',
    ];

    private EcommerceHubRepository $repository;
    private AuditLogger $auditLogger;
    private EcommerceHubEventLogger $eventLogger;

    public function __construct(
        ?EcommerceHubRepository $repository = null,
        ?AuditLogger $auditLogger = null,
        ?EcommerceHubEventLogger $eventLogger = null
    ) {
        $this->repository = $repository ?? new EcommerceHubRepository();
        $this->auditLogger = $auditLogger ?? new AuditLogger();
        $this->eventLogger = $eventLogger ?? new EcommerceHubEventLogger();
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createStore(array $payload): array
    {
        $startedAt = microtime(true);
        $tenantId = $this->requireString($payload['tenant_id'] ?? null, 'tenant_id');
        $appId = $this->nullableString($payload['app_id'] ?? $payload['project_id'] ?? null);

        $store = $this->repository->createStore([
            'tenant_id' => $tenantId,
            'app_id' => $appId,
            'platform' => $this->platform($payload['platform'] ?? null),
            'store_name' => $this->requireString($payload['store_name'] ?? $payload['name'] ?? null, 'store_name'),
            'store_url' => $this->nullableString($payload['store_url'] ?? $payload['url'] ?? null),
            'status' => array_key_exists('status', $payload) && $payload['status'] !== null && $payload['status'] !== ''
                ? $this->storeStatus($payload['status'])
                : 'active',
            'connection_status' => array_key_exists('connection_status', $payload) && $payload['connection_status'] !== null && $payload['connection_status'] !== ''
                ? $this->connectionStatus($payload['connection_status'])
                : 'not_configured',
            'currency' => $this->nullableString($payload['currency'] ?? null),
            'timezone' => $this->nullableString($payload['timezone'] ?? null),
            'metadata' => $this->buildStoreMetadata(
                is_array($payload['metadata'] ?? null) ? (array) $payload['metadata'] : [],
                []
            ),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $store = $this->validatedStore($store);
        $this->logStoreEvent('create_store', $tenantId, $appId, $store, $this->latencyMs($startedAt));

        return $store;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function updateStore(array $payload): array
    {
        $startedAt = microtime(true);
        $tenantId = $this->requireString($payload['tenant_id'] ?? null, 'tenant_id');
        $storeId = $this->requireString($payload['store_id'] ?? $payload['id'] ?? null, 'store_id');
        $appId = $this->nullableString($payload['app_id'] ?? $payload['project_id'] ?? null);
        $store = $this->loadStore($tenantId, $storeId, $appId);
        $updates = [];

        foreach ([
            'store_name' => 'store_name',
            'store_url' => 'store_url',
            'currency' => 'currency',
            'timezone' => 'timezone',
        ] as $inputKey => $column) {
            if (array_key_exists($inputKey, $payload)) {
                $updates[$column] = $this->nullableString($payload[$inputKey]);
            }
        }
        if (array_key_exists('name', $payload) && !array_key_exists('store_name', $payload)) {
            $updates['store_name'] = $this->nullableString($payload['name']);
        }
        if (array_key_exists('url', $payload) && !array_key_exists('store_url', $payload)) {
            $updates['store_url'] = $this->nullableString($payload['url']);
        }
        if (array_key_exists('platform', $payload) && $payload['platform'] !== null && $payload['platform'] !== '') {
            $updates['platform'] = $this->platform($payload['platform']);
        }
        if (array_key_exists('status', $payload) && $payload['status'] !== null && $payload['status'] !== '') {
            $updates['status'] = $this->storeStatus($payload['status']);
        }
        if (array_key_exists('connection_status', $payload) && $payload['connection_status'] !== null && $payload['connection_status'] !== '') {
            $updates['connection_status'] = $this->connectionStatus($payload['connection_status']);
        }
        if (array_key_exists('metadata', $payload) && is_array($payload['metadata'])) {
            $updates['metadata'] = $this->buildStoreMetadata((array) $payload['metadata'], $store);
        }

        $updated = $this->repository->updateStore($tenantId, $storeId, $updates, $appId);
        if (!is_array($updated)) {
            throw new RuntimeException('ECOMMERCE_STORE_NOT_FOUND');
        }

        $updated = $this->validatedStore($updated);
        $this->logStoreEvent('update_store', $tenantId, $appId, $updated, $this->latencyMs($startedAt));

        return $updated;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function registerCredentials(array $payload): array
    {
        $startedAt = microtime(true);
        $tenantId = $this->requireString($payload['tenant_id'] ?? null, 'tenant_id');
        $storeId = $this->requireString($payload['store_id'] ?? null, 'store_id');
        $appId = $this->nullableString($payload['app_id'] ?? $payload['project_id'] ?? null);
        $store = $this->loadStore($tenantId, $storeId, $appId);
        $credentialType = $this->requireString($payload['credential_type'] ?? null, 'credential_type');
        $bundle = $this->extractCredentialPayloadBundle($payload);
        $encryptedPayload = $this->encryptCredentialPayload((array) ($bundle['raw'] ?? []));
        $maskedPayload = is_array($bundle['masked'] ?? null) ? (array) $bundle['masked'] : [];
        $fingerprint = hash(
            'sha256',
            json_encode($bundle['raw'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}'
        );

        $credential = $this->repository->transaction(function () use (
            $tenantId,
            $appId,
            $storeId,
            $credentialType,
            $encryptedPayload,
            $maskedPayload,
            $fingerprint,
            $store
        ): array {
            $created = $this->repository->createCredential([
                'tenant_id' => $tenantId,
                'app_id' => $appId,
                'store_id' => $storeId,
                'credential_type' => $credentialType,
                'encrypted_payload' => $encryptedPayload,
                'status' => 'active',
                'metadata' => [
                    'payload_fingerprint' => $fingerprint,
                    'masked_payload' => $maskedPayload,
                    'hooks' => $this->futureHooks(),
                ],
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            $this->repository->updateStore($tenantId, $storeId, [
                'connection_status' => (string) ($store['connection_status'] ?? '') === 'validated'
                    ? 'validated'
                    : 'pending_validation',
                'metadata' => $this->mergeMetadata(
                    is_array($store['metadata'] ?? null) ? (array) $store['metadata'] : [],
                    [
                        'credentials' => [
                            'last_registered_at' => date('c'),
                            'last_type' => $credentialType,
                        ],
                    ]
                ),
            ], $appId);

            return $created;
        });

        $masked = $this->validatedCredential($this->maskCredential($credential));
        $this->logCredentialEvent('register_credentials', $tenantId, $appId, $masked, $store, $this->latencyMs($startedAt));

        return $masked;
    }

    /**
     * @return array<string, mixed>
     */
    public function validateStoreSetup(string $tenantId, string $storeId, ?string $appId = null): array
    {
        $startedAt = microtime(true);
        $store = $this->loadStore($tenantId, $storeId, $appId);
        $credentials = $this->repository->listCredentialsByStore($tenantId, $storeId, $appId);
        $credentialsConfigured = count($credentials) > 0;
        $ready = $credentialsConfigured
            && (string) ($store['status'] ?? '') === 'active'
            && (string) ($store['connection_status'] ?? '') === 'validated';

        $setup = [
            'tenant_id' => $tenantId,
            'app_id' => $appId,
            'store_id' => $storeId,
            'platform' => (string) ($store['platform'] ?? 'unknown'),
            'status' => (string) ($store['status'] ?? 'inactive'),
            'connection_status' => (string) ($store['connection_status'] ?? 'not_configured'),
            'ready' => $ready,
            'credentials_configured' => $credentialsConfigured,
            'checks' => [
                'store_exists' => true,
                'platform_supported' => in_array((string) ($store['platform'] ?? 'unknown'), self::PLATFORM_TYPES, true),
                'store_active' => (string) ($store['status'] ?? '') === 'active',
                'connection_validated' => (string) ($store['connection_status'] ?? '') === 'validated',
                'credentials_configured' => $credentialsConfigured,
            ],
            'hooks' => $this->futureHooks(),
        ];
        EcommerceHubContractValidator::validateStoreSetup($setup);

        $this->eventLogger->log('validate_store_setup', $tenantId, [
            'tenant_id' => $tenantId,
            'app_id' => $appId,
            'module' => 'ecommerce',
            'action_name' => 'validate_store_setup',
            'store_id' => $storeId,
            'platform' => (string) ($store['platform'] ?? 'unknown'),
            'connection_status' => (string) ($store['connection_status'] ?? 'not_configured'),
            'latency_ms' => $this->latencyMs($startedAt),
            'result_status' => 'success',
        ]);
        $this->auditLogger->log('ecommerce_validate_store_setup', 'ecommerce_store', $storeId, [
            'tenant_id' => $tenantId,
            'app_id' => $appId,
            'module' => 'ecommerce',
            'action_name' => 'validate_store_setup',
            'store_id' => $storeId,
            'platform' => (string) ($store['platform'] ?? 'unknown'),
            'connection_status' => (string) ($store['connection_status'] ?? 'not_configured'),
            'latency_ms' => $this->latencyMs($startedAt),
            'result_status' => 'success',
        ]);

        return $setup;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function listStores(string $tenantId, array $filters = [], ?string $appId = null): array
    {
        $startedAt = microtime(true);
        $items = array_map(
            fn(array $store): array => $this->validatedStore($store),
            $this->repository->listStores($tenantId, $filters + ['app_id' => $appId], $this->limit($filters['limit'] ?? null, 20))
        );
        $selected = is_array($items[0] ?? null) ? (array) $items[0] : [];

        $this->eventLogger->log('list_stores', $tenantId, [
            'tenant_id' => $tenantId,
            'app_id' => $appId,
            'module' => 'ecommerce',
            'action_name' => 'list_stores',
            'store_id' => (string) ($selected['id'] ?? ''),
            'platform' => (string) ($selected['platform'] ?? ''),
            'connection_status' => (string) ($selected['connection_status'] ?? ''),
            'latency_ms' => $this->latencyMs($startedAt),
            'result_count' => count($items),
            'result_status' => 'success',
        ]);
        $this->auditLogger->log('ecommerce_list_stores', 'ecommerce_store', (string) ($selected['id'] ?? '') ?: null, [
            'tenant_id' => $tenantId,
            'app_id' => $appId,
            'module' => 'ecommerce',
            'action_name' => 'list_stores',
            'store_id' => (string) ($selected['id'] ?? ''),
            'platform' => (string) ($selected['platform'] ?? ''),
            'connection_status' => (string) ($selected['connection_status'] ?? ''),
            'latency_ms' => $this->latencyMs($startedAt),
            'result_status' => 'success',
        ]);

        return $items;
    }

    /**
     * @return array<string, mixed>
     */
    public function getStore(string $tenantId, string $storeId, ?string $appId = null): array
    {
        $startedAt = microtime(true);
        $store = $this->validatedStore($this->loadStore($tenantId, $storeId, $appId));
        $this->logStoreEvent('get_store', $tenantId, $appId, $store, $this->latencyMs($startedAt));

        return $store;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createSyncJob(array $payload): array
    {
        $startedAt = microtime(true);
        $tenantId = $this->requireString($payload['tenant_id'] ?? null, 'tenant_id');
        $storeId = $this->requireString($payload['store_id'] ?? null, 'store_id');
        $appId = $this->nullableString($payload['app_id'] ?? $payload['project_id'] ?? null);
        $store = $this->loadStore($tenantId, $storeId, $appId);

        $job = $this->repository->createSyncJob([
            'tenant_id' => $tenantId,
            'app_id' => $appId,
            'store_id' => $storeId,
            'sync_type' => $this->requireString($payload['sync_type'] ?? null, 'sync_type'),
            'status' => array_key_exists('status', $payload) && $payload['status'] !== null && $payload['status'] !== ''
                ? $this->syncJobStatus($payload['status'])
                : 'queued',
            'started_at' => $this->nullableString($payload['started_at'] ?? null),
            'finished_at' => $this->nullableString($payload['finished_at'] ?? null),
            'result_summary' => $this->nullableString($payload['result_summary'] ?? null),
            'metadata' => $this->mergeMetadata(
                is_array($payload['metadata'] ?? null) ? (array) $payload['metadata'] : [],
                [
                    'store_platform' => (string) ($store['platform'] ?? 'unknown'),
                    'hooks' => $this->futureHooks(),
                ]
            ),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $job = $this->validatedSyncJob($job);
        $this->logSyncJobEvent('create_sync_job', $tenantId, $appId, $job, $store, $this->latencyMs($startedAt));

        return $job;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function updateSyncJobStatus(array $payload): array
    {
        $tenantId = $this->requireString($payload['tenant_id'] ?? null, 'tenant_id');
        $syncJobId = $this->requireString($payload['sync_job_id'] ?? $payload['id'] ?? null, 'sync_job_id');
        $appId = $this->nullableString($payload['app_id'] ?? $payload['project_id'] ?? null);
        $job = $this->loadSyncJob($tenantId, $syncJobId, $appId);
        $status = $this->syncJobStatus($payload['status'] ?? null);

        $updated = $this->repository->updateSyncJob($tenantId, $syncJobId, [
            'status' => $status,
            'started_at' => $status === 'running'
                ? ($this->nullableString($payload['started_at'] ?? $job['started_at'] ?? null) ?? date('Y-m-d H:i:s'))
                : $this->nullableString($payload['started_at'] ?? $job['started_at'] ?? null),
            'finished_at' => in_array($status, ['completed', 'failed'], true)
                ? ($this->nullableString($payload['finished_at'] ?? null) ?? date('Y-m-d H:i:s'))
                : $this->nullableString($payload['finished_at'] ?? $job['finished_at'] ?? null),
            'result_summary' => $this->nullableString($payload['result_summary'] ?? $job['result_summary'] ?? null),
            'metadata' => $this->mergeMetadata(
                is_array($job['metadata'] ?? null) ? (array) $job['metadata'] : [],
                is_array($payload['metadata'] ?? null) ? (array) $payload['metadata'] : []
            ),
        ], $appId);
        if (!is_array($updated)) {
            throw new RuntimeException('ECOMMERCE_SYNC_JOB_NOT_FOUND');
        }

        return $this->validatedSyncJob($updated);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function listSyncJobs(string $tenantId, array $filters = [], ?string $appId = null): array
    {
        $startedAt = microtime(true);
        if (($storeId = $this->nullableString($filters['store_id'] ?? null)) !== null) {
            $this->loadStore($tenantId, $storeId, $appId);
        }

        $items = array_map(
            fn(array $job): array => $this->validatedSyncJob($job),
            $this->repository->listSyncJobs($tenantId, $filters + ['app_id' => $appId], $this->limit($filters['limit'] ?? null, 20))
        );
        $selected = is_array($items[0] ?? null) ? (array) $items[0] : [];

        $this->eventLogger->log('list_sync_jobs', $tenantId, [
            'tenant_id' => $tenantId,
            'app_id' => $appId,
            'module' => 'ecommerce',
            'action_name' => 'list_sync_jobs',
            'store_id' => (string) ($selected['store_id'] ?? ($filters['store_id'] ?? '')),
            'sync_job_id' => (string) ($selected['id'] ?? ''),
            'sync_type' => (string) ($selected['sync_type'] ?? ($filters['sync_type'] ?? '')),
            'latency_ms' => $this->latencyMs($startedAt),
            'result_count' => count($items),
            'result_status' => 'success',
        ]);
        $this->auditLogger->log('ecommerce_list_sync_jobs', 'ecommerce_sync_job', (string) ($selected['id'] ?? '') ?: null, [
            'tenant_id' => $tenantId,
            'app_id' => $appId,
            'module' => 'ecommerce',
            'action_name' => 'list_sync_jobs',
            'store_id' => (string) ($selected['store_id'] ?? ($filters['store_id'] ?? '')),
            'sync_job_id' => (string) ($selected['id'] ?? ''),
            'sync_type' => (string) ($selected['sync_type'] ?? ($filters['sync_type'] ?? '')),
            'latency_ms' => $this->latencyMs($startedAt),
            'result_status' => 'success',
        ]);

        return $items;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function getOrderRefsByStore(string $tenantId, string $storeId, array $filters = [], ?string $appId = null): array
    {
        $startedAt = microtime(true);
        $this->loadStore($tenantId, $storeId, $appId);

        $items = array_map(
            fn(array $item): array => $this->validatedOrderRef($item),
            $this->repository->listOrderRefs($tenantId, $filters + ['store_id' => $storeId, 'app_id' => $appId], $this->limit($filters['limit'] ?? null, 20))
        );

        $this->eventLogger->log('list_order_refs', $tenantId, [
            'tenant_id' => $tenantId,
            'app_id' => $appId,
            'module' => 'ecommerce',
            'action_name' => 'list_order_refs',
            'store_id' => $storeId,
            'latency_ms' => $this->latencyMs($startedAt),
            'result_count' => count($items),
            'result_status' => 'success',
        ]);
        $this->auditLogger->log('ecommerce_list_order_refs', 'ecommerce_order_ref', null, [
            'tenant_id' => $tenantId,
            'app_id' => $appId,
            'module' => 'ecommerce',
            'action_name' => 'list_order_refs',
            'store_id' => $storeId,
            'latency_ms' => $this->latencyMs($startedAt),
            'result_status' => 'success',
        ]);

        return $items;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createOrderRef(array $payload): array
    {
        $tenantId = $this->requireString($payload['tenant_id'] ?? null, 'tenant_id');
        $storeId = $this->requireString($payload['store_id'] ?? null, 'store_id');
        $appId = $this->nullableString($payload['app_id'] ?? $payload['project_id'] ?? null);
        $this->loadStore($tenantId, $storeId, $appId);

        return $this->validatedOrderRef($this->repository->createOrderRef([
            'tenant_id' => $tenantId,
            'app_id' => $appId,
            'store_id' => $storeId,
            'external_order_id' => $this->requireString($payload['external_order_id'] ?? null, 'external_order_id'),
            'local_order_status' => $this->nullableString($payload['local_order_status'] ?? null),
            'external_status' => $this->nullableString($payload['external_status'] ?? null),
            'total' => $this->nullableAmount($payload['total'] ?? null),
            'currency' => $this->nullableString($payload['currency'] ?? null),
            'metadata' => is_array($payload['metadata'] ?? null) ? (array) $payload['metadata'] : [],
            'created_at' => date('Y-m-d H:i:s'),
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function loadStore(string $tenantId, string $storeId, ?string $appId = null): array
    {
        $store = $this->repository->findStore($tenantId, $storeId, $appId);
        if (!is_array($store)) {
            throw new RuntimeException('ECOMMERCE_STORE_NOT_FOUND');
        }

        return $store;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadSyncJob(string $tenantId, string $syncJobId, ?string $appId = null): array
    {
        $job = $this->repository->findSyncJob($tenantId, $syncJobId, $appId);
        if (!is_array($job)) {
            throw new RuntimeException('ECOMMERCE_SYNC_JOB_NOT_FOUND');
        }

        return $job;
    }

    /**
     * @param array<string, mixed> $inputMetadata
     * @param array<string, mixed> $existingStore
     * @return array<string, mixed>
     */
    private function buildStoreMetadata(array $inputMetadata, array $existingStore): array
    {
        return $this->mergeMetadata(
            is_array($existingStore['metadata'] ?? null) ? (array) $existingStore['metadata'] : [],
            $inputMetadata,
            [
                'hooks' => $this->futureHooks(),
                'adapter_state' => [
                    'provider_runtime' => 'pending',
                    'product_sync' => 'pending',
                    'order_sync' => 'pending',
                    'inventory_sync' => 'pending',
                    'fiscal_linkage' => 'pending',
                ],
            ]
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{raw: array<string, mixed>, masked: array<string, mixed>}
     */
    private function extractCredentialPayloadBundle(array $payload): array
    {
        $raw = [];

        if (is_array($payload['credentials'] ?? null) && (array) $payload['credentials'] !== []) {
            $raw = (array) $payload['credentials'];
        } elseif (is_array($payload['payload'] ?? null) && (array) $payload['payload'] !== []) {
            $raw = (array) $payload['payload'];
        } elseif (($payloadJson = trim((string) ($payload['payload_json'] ?? ''))) !== '') {
            $decoded = json_decode($payloadJson, true);
            if (is_array($decoded)) {
                $raw = $decoded;
            }
        } else {
            foreach (['token', 'api_key', 'secret', 'client_id', 'client_secret', 'username', 'password', 'key'] as $key) {
                $value = $this->nullableString($payload[$key] ?? null);
                if ($value !== null) {
                    $raw[$key] = $value;
                }
            }
        }

        if ($raw === []) {
            throw new RuntimeException('ECOMMERCE_CREDENTIAL_PAYLOAD_REQUIRED');
        }

        return [
            'raw' => $raw,
            'masked' => $this->maskSensitivePayload($raw),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function maskSensitivePayload(array $payload): array
    {
        $masked = [];
        foreach ($payload as $key => $value) {
            $masked[$key] = $this->looksSensitiveKey((string) $key)
                ? '***'
                : (is_scalar($value) || $value === null ? $value : '[complex]');
        }

        return $masked;
    }

    private function looksSensitiveKey(string $key): bool
    {
        $key = strtolower(trim($key));
        foreach (['token', 'secret', 'password', 'key'] as $fragment) {
            if (str_contains($key, $fragment)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function encryptCredentialPayload(array $payload): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('ECOMMERCE_CREDENTIAL_PAYLOAD_INVALID');
        }

        $cipher = 'aes-256-cbc';
        $ivLength = openssl_cipher_iv_length($cipher);
        if ($ivLength < 1) {
            throw new RuntimeException('ECOMMERCE_CREDENTIAL_ENCRYPTION_UNAVAILABLE');
        }

        $iv = random_bytes($ivLength);
        $encrypted = openssl_encrypt($json, $cipher, $this->encryptionKey(), OPENSSL_RAW_DATA, $iv);
        if (!is_string($encrypted) || $encrypted === '') {
            throw new RuntimeException('ECOMMERCE_CREDENTIAL_ENCRYPT_FAILED');
        }

        $package = json_encode([
            'v' => 1,
            'cipher' => $cipher,
            'iv' => base64_encode($iv),
            'payload' => base64_encode($encrypted),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($package === false) {
            throw new RuntimeException('ECOMMERCE_CREDENTIAL_ENCRYPT_FAILED');
        }

        return base64_encode($package);
    }

    private function encryptionKey(): string
    {
        $secret = trim((string) (getenv('ECOMMERCE_HUB_SECRET') ?: ''));
        if ($secret === '') {
            $secret = hash('sha256', FRAMEWORK_ROOT . '|' . PROJECT_ROOT . '|ecommerce_hub_secret');
        }

        return hash('sha256', $secret, true);
    }

    /**
     * @param array<string, mixed> $credential
     * @return array<string, mixed>
     */
    private function maskCredential(array $credential): array
    {
        $credential['encrypted_payload'] = '***masked***';

        return $credential;
    }

    /**
     * @return array<string, mixed>
     */
    private function futureHooks(): array
    {
        return [
            'woocommerce_adapter' => 'pending',
            'tiendanube_adapter' => 'pending',
            'prestashop_adapter' => 'pending',
            'product_sync' => 'pending',
            'order_sync' => 'pending',
            'inventory_sync' => 'pending',
            'fiscal_order_linkage' => 'pending',
            'internal_order_ingest' => 'pending',
        ];
    }

    /**
     * @param array<string, mixed> ...$parts
     * @return array<string, mixed>
     */
    private function mergeMetadata(array ...$parts): array
    {
        $result = [];
        foreach ($parts as $part) {
            foreach ($part as $key => $value) {
                if (is_array($value) && is_array($result[$key] ?? null)) {
                    $result[$key] = $this->mergeMetadata((array) $result[$key], $value);
                    continue;
                }
                $result[$key] = $value;
            }
        }

        return $result;
    }

    private function platform($value): string
    {
        $value = strtolower(trim((string) $value));
        if (!in_array($value, self::PLATFORM_TYPES, true)) {
            throw new RuntimeException('ECOMMERCE_PLATFORM_INVALID');
        }

        return $value;
    }

    private function storeStatus($value): string
    {
        $value = strtolower(trim((string) $value));
        if (!in_array($value, self::STORE_STATUSES, true)) {
            throw new RuntimeException('ECOMMERCE_STORE_STATUS_INVALID');
        }

        return $value;
    }

    private function connectionStatus($value): string
    {
        $value = strtolower(trim((string) $value));
        if (!in_array($value, self::CONNECTION_STATUSES, true)) {
            throw new RuntimeException('ECOMMERCE_CONNECTION_STATUS_INVALID');
        }

        return $value;
    }

    private function syncJobStatus($value): string
    {
        $value = strtolower(trim((string) $value));
        if (!in_array($value, self::SYNC_JOB_STATUSES, true)) {
            throw new RuntimeException('ECOMMERCE_SYNC_JOB_STATUS_INVALID');
        }

        return $value;
    }

    private function nullableAmount($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            throw new RuntimeException('ECOMMERCE_AMOUNT_INVALID');
        }

        return round((float) $value, 4);
    }

    private function requireString($value, string $field): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            throw new RuntimeException(strtoupper($field) . '_REQUIRED');
        }

        return $value;
    }

    private function nullableString($value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function limit($value, int $default): int
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return $default;
        }

        return max(1, min(100, (int) $value));
    }

    private function latencyMs(float $startedAt): int
    {
        return (int) max(0, round((microtime(true) - $startedAt) * 1000));
    }

    /**
     * @param array<string, mixed> $store
     * @return array<string, mixed>
     */
    private function validatedStore(array $store): array
    {
        $store['metadata'] = is_array($store['metadata'] ?? null) ? (array) $store['metadata'] : [];
        EcommerceHubContractValidator::validateStore($store);

        return $store;
    }

    /**
     * @param array<string, mixed> $credential
     * @return array<string, mixed>
     */
    private function validatedCredential(array $credential): array
    {
        $credential['metadata'] = is_array($credential['metadata'] ?? null) ? (array) $credential['metadata'] : [];
        EcommerceHubContractValidator::validateCredential($credential);

        return $credential;
    }

    /**
     * @param array<string, mixed> $job
     * @return array<string, mixed>
     */
    private function validatedSyncJob(array $job): array
    {
        $job['metadata'] = is_array($job['metadata'] ?? null) ? (array) $job['metadata'] : [];
        EcommerceHubContractValidator::validateSyncJob($job);

        return $job;
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function validatedOrderRef(array $item): array
    {
        $item['metadata'] = is_array($item['metadata'] ?? null) ? (array) $item['metadata'] : [];
        EcommerceHubContractValidator::validateOrderRef($item);

        return $item;
    }

    /**
     * @param array<string, mixed> $store
     */
    private function logStoreEvent(string $actionName, string $tenantId, ?string $appId, array $store, int $latencyMs): void
    {
        $payload = [
            'tenant_id' => $tenantId,
            'app_id' => $appId,
            'module' => 'ecommerce',
            'action_name' => $actionName,
            'store_id' => (string) ($store['id'] ?? ''),
            'platform' => (string) ($store['platform'] ?? 'unknown'),
            'connection_status' => (string) ($store['connection_status'] ?? 'not_configured'),
            'latency_ms' => $latencyMs,
            'result_status' => 'success',
        ];
        $this->auditLogger->log('ecommerce_' . $actionName, 'ecommerce_store', (string) ($store['id'] ?? ''), $payload);
        $this->eventLogger->log($actionName, $tenantId, $payload);
    }

    /**
     * @param array<string, mixed> $credential
     * @param array<string, mixed> $store
     */
    private function logCredentialEvent(string $actionName, string $tenantId, ?string $appId, array $credential, array $store, int $latencyMs): void
    {
        $payload = [
            'tenant_id' => $tenantId,
            'app_id' => $appId,
            'module' => 'ecommerce',
            'action_name' => $actionName,
            'store_id' => (string) ($store['id'] ?? ''),
            'platform' => (string) ($store['platform'] ?? 'unknown'),
            'connection_status' => (string) ($store['connection_status'] ?? 'not_configured'),
            'latency_ms' => $latencyMs,
            'result_status' => 'success',
        ];
        $this->auditLogger->log('ecommerce_' . $actionName, 'ecommerce_credential', (string) ($credential['id'] ?? ''), $payload);
        $this->eventLogger->log($actionName, $tenantId, $payload);
    }

    /**
     * @param array<string, mixed> $job
     * @param array<string, mixed> $store
     */
    private function logSyncJobEvent(string $actionName, string $tenantId, ?string $appId, array $job, array $store, int $latencyMs): void
    {
        $payload = [
            'tenant_id' => $tenantId,
            'app_id' => $appId,
            'module' => 'ecommerce',
            'action_name' => $actionName,
            'store_id' => (string) ($store['id'] ?? ''),
            'platform' => (string) ($store['platform'] ?? 'unknown'),
            'connection_status' => (string) ($store['connection_status'] ?? 'not_configured'),
            'sync_job_id' => (string) ($job['id'] ?? ''),
            'sync_type' => (string) ($job['sync_type'] ?? ''),
            'latency_ms' => $latencyMs,
            'result_status' => 'success',
        ];
        $this->auditLogger->log('ecommerce_' . $actionName, 'ecommerce_sync_job', (string) ($job['id'] ?? ''), $payload);
        $this->eventLogger->log($actionName, $tenantId, $payload);
    }
}
