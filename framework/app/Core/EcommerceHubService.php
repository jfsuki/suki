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

    /** @var array<int, string> */
    private const PRODUCT_SYNC_STATUSES = [
        'linked',
        'prepared',
        'pending_push',
        'pending_pull',
        'snapshot_received',
        'synced',
        'failed',
    ];

    /** @var array<int, string> */
    private const ORDER_SYNC_STATUSES = [
        'linked',
        'normalized',
        'pending_push',
        'pending_pull',
        'snapshot_received',
        'synced',
        'failed',
    ];

    /** @var array<int, string> */
    private const ORDER_STATUSES = [
        'pending',
        'paid',
        'processing',
        'completed',
        'canceled',
        'refunded',
        'unknown',
    ];

    /** @var array<int, string> */
    private const SYNC_DIRECTIONS = [
        'push_local_to_store',
        'pull_store_to_local',
    ];

    private EcommerceHubRepository $repository;
    private AuditLogger $auditLogger;
    private EcommerceHubEventLogger $eventLogger;
    private EcommerceAdapterResolver $adapterResolver;
    private EntitySearchService $entitySearchService;

    public function __construct(
        ?EcommerceHubRepository $repository = null,
        ?AuditLogger $auditLogger = null,
        ?EcommerceHubEventLogger $eventLogger = null,
        ?EcommerceAdapterResolver $adapterResolver = null,
        ?EntitySearchService $entitySearchService = null
    ) {
        $this->repository = $repository ?? new EcommerceHubRepository();
        $this->auditLogger = $auditLogger ?? new AuditLogger();
        $this->eventLogger = $eventLogger ?? new EcommerceHubEventLogger();
        $this->adapterResolver = $adapterResolver ?? new EcommerceAdapterResolver();
        $this->entitySearchService = $entitySearchService ?? new EntitySearchService();
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
        $platform = $this->platform($payload['platform'] ?? null);

        $store = $this->repository->createStore([
            'tenant_id' => $tenantId,
            'app_id' => $appId,
            'platform' => $platform,
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
                [],
                $platform
            ),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $store = $this->validatedStore($store);
        $adapter = $this->resolveAdapter((string) ($store['platform'] ?? 'unknown'));
        $this->logStoreEvent('create_store', $tenantId, $appId, $store, $this->latencyMs($startedAt), [
            'adapter_key' => $adapter->getPlatformKey(),
            'validation_result' => 'not_applicable',
        ]);

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
        $resolvedPlatform = (string) ($updates['platform'] ?? $store['platform'] ?? 'unknown');
        if (
            (array_key_exists('metadata', $payload) && is_array($payload['metadata']))
            || array_key_exists('platform', $updates)
        ) {
            $updates['metadata'] = $this->buildStoreMetadata(
                is_array($payload['metadata'] ?? null) ? (array) $payload['metadata'] : [],
                $store,
                $resolvedPlatform
            );
        }

        $updated = $this->repository->updateStore($tenantId, $storeId, $updates, $appId);
        if (!is_array($updated)) {
            throw new RuntimeException('ECOMMERCE_STORE_NOT_FOUND');
        }

        $updated = $this->validatedStore($updated);
        $adapter = $this->resolveAdapter((string) ($updated['platform'] ?? 'unknown'));
        $this->logStoreEvent('update_store', $tenantId, $appId, $updated, $this->latencyMs($startedAt), [
            'adapter_key' => $adapter->getPlatformKey(),
            'validation_result' => 'not_applicable',
        ]);

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
                    'hooks' => $this->futureHooks($this->resolveAdapter((string) ($store['platform'] ?? 'unknown'))),
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
        $adapter = $this->resolveAdapter((string) ($store['platform'] ?? 'unknown'));
        $this->logCredentialEvent('register_credentials', $tenantId, $appId, $masked, $store, $this->latencyMs($startedAt), [
            'adapter_key' => $adapter->getPlatformKey(),
            'validation_result' => 'credentials_registered',
        ]);

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
        $adapter = $this->resolveAdapter((string) ($store['platform'] ?? 'unknown'));
        $validation = $this->evaluateCredentialValidation($store, $credentials, $adapter);
        $credentialsConfigured = count($credentials) > 0;
        $ready = $credentialsConfigured
            && (string) ($store['status'] ?? '') === 'active'
            && (string) ($store['connection_status'] ?? '') === 'validated'
            && $adapter->getPlatformKey() !== 'unknown';

        $setup = [
            'tenant_id' => $tenantId,
            'app_id' => $appId,
            'store_id' => $storeId,
            'platform' => (string) ($store['platform'] ?? 'unknown'),
            'adapter_key' => $adapter->getPlatformKey(),
            'status' => (string) ($store['status'] ?? 'inactive'),
            'connection_status' => (string) ($store['connection_status'] ?? 'not_configured'),
            'validation_result' => (string) ($validation['validation_result'] ?? 'not_checked'),
            'ready' => $ready,
            'credentials_configured' => $credentialsConfigured,
            'capabilities' => $adapter->listCapabilities(),
            'checks' => [
                'store_exists' => true,
                'platform_supported' => $adapter->getPlatformKey() !== 'unknown',
                'store_active' => (string) ($store['status'] ?? '') === 'active',
                'connection_validated' => (string) ($store['connection_status'] ?? '') === 'validated',
                'credentials_configured' => $credentialsConfigured,
                'adapter_resolved' => $adapter->getPlatformKey() !== 'unknown',
                'credential_shape_valid' => (bool) ($validation['valid'] ?? false),
            ],
            'hooks' => $this->mergeMetadata(
                $this->futureHooks($adapter),
                [
                    'adapter_state' => [
                        'adapter_key' => $adapter->getPlatformKey(),
                        'validation_result' => (string) ($validation['validation_result'] ?? 'not_checked'),
                    ],
                ]
            ),
        ];
        EcommerceHubContractValidator::validateStoreSetup($setup);

        $this->logStoreEvent('validate_store_setup', $tenantId, $appId, $store, $this->latencyMs($startedAt), [
            'adapter_key' => $adapter->getPlatformKey(),
            'validation_result' => (string) ($validation['validation_result'] ?? 'not_checked'),
            'result_status' => 'success',
        ]);

        return $setup;
    }

    /**
     * @return array<string, mixed>
     */
    public function validateConnection(string $tenantId, string $storeId, ?string $appId = null): array
    {
        $startedAt = microtime(true);
        $store = $this->loadStore($tenantId, $storeId, $appId);
        $credentials = $this->repository->listCredentialsByStore($tenantId, $storeId, $appId);
        $adapter = $this->resolveAdapter((string) ($store['platform'] ?? 'unknown'));
        $summary = $this->summarizeCredentials($credentials);
        $validation = $this->evaluateCredentialValidation($store, $credentials, $adapter);
        $resultStatus = ($validation['valid'] ?? false) === true ? 'success' : 'safe_failure';

        $result = [
            'tenant_id' => $tenantId,
            'app_id' => $appId,
            'store_id' => $storeId,
            'platform' => (string) ($store['platform'] ?? 'unknown'),
            'adapter_key' => $adapter->getPlatformKey(),
            'stored_connection_status' => (string) ($store['connection_status'] ?? 'not_configured'),
            'connection_status' => (string) ($validation['connection_status'] ?? 'not_configured'),
            'validation_result' => (string) ($validation['validation_result'] ?? 'unknown'),
            'valid' => (bool) ($validation['valid'] ?? false),
            'credentials_configured' => (bool) ($summary['configured'] ?? false),
            'credential_summary' => $summary,
            'masked_credentials' => is_array($validation['masked_credentials'] ?? null) ? (array) $validation['masked_credentials'] : [],
            'required_credentials' => is_array($validation['required_credentials'] ?? null) ? (array) $validation['required_credentials'] : [],
            'required_store_fields' => is_array($validation['required_store_fields'] ?? null) ? (array) $validation['required_store_fields'] : [],
            'missing_credentials' => is_array($validation['missing_credentials'] ?? null) ? (array) $validation['missing_credentials'] : [],
            'missing_store_fields' => is_array($validation['missing_store_fields'] ?? null) ? (array) $validation['missing_store_fields'] : [],
            'capabilities' => $adapter->listCapabilities(),
            'result_status' => $resultStatus,
        ];

        $this->logStoreEvent('validate_connection', $tenantId, $appId, $store, $this->latencyMs($startedAt), [
            'adapter_key' => $adapter->getPlatformKey(),
            'validation_result' => (string) ($result['validation_result'] ?? 'unknown'),
            'result_status' => $resultStatus,
        ]);

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function getNormalizedStoreMetadata(string $tenantId, string $storeId, ?string $appId = null): array
    {
        $startedAt = microtime(true);
        $store = $this->validatedStore($this->loadStore($tenantId, $storeId, $appId));
        $credentials = $this->repository->listCredentialsByStore($tenantId, $storeId, $appId);
        $adapter = $this->resolveAdapter((string) ($store['platform'] ?? 'unknown'));
        $summary = $this->summarizeCredentials($credentials);
        $metadata = $adapter->getStoreMetadata($store);

        $result = [
            'tenant_id' => $tenantId,
            'app_id' => $appId,
            'store_id' => $storeId,
            'platform' => (string) ($store['platform'] ?? 'unknown'),
            'adapter_key' => $adapter->getPlatformKey(),
            'connection_status' => (string) ($store['connection_status'] ?? 'not_configured'),
            'credential_summary' => $summary,
            'capabilities' => $adapter->listCapabilities(),
            'metadata' => $this->mergeMetadata(
                is_array($metadata['metadata'] ?? null) ? (array) $metadata['metadata'] : [],
                ['credential_summary' => $summary]
            ),
            'result_status' => 'success',
        ];

        $this->logStoreEvent('get_store_metadata', $tenantId, $appId, $store, $this->latencyMs($startedAt), [
            'adapter_key' => $adapter->getPlatformKey(),
            'validation_result' => 'metadata_ready',
            'result_status' => 'success',
        ]);

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function getPlatformCapabilities(string $tenantId, ?string $storeId = null, ?string $platform = null, ?string $appId = null): array
    {
        $startedAt = microtime(true);
        $resolvedStore = null;
        if ($storeId !== null && trim($storeId) !== '') {
            $resolvedStore = $this->loadStore($tenantId, $storeId, $appId);
            $platform = (string) ($resolvedStore['platform'] ?? $platform ?? 'unknown');
        }
        if ($platform === null || trim($platform) === '') {
            throw new RuntimeException('ECOMMERCE_PLATFORM_OR_STORE_REQUIRED');
        }

        $platform = $this->platform($platform);
        $adapter = $this->resolveAdapter($platform);
        $store = is_array($resolvedStore) ? $resolvedStore : ['id' => '', 'platform' => $platform, 'connection_status' => 'not_configured'];
        $resultStatus = $adapter->getPlatformKey() === 'unknown' ? 'safe_failure' : 'success';
        $validationResult = $adapter->getPlatformKey() === 'unknown' ? 'unsupported_platform' : 'capabilities_ready';

        $result = [
            'tenant_id' => $tenantId,
            'app_id' => $appId,
            'store_id' => $storeId ?? '',
            'platform' => $platform,
            'adapter_key' => $adapter->getPlatformKey(),
            'capabilities' => $adapter->listCapabilities(),
            'validation_result' => $validationResult,
            'result_status' => $resultStatus,
        ];

        $this->logStoreEvent('get_platform_capabilities', $tenantId, $appId, $store, $this->latencyMs($startedAt), [
            'adapter_key' => $adapter->getPlatformKey(),
            'validation_result' => $validationResult,
            'result_status' => $resultStatus,
        ]);

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function pingStore(string $tenantId, string $storeId, ?string $appId = null): array
    {
        $startedAt = microtime(true);
        $store = $this->loadStore($tenantId, $storeId, $appId);
        $credentials = $this->repository->listCredentialsByStore($tenantId, $storeId, $appId);
        $adapter = $this->resolveAdapter((string) ($store['platform'] ?? 'unknown'));
        $summary = $this->summarizeCredentials($credentials);
        $ping = $this->evaluatePing($store, $credentials, $adapter);
        $resultStatus = (string) ($ping['result_status'] ?? 'safe_failure');

        $result = [
            'tenant_id' => $tenantId,
            'app_id' => $appId,
            'store_id' => $storeId,
            'platform' => (string) ($store['platform'] ?? 'unknown'),
            'adapter_key' => $adapter->getPlatformKey(),
            'connection_status' => (string) ($ping['connection_status'] ?? 'not_configured'),
            'validation_result' => (string) ($ping['validation_result'] ?? 'unknown'),
            'ping_attempted' => (bool) ($ping['ping_attempted'] ?? false),
            'reachable' => (bool) ($ping['reachable'] ?? false),
            'checked_endpoint' => $this->nullableString($ping['checked_endpoint'] ?? null),
            'message' => (string) ($ping['message'] ?? ''),
            'credential_summary' => $summary,
            'capabilities' => $adapter->listCapabilities(),
            'result_status' => $resultStatus,
        ];

        $this->logStoreEvent('ping_store', $tenantId, $appId, $store, $this->latencyMs($startedAt), [
            'adapter_key' => $adapter->getPlatformKey(),
            'validation_result' => (string) ($ping['validation_result'] ?? 'unknown'),
            'result_status' => $resultStatus,
        ]);

        return $result;
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
        $selectedAdapter = $this->resolveAdapter((string) ($selected['platform'] ?? 'unknown'));

        $this->eventLogger->log('list_stores', $tenantId, [
            'tenant_id' => $tenantId,
            'app_id' => $appId,
            'module' => 'ecommerce',
            'action_name' => 'list_stores',
            'store_id' => (string) ($selected['id'] ?? ''),
            'platform' => (string) ($selected['platform'] ?? ''),
            'adapter_key' => $selectedAdapter->getPlatformKey(),
            'validation_result' => 'list_result',
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
            'adapter_key' => $selectedAdapter->getPlatformKey(),
            'validation_result' => 'list_result',
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
        $adapter = $this->resolveAdapter((string) ($store['platform'] ?? 'unknown'));
        $this->logStoreEvent('get_store', $tenantId, $appId, $store, $this->latencyMs($startedAt), [
            'adapter_key' => $adapter->getPlatformKey(),
            'validation_result' => 'not_applicable',
        ]);

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
                    'hooks' => $this->futureHooks($this->resolveAdapter((string) ($store['platform'] ?? 'unknown'))),
                ]
            ),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $job = $this->validatedSyncJob($job);
        $adapter = $this->resolveAdapter((string) ($store['platform'] ?? 'unknown'));
        $this->logSyncJobEvent('create_sync_job', $tenantId, $appId, $job, $store, $this->latencyMs($startedAt), [
            'adapter_key' => $adapter->getPlatformKey(),
            'validation_result' => 'sync_job_registered',
        ]);

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
        $selectedAdapter = $this->resolveAdapter((string) ($selected['metadata']['store_platform'] ?? 'unknown'));

        $this->eventLogger->log('list_sync_jobs', $tenantId, [
            'tenant_id' => $tenantId,
            'app_id' => $appId,
            'module' => 'ecommerce',
            'action_name' => 'list_sync_jobs',
            'store_id' => (string) ($selected['store_id'] ?? ($filters['store_id'] ?? '')),
            'adapter_key' => $selectedAdapter->getPlatformKey(),
            'validation_result' => 'list_result',
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
            'adapter_key' => $selectedAdapter->getPlatformKey(),
            'validation_result' => 'list_result',
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
        $store = $this->loadStore($tenantId, $storeId, $appId);
        $adapter = $this->resolveAdapter((string) ($store['platform'] ?? 'unknown'));

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
            'platform' => (string) ($store['platform'] ?? 'unknown'),
            'adapter_key' => $adapter->getPlatformKey(),
            'validation_result' => 'list_result',
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
            'platform' => (string) ($store['platform'] ?? 'unknown'),
            'adapter_key' => $adapter->getPlatformKey(),
            'validation_result' => 'list_result',
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
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function linkOrder(array $payload): array
    {
        $startedAt = microtime(true);
        $tenantId = $this->requireString($payload['tenant_id'] ?? null, 'tenant_id');
        $storeId = $this->requireString($payload['store_id'] ?? null, 'store_id');
        $externalOrderId = $this->requireString($payload['external_order_id'] ?? null, 'external_order_id');
        $appId = $this->nullableString($payload['app_id'] ?? $payload['project_id'] ?? null);
        $store = $this->loadStore($tenantId, $storeId, $appId);
        $adapter = $this->resolveAdapter((string) ($store['platform'] ?? 'unknown'));
        $inputMetadata = is_array($payload['metadata'] ?? null) ? (array) $payload['metadata'] : [];
        $externalStatusInput = $this->nullableString($payload['external_status'] ?? ($inputMetadata['external_status'] ?? null));
        $localStatusInput = $this->nullableString($payload['local_status'] ?? ($inputMetadata['local_status'] ?? null));
        $existing = $this->repository->findOrderLinkByExternalOrder($tenantId, $storeId, $externalOrderId, $appId);

        $metadata = $this->mergeMetadata(
            $inputMetadata,
            [
                'link_origin' => 'foundation_manual',
                'adapter_key' => $adapter->getPlatformKey(),
                'future_hooks' => $this->futureOrderHooks(),
            ]
        );
        if ($externalStatusInput !== null) {
            $metadata['external_status_raw'] = $externalStatusInput;
        }

        if (is_array($existing)) {
            $link = $this->repository->updateOrderLink($tenantId, (string) ($existing['id'] ?? ''), [
                'local_reference_type' => $this->nullableString($payload['local_reference_type'] ?? null),
                'local_reference_id' => $this->nullableString($payload['local_reference_id'] ?? null),
                'external_status' => $this->nullableOrderStatus($externalStatusInput),
                'local_status' => $this->nullableOrderStatus($localStatusInput),
                'currency' => $this->nullableCurrencyCode($payload['currency'] ?? null),
                'total' => $this->nullableAmount($payload['total'] ?? null),
                'sync_status' => 'linked',
                'metadata' => $this->mergeMetadata(
                    is_array($existing['metadata'] ?? null) ? (array) $existing['metadata'] : [],
                    $metadata
                ),
            ], $appId);
        } else {
            $link = $this->repository->createOrderLink([
                'tenant_id' => $tenantId,
                'app_id' => $appId,
                'store_id' => $storeId,
                'external_order_id' => $externalOrderId,
                'local_reference_type' => $this->nullableString($payload['local_reference_type'] ?? null),
                'local_reference_id' => $this->nullableString($payload['local_reference_id'] ?? null),
                'external_status' => $this->nullableOrderStatus($externalStatusInput),
                'local_status' => $this->nullableOrderStatus($localStatusInput),
                'currency' => $this->nullableCurrencyCode($payload['currency'] ?? null),
                'total' => $this->nullableAmount($payload['total'] ?? null),
                'sync_status' => 'linked',
                'metadata' => $metadata,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }
        if (!is_array($link)) {
            throw new RuntimeException('ECOMMERCE_ORDER_LINK_NOT_FOUND');
        }

        $link = $this->validatedOrderLink($link);
        $this->logOrderLinkEvent('link_order', $tenantId, $appId, $link, $store, $this->latencyMs($startedAt), [
            'link_id' => (string) ($link['id'] ?? ''),
            'external_order_id' => $externalOrderId,
            'local_reference_type' => (string) ($link['local_reference_type'] ?? ''),
            'local_reference_id' => (string) ($link['local_reference_id'] ?? ''),
            'sync_status' => (string) ($link['sync_status'] ?? 'linked'),
            'adapter_key' => $adapter->getPlatformKey(),
            'validation_result' => 'order_linked',
            'result_status' => 'success',
        ]);

        return $link;
    }

    /**
     * @return array<string, mixed>
     */
    public function getOrderLink(string $tenantId, string $linkId, ?string $appId = null): array
    {
        $startedAt = microtime(true);
        $link = $this->validatedOrderLink($this->loadOrderLink($tenantId, $linkId, $appId));
        $store = $this->loadStore($tenantId, (string) ($link['store_id'] ?? ''), $appId);
        $adapter = $this->resolveAdapter((string) ($store['platform'] ?? 'unknown'));

        $this->logOrderLinkEvent('get_order_link', $tenantId, $appId, $link, $store, $this->latencyMs($startedAt), [
            'link_id' => (string) ($link['id'] ?? ''),
            'external_order_id' => (string) ($link['external_order_id'] ?? ''),
            'local_reference_type' => (string) ($link['local_reference_type'] ?? ''),
            'local_reference_id' => (string) ($link['local_reference_id'] ?? ''),
            'sync_status' => (string) ($link['sync_status'] ?? ''),
            'adapter_key' => $adapter->getPlatformKey(),
            'validation_result' => 'order_link_loaded',
            'result_status' => 'success',
        ]);

        return $link;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function listOrderLinks(string $tenantId, string $storeId, array $filters = [], ?string $appId = null): array
    {
        $startedAt = microtime(true);
        $store = $this->loadStore($tenantId, $storeId, $appId);
        $adapter = $this->resolveAdapter((string) ($store['platform'] ?? 'unknown'));
        $items = array_map(
            fn(array $item): array => $this->validatedOrderLink($item),
            $this->repository->listOrderLinks($tenantId, $filters + ['store_id' => $storeId, 'app_id' => $appId], $this->limit($filters['limit'] ?? null, 20))
        );
        $selected = is_array($items[0] ?? null) ? (array) $items[0] : [];

        $this->logOrderLinkEvent('list_order_links', $tenantId, $appId, $selected, $store, $this->latencyMs($startedAt), [
            'link_id' => (string) ($selected['id'] ?? ''),
            'external_order_id' => (string) ($selected['external_order_id'] ?? ($filters['external_order_id'] ?? '')),
            'local_reference_type' => (string) ($selected['local_reference_type'] ?? ($filters['local_reference_type'] ?? '')),
            'local_reference_id' => (string) ($selected['local_reference_id'] ?? ($filters['local_reference_id'] ?? '')),
            'sync_status' => (string) ($selected['sync_status'] ?? ($filters['sync_status'] ?? '')),
            'adapter_key' => $adapter->getPlatformKey(),
            'validation_result' => 'list_result',
            'result_count' => count($items),
            'result_status' => 'success',
        ]);

        return $items;
    }

    /**
     * @param array<string, mixed> $externalOrderPayload
     * @return array<string, mixed>
     */
    public function normalizeExternalOrderPayload(string $tenantId, string $storeId, array $externalOrderPayload, ?string $appId = null): array
    {
        $startedAt = microtime(true);
        $store = $this->loadStore($tenantId, $storeId, $appId);
        $adapter = $this->resolveAdapter((string) ($store['platform'] ?? 'unknown'));
        $normalized = $adapter->normalizeExternalOrder($externalOrderPayload);
        $externalOrderId = $this->nullableString($normalized['external_order_id'] ?? ($externalOrderPayload['external_order_id'] ?? null));

        if (!$adapter->supportsOrderSync()) {
            $result = [
                'tenant_id' => $tenantId,
                'app_id' => $appId,
                'store_id' => $storeId,
                'platform' => (string) ($store['platform'] ?? 'unknown'),
                'adapter_key' => $adapter->getPlatformKey(),
                'normalized_external_order' => $normalized,
                'validation_result' => 'order_sync_not_supported',
                'result_status' => 'safe_failure',
            ];

            $this->logOrderSnapshotEvent('normalize_external_order', $tenantId, $appId, [
                'id' => '',
                'store_id' => $storeId,
                'external_order_id' => $externalOrderId ?? '',
            ], $store, $this->latencyMs($startedAt), [
                'link_id' => '',
                'external_order_id' => $externalOrderId ?? '',
                'local_reference_type' => '',
                'local_reference_id' => '',
                'sync_status' => '',
                'adapter_key' => $adapter->getPlatformKey(),
                'validation_result' => 'order_sync_not_supported',
                'result_status' => 'safe_failure',
            ]);

            return $result;
        }

        if ($externalOrderId === null) {
            throw new RuntimeException('ECOMMERCE_EXTERNAL_ORDER_ID_REQUIRED');
        }

        $existingLink = $this->repository->findOrderLinkByExternalOrder($tenantId, $storeId, $externalOrderId, $appId);
        $result = [
            'tenant_id' => $tenantId,
            'app_id' => $appId,
            'store_id' => $storeId,
            'platform' => (string) ($store['platform'] ?? 'unknown'),
            'adapter_key' => $adapter->getPlatformKey(),
            'external_order_id' => $externalOrderId,
            'supports_order_sync' => $adapter->supportsOrderSync(),
            'existing_link' => is_array($existingLink) ? $this->validatedOrderLink($existingLink) : null,
            'normalized_external_order' => $normalized,
            'validation_result' => 'external_order_normalized',
            'result_status' => 'success',
        ];

        $this->logOrderSnapshotEvent('normalize_external_order', $tenantId, $appId, [
            'id' => '',
            'store_id' => $storeId,
            'external_order_id' => $externalOrderId,
        ], $store, $this->latencyMs($startedAt), [
            'link_id' => is_array($existingLink) ? (string) ($existingLink['id'] ?? '') : '',
            'external_order_id' => $externalOrderId,
            'local_reference_type' => is_array($existingLink) ? (string) ($existingLink['local_reference_type'] ?? '') : '',
            'local_reference_id' => is_array($existingLink) ? (string) ($existingLink['local_reference_id'] ?? '') : '',
            'sync_status' => is_array($existingLink) ? (string) ($existingLink['sync_status'] ?? '') : '',
            'adapter_key' => $adapter->getPlatformKey(),
            'validation_result' => 'external_order_normalized',
            'result_status' => 'success',
        ]);

        return $result;
    }

    /**
     * @param array<string, mixed> $externalOrderPayload
     * @return array<string, mixed>
     */
    public function registerOrderPullSnapshot(string $tenantId, string $storeId, array $externalOrderPayload, ?string $appId = null): array
    {
        $startedAt = microtime(true);
        $store = $this->loadStore($tenantId, $storeId, $appId);
        $adapter = $this->resolveAdapter((string) ($store['platform'] ?? 'unknown'));
        $normalized = $adapter->normalizeExternalOrder($externalOrderPayload);
        $externalOrderId = $this->nullableString($normalized['external_order_id'] ?? ($externalOrderPayload['external_order_id'] ?? null));

        if (!$adapter->supportsOrderSync()) {
            $result = [
                'tenant_id' => $tenantId,
                'app_id' => $appId,
                'store_id' => $storeId,
                'platform' => (string) ($store['platform'] ?? 'unknown'),
                'adapter_key' => $adapter->getPlatformKey(),
                'normalized_external_order' => $normalized,
                'validation_result' => 'order_sync_not_supported',
                'result_status' => 'safe_failure',
            ];

            $this->logOrderSnapshotEvent('register_order_pull_snapshot', $tenantId, $appId, [
                'id' => '',
                'store_id' => $storeId,
                'external_order_id' => $externalOrderId ?? '',
            ], $store, $this->latencyMs($startedAt), [
                'link_id' => '',
                'external_order_id' => $externalOrderId ?? '',
                'local_reference_type' => '',
                'local_reference_id' => '',
                'sync_status' => '',
                'adapter_key' => $adapter->getPlatformKey(),
                'validation_result' => 'order_sync_not_supported',
                'result_status' => 'safe_failure',
            ]);

            return $result;
        }

        if ($externalOrderId === null) {
            throw new RuntimeException('ECOMMERCE_EXTERNAL_ORDER_ID_REQUIRED');
        }

        $capturedAt = date('Y-m-d H:i:s');
        $existing = $this->repository->findOrderLinkByExternalOrder($tenantId, $storeId, $externalOrderId, $appId);
        $normalizedStatus = $this->orderStatus($normalized['normalized_status'] ?? 'unknown');
        $snapshot = $this->repository->createOrderSnapshot([
            'tenant_id' => $tenantId,
            'app_id' => $appId,
            'store_id' => $storeId,
            'external_order_id' => $externalOrderId,
            'snapshot_payload' => $externalOrderPayload,
            'normalized_payload' => $normalized,
            'captured_at' => $capturedAt,
            'metadata' => [
                'adapter_key' => $adapter->getPlatformKey(),
                'sync_direction' => 'pull_store_to_local',
            ],
        ]);

        $linkMetadata = [
            'adapter_key' => $adapter->getPlatformKey(),
            'last_pull_snapshot_id' => (string) ($snapshot['id'] ?? ''),
            'last_pull_snapshot' => $normalized,
            'external_status_raw' => $this->nullableString($normalized['external_status'] ?? null),
            'future_hooks' => $this->futureOrderHooks(),
        ];
        if (is_array($existing)) {
            $link = $this->repository->updateOrderLink($tenantId, (string) ($existing['id'] ?? ''), [
                'external_status' => $normalizedStatus,
                'currency' => $this->nullableCurrencyCode($normalized['currency'] ?? null),
                'total' => $this->nullableAmount($normalized['total'] ?? null),
                'sync_status' => 'snapshot_received',
                'last_sync_at' => $capturedAt,
                'metadata' => $this->mergeMetadata(
                    is_array($existing['metadata'] ?? null) ? (array) $existing['metadata'] : [],
                    $linkMetadata
                ),
            ], $appId);
        } else {
            $link = $this->repository->createOrderLink([
                'tenant_id' => $tenantId,
                'app_id' => $appId,
                'store_id' => $storeId,
                'external_order_id' => $externalOrderId,
                'external_status' => $normalizedStatus,
                'currency' => $this->nullableCurrencyCode($normalized['currency'] ?? null),
                'total' => $this->nullableAmount($normalized['total'] ?? null),
                'sync_status' => 'snapshot_received',
                'last_sync_at' => $capturedAt,
                'metadata' => $linkMetadata,
                'created_at' => $capturedAt,
            ]);
        }
        if (!is_array($link)) {
            throw new RuntimeException('ECOMMERCE_ORDER_LINK_NOT_FOUND');
        }

        $link = $this->validatedOrderLink($link);
        $snapshot = $this->validatedOrderSnapshot($snapshot);
        $this->logOrderSnapshotEvent('register_order_pull_snapshot', $tenantId, $appId, $snapshot, $store, $this->latencyMs($startedAt), [
            'link_id' => (string) ($link['id'] ?? ''),
            'external_order_id' => $externalOrderId,
            'local_reference_type' => (string) ($link['local_reference_type'] ?? ''),
            'local_reference_id' => (string) ($link['local_reference_id'] ?? ''),
            'sync_status' => (string) ($link['sync_status'] ?? ''),
            'adapter_key' => $adapter->getPlatformKey(),
            'validation_result' => 'pull_snapshot_registered',
            'result_status' => 'success',
        ]);

        return [
            'tenant_id' => $tenantId,
            'app_id' => $appId,
            'store_id' => $storeId,
            'platform' => (string) ($store['platform'] ?? 'unknown'),
            'adapter_key' => $adapter->getPlatformKey(),
            'external_order_id' => $externalOrderId,
            'normalized_external_order' => $normalized,
            'snapshot' => $snapshot,
            'link' => $link,
            'validation_result' => 'pull_snapshot_registered',
            'result_status' => 'success',
        ];
    }

    /**
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    public function markOrderSyncStatus(string $tenantId, string $linkId, string $syncStatus, array $metadata = [], ?string $appId = null): array
    {
        $startedAt = microtime(true);
        $link = $this->loadOrderLink($tenantId, $linkId, $appId);
        $store = $this->loadStore($tenantId, (string) ($link['store_id'] ?? ''), $appId);
        $adapter = $this->resolveAdapter((string) ($store['platform'] ?? 'unknown'));
        $updates = [
            'sync_status' => $this->orderSyncStatus($syncStatus),
            'last_sync_at' => date('Y-m-d H:i:s'),
            'metadata' => $this->mergeMetadata(
                is_array($link['metadata'] ?? null) ? (array) $link['metadata'] : [],
                $metadata
            ),
        ];

        $externalStatusInput = $this->nullableString($metadata['external_status'] ?? null);
        if ($externalStatusInput !== null) {
            $updates['external_status'] = $this->orderStatus($externalStatusInput);
            $updates['metadata'] = $this->mergeMetadata((array) $updates['metadata'], ['external_status_raw' => $externalStatusInput]);
        }

        $localStatusInput = $this->nullableString($metadata['local_status'] ?? null);
        if ($localStatusInput !== null) {
            $updates['local_status'] = $this->orderStatus($localStatusInput);
        }

        $updated = $this->repository->updateOrderLink($tenantId, $linkId, $updates, $appId);
        if (!is_array($updated)) {
            throw new RuntimeException('ECOMMERCE_ORDER_LINK_NOT_FOUND');
        }

        $updated = $this->validatedOrderLink($updated);
        $this->logOrderLinkEvent('mark_order_sync_status', $tenantId, $appId, $updated, $store, $this->latencyMs($startedAt), [
            'link_id' => (string) ($updated['id'] ?? ''),
            'external_order_id' => (string) ($updated['external_order_id'] ?? ''),
            'local_reference_type' => (string) ($updated['local_reference_type'] ?? ''),
            'local_reference_id' => (string) ($updated['local_reference_id'] ?? ''),
            'sync_status' => (string) ($updated['sync_status'] ?? ''),
            'adapter_key' => $adapter->getPlatformKey(),
            'validation_result' => 'sync_status_recorded',
            'result_status' => 'success',
        ]);

        return $updated;
    }

    /**
     * @return array<string, mixed>
     */
    public function getOrderSnapshot(string $tenantId, string $storeId, string $externalOrderId, ?string $appId = null): array
    {
        $startedAt = microtime(true);
        $store = $this->loadStore($tenantId, $storeId, $appId);
        $adapter = $this->resolveAdapter((string) ($store['platform'] ?? 'unknown'));
        $snapshot = $this->validatedOrderSnapshot($this->loadOrderSnapshot($tenantId, $storeId, $externalOrderId, $appId));
        $link = $this->repository->findOrderLinkByExternalOrder($tenantId, $storeId, $externalOrderId, $appId);

        $this->logOrderSnapshotEvent('get_order_snapshot', $tenantId, $appId, $snapshot, $store, $this->latencyMs($startedAt), [
            'link_id' => is_array($link) ? (string) ($link['id'] ?? '') : '',
            'external_order_id' => $externalOrderId,
            'local_reference_type' => is_array($link) ? (string) ($link['local_reference_type'] ?? '') : '',
            'local_reference_id' => is_array($link) ? (string) ($link['local_reference_id'] ?? '') : '',
            'sync_status' => is_array($link) ? (string) ($link['sync_status'] ?? '') : '',
            'adapter_key' => $adapter->getPlatformKey(),
            'validation_result' => 'snapshot_loaded',
            'result_status' => 'success',
        ]);

        return $snapshot;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function linkProduct(array $payload): array
    {
        $startedAt = microtime(true);
        $tenantId = $this->requireString($payload['tenant_id'] ?? null, 'tenant_id');
        $storeId = $this->requireString($payload['store_id'] ?? null, 'store_id');
        $localProductReference = $this->requireString($payload['local_product_id'] ?? null, 'local_product_id');
        $externalProductId = $this->requireString($payload['external_product_id'] ?? null, 'external_product_id');
        $appId = $this->nullableString($payload['app_id'] ?? $payload['project_id'] ?? null);
        $store = $this->loadStore($tenantId, $storeId, $appId);
        $adapter = $this->resolveAdapter((string) ($store['platform'] ?? 'unknown'));
        $localProduct = $this->resolveLocalProductReference($tenantId, $localProductReference, $appId);
        $localProductId = (string) ($localProduct['local_product_id'] ?? '');
        $inputMetadata = is_array($payload['metadata'] ?? null) ? (array) $payload['metadata'] : [];
        $externalSku = $this->nullableString($payload['external_sku'] ?? ($inputMetadata['external_sku'] ?? null));
        $existingLocal = $this->repository->findProductLinkByLocalProduct($tenantId, $storeId, $localProductId, $appId);
        $existingExternal = $this->repository->findProductLinkByExternalProduct($tenantId, $storeId, $externalProductId, $appId);

        if (
            is_array($existingLocal)
            && is_array($existingExternal)
            && (string) ($existingLocal['id'] ?? '') !== (string) ($existingExternal['id'] ?? '')
        ) {
            throw new RuntimeException('ECOMMERCE_PRODUCT_LINK_CONFLICT');
        }

        $metadata = $this->mergeMetadata(
            $inputMetadata,
            [
                'local_product' => $localProduct,
                'link_origin' => 'foundation_manual',
                'adapter_key' => $adapter->getPlatformKey(),
            ]
        );

        $candidate = is_array($existingExternal) ? $existingExternal : $existingLocal;
        if (is_array($candidate)) {
            $link = $this->repository->updateProductLink($tenantId, (string) ($candidate['id'] ?? ''), [
                'store_id' => $storeId,
                'local_product_id' => $localProductId,
                'external_product_id' => $externalProductId,
                'external_sku' => $externalSku,
                'sync_status' => 'linked',
                'metadata' => $this->mergeMetadata(
                    is_array($candidate['metadata'] ?? null) ? (array) $candidate['metadata'] : [],
                    $metadata
                ),
            ], $appId);
        } else {
            $link = $this->repository->createProductLink([
                'tenant_id' => $tenantId,
                'app_id' => $appId,
                'store_id' => $storeId,
                'local_product_id' => $localProductId,
                'external_product_id' => $externalProductId,
                'external_sku' => $externalSku,
                'sync_status' => 'linked',
                'metadata' => $metadata,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }
        if (!is_array($link)) {
            throw new RuntimeException('ECOMMERCE_PRODUCT_LINK_NOT_FOUND');
        }

        $link = $this->validatedProductLink($link);
        $this->logProductLinkEvent('link_product', $tenantId, $appId, $link, $store, $this->latencyMs($startedAt), [
            'link_id' => (string) ($link['id'] ?? ''),
            'local_product_id' => $localProductId,
            'external_product_id' => $externalProductId,
            'sync_status' => (string) ($link['sync_status'] ?? 'linked'),
            'sync_direction' => '',
            'adapter_key' => $adapter->getPlatformKey(),
            'validation_result' => 'product_linked',
            'result_status' => 'success',
        ]);

        return $link;
    }

    /**
     * @return array<string, mixed>
     */
    public function unlinkProduct(string $tenantId, string $linkId, ?string $appId = null): array
    {
        $startedAt = microtime(true);
        $link = $this->loadProductLink($tenantId, $linkId, $appId);
        $store = $this->loadStore($tenantId, (string) ($link['store_id'] ?? ''), $appId);
        $adapter = $this->resolveAdapter((string) ($store['platform'] ?? 'unknown'));
        $deleted = $this->repository->deleteProductLink($tenantId, $linkId, $appId);
        if ($deleted < 1) {
            throw new RuntimeException('ECOMMERCE_PRODUCT_LINK_NOT_FOUND');
        }

        $result = $this->validatedProductLink($link);
        $result['deleted'] = true;

        $this->logProductLinkEvent('unlink_product', $tenantId, $appId, $result, $store, $this->latencyMs($startedAt), [
            'link_id' => (string) ($result['id'] ?? ''),
            'local_product_id' => (string) ($result['local_product_id'] ?? ''),
            'external_product_id' => (string) ($result['external_product_id'] ?? ''),
            'sync_status' => (string) ($result['sync_status'] ?? 'linked'),
            'sync_direction' => '',
            'adapter_key' => $adapter->getPlatformKey(),
            'validation_result' => 'product_unlinked',
            'result_status' => 'success',
        ]);

        return $result;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function listProductLinks(string $tenantId, string $storeId, array $filters = [], ?string $appId = null): array
    {
        $startedAt = microtime(true);
        $store = $this->loadStore($tenantId, $storeId, $appId);
        $adapter = $this->resolveAdapter((string) ($store['platform'] ?? 'unknown'));
        $items = array_map(
            fn(array $item): array => $this->validatedProductLink($item),
            $this->repository->listProductLinks($tenantId, $filters + ['store_id' => $storeId, 'app_id' => $appId], $this->limit($filters['limit'] ?? null, 20))
        );
        $selected = is_array($items[0] ?? null) ? (array) $items[0] : [];

        $this->logProductLinkEvent('list_product_links', $tenantId, $appId, $selected, $store, $this->latencyMs($startedAt), [
            'link_id' => (string) ($selected['id'] ?? ''),
            'local_product_id' => (string) ($selected['local_product_id'] ?? ($filters['local_product_id'] ?? '')),
            'external_product_id' => (string) ($selected['external_product_id'] ?? ($filters['external_product_id'] ?? '')),
            'sync_status' => (string) ($selected['sync_status'] ?? ($filters['sync_status'] ?? '')),
            'sync_direction' => (string) ($selected['last_sync_direction'] ?? ($filters['last_sync_direction'] ?? '')),
            'adapter_key' => $adapter->getPlatformKey(),
            'validation_result' => 'list_result',
            'result_count' => count($items),
            'result_status' => 'success',
        ]);

        return $items;
    }

    /**
     * @return array<string, mixed>
     */
    public function getProductLink(string $tenantId, string $linkId, ?string $appId = null): array
    {
        $startedAt = microtime(true);
        $link = $this->validatedProductLink($this->loadProductLink($tenantId, $linkId, $appId));
        $store = $this->loadStore($tenantId, (string) ($link['store_id'] ?? ''), $appId);
        $adapter = $this->resolveAdapter((string) ($store['platform'] ?? 'unknown'));

        $this->logProductLinkEvent('get_product_link', $tenantId, $appId, $link, $store, $this->latencyMs($startedAt), [
            'link_id' => (string) ($link['id'] ?? ''),
            'local_product_id' => (string) ($link['local_product_id'] ?? ''),
            'external_product_id' => (string) ($link['external_product_id'] ?? ''),
            'sync_status' => (string) ($link['sync_status'] ?? ''),
            'sync_direction' => (string) ($link['last_sync_direction'] ?? ''),
            'adapter_key' => $adapter->getPlatformKey(),
            'validation_result' => 'product_link_loaded',
            'result_status' => 'success',
        ]);

        return $link;
    }

    /**
     * @return array<string, mixed>
     */
    public function prepareProductPushPayload(string $tenantId, string $storeId, string $localProductId, ?string $appId = null): array
    {
        $startedAt = microtime(true);
        $store = $this->loadStore($tenantId, $storeId, $appId);
        $adapter = $this->resolveAdapter((string) ($store['platform'] ?? 'unknown'));
        $localProduct = $this->resolveLocalProductReference($tenantId, $localProductId, $appId);
        $existingLink = $this->repository->findProductLinkByLocalProduct($tenantId, $storeId, (string) ($localProduct['local_product_id'] ?? ''), $appId);

        $resultStatus = $adapter->supportsProductSync() ? 'success' : 'safe_failure';
        $validationResult = $adapter->supportsProductSync() ? 'payload_ready' : 'product_sync_not_supported';
        $payload = $adapter->buildProductPayload($this->mergeMetadata(
            $localProduct,
            [
                'external_product_id' => is_array($existingLink) ? ($existingLink['external_product_id'] ?? null) : null,
                'external_sku' => is_array($existingLink) ? ($existingLink['external_sku'] ?? null) : null,
            ]
        ));

        $result = [
            'tenant_id' => $tenantId,
            'app_id' => $appId,
            'store_id' => $storeId,
            'platform' => (string) ($store['platform'] ?? 'unknown'),
            'adapter_key' => $adapter->getPlatformKey(),
            'local_product_id' => (string) ($localProduct['local_product_id'] ?? ''),
            'sync_direction' => 'push_local_to_store',
            'supports_product_sync' => $adapter->supportsProductSync(),
            'existing_link' => is_array($existingLink) ? $this->validatedProductLink($existingLink) : null,
            'local_product' => $localProduct,
            'adapter_payload' => $payload,
            'validation_result' => $validationResult,
            'result_status' => $resultStatus,
        ];

        $this->logProductLinkEvent('prepare_product_push_payload', $tenantId, $appId, is_array($existingLink) ? $existingLink : [
            'id' => '',
            'store_id' => $storeId,
            'local_product_id' => (string) ($localProduct['local_product_id'] ?? ''),
            'external_product_id' => '',
            'sync_status' => '',
            'last_sync_direction' => 'push_local_to_store',
        ], $store, $this->latencyMs($startedAt), [
            'link_id' => is_array($existingLink) ? (string) ($existingLink['id'] ?? '') : '',
            'local_product_id' => (string) ($localProduct['local_product_id'] ?? ''),
            'external_product_id' => is_array($existingLink) ? (string) ($existingLink['external_product_id'] ?? '') : '',
            'sync_status' => is_array($existingLink) ? (string) ($existingLink['sync_status'] ?? '') : '',
            'sync_direction' => 'push_local_to_store',
            'adapter_key' => $adapter->getPlatformKey(),
            'validation_result' => $validationResult,
            'result_status' => $resultStatus,
        ]);

        return $result;
    }

    /**
     * @param array<string, mixed> $externalProductPayload
     * @return array<string, mixed>
     */
    public function registerProductPullSnapshot(string $tenantId, string $storeId, array $externalProductPayload, ?string $appId = null): array
    {
        $startedAt = microtime(true);
        $store = $this->loadStore($tenantId, $storeId, $appId);
        $adapter = $this->resolveAdapter((string) ($store['platform'] ?? 'unknown'));
        $normalized = $adapter->normalizeExternalProduct($externalProductPayload);
        if (!$adapter->supportsProductSync()) {
            $result = [
                'tenant_id' => $tenantId,
                'app_id' => $appId,
                'store_id' => $storeId,
                'platform' => (string) ($store['platform'] ?? 'unknown'),
                'adapter_key' => $adapter->getPlatformKey(),
                'sync_direction' => 'pull_store_to_local',
                'normalized_external_product' => $normalized,
                'validation_result' => 'product_sync_not_supported',
                'result_status' => 'safe_failure',
            ];

            $this->logProductLinkEvent('register_product_pull_snapshot', $tenantId, $appId, [
                'id' => '',
                'store_id' => $storeId,
                'local_product_id' => '',
                'external_product_id' => '',
                'sync_status' => '',
                'last_sync_direction' => 'pull_store_to_local',
            ], $store, $this->latencyMs($startedAt), [
                'link_id' => '',
                'local_product_id' => '',
                'external_product_id' => '',
                'sync_status' => '',
                'sync_direction' => 'pull_store_to_local',
                'adapter_key' => $adapter->getPlatformKey(),
                'validation_result' => 'product_sync_not_supported',
                'result_status' => 'safe_failure',
            ]);

            return $result;
        }

        $externalProductId = $this->nullableString($normalized['external_product_id'] ?? null);
        if ($externalProductId === null) {
            throw new RuntimeException('ECOMMERCE_EXTERNAL_PRODUCT_ID_REQUIRED');
        }

        $existing = $this->repository->findProductLinkByExternalProduct($tenantId, $storeId, $externalProductId, $appId);
        if (is_array($existing)) {
            $link = $this->repository->updateProductLink($tenantId, (string) ($existing['id'] ?? ''), [
                'external_sku' => $this->nullableString($normalized['external_sku'] ?? null),
                'sync_status' => 'snapshot_received',
                'last_sync_at' => date('Y-m-d H:i:s'),
                'last_sync_direction' => 'pull_store_to_local',
                'metadata' => $this->mergeMetadata(
                    is_array($existing['metadata'] ?? null) ? (array) $existing['metadata'] : [],
                    [
                        'last_pull_snapshot' => $normalized,
                        'adapter_key' => $adapter->getPlatformKey(),
                    ]
                ),
            ], $appId);
        } else {
            $link = $this->repository->createProductLink([
                'tenant_id' => $tenantId,
                'app_id' => $appId,
                'store_id' => $storeId,
                'local_product_id' => null,
                'external_product_id' => $externalProductId,
                'external_sku' => $this->nullableString($normalized['external_sku'] ?? null),
                'sync_status' => 'snapshot_received',
                'last_sync_at' => date('Y-m-d H:i:s'),
                'last_sync_direction' => 'pull_store_to_local',
                'metadata' => [
                    'last_pull_snapshot' => $normalized,
                    'adapter_key' => $adapter->getPlatformKey(),
                ],
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }
        if (!is_array($link)) {
            throw new RuntimeException('ECOMMERCE_PRODUCT_LINK_NOT_FOUND');
        }

        $link = $this->validatedProductLink($link);
        $this->logProductLinkEvent('register_product_pull_snapshot', $tenantId, $appId, $link, $store, $this->latencyMs($startedAt), [
            'link_id' => (string) ($link['id'] ?? ''),
            'local_product_id' => (string) ($link['local_product_id'] ?? ''),
            'external_product_id' => (string) ($link['external_product_id'] ?? ''),
            'sync_status' => (string) ($link['sync_status'] ?? ''),
            'sync_direction' => 'pull_store_to_local',
            'adapter_key' => $adapter->getPlatformKey(),
            'validation_result' => 'pull_snapshot_registered',
            'result_status' => 'success',
        ]);

        return [
            'tenant_id' => $tenantId,
            'app_id' => $appId,
            'store_id' => $storeId,
            'platform' => (string) ($store['platform'] ?? 'unknown'),
            'adapter_key' => $adapter->getPlatformKey(),
            'sync_direction' => 'pull_store_to_local',
            'normalized_external_product' => $normalized,
            'link' => $link,
            'validation_result' => 'pull_snapshot_registered',
            'result_status' => 'success',
        ];
    }

    /**
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    public function markProductSyncStatus(string $tenantId, string $linkId, string $syncStatus, array $metadata = [], ?string $appId = null): array
    {
        $startedAt = microtime(true);
        $link = $this->loadProductLink($tenantId, $linkId, $appId);
        $store = $this->loadStore($tenantId, (string) ($link['store_id'] ?? ''), $appId);
        $adapter = $this->resolveAdapter((string) ($store['platform'] ?? 'unknown'));
        $direction = $this->nullableSyncDirection($metadata['sync_direction'] ?? null);

        $updated = $this->repository->updateProductLink($tenantId, $linkId, [
            'sync_status' => $this->productSyncStatus($syncStatus),
            'last_sync_at' => date('Y-m-d H:i:s'),
            'last_sync_direction' => $direction ?? ($link['last_sync_direction'] ?? null),
            'metadata' => $this->mergeMetadata(
                is_array($link['metadata'] ?? null) ? (array) $link['metadata'] : [],
                $metadata
            ),
        ], $appId);
        if (!is_array($updated)) {
            throw new RuntimeException('ECOMMERCE_PRODUCT_LINK_NOT_FOUND');
        }

        $updated = $this->validatedProductLink($updated);
        $this->logProductLinkEvent('mark_product_sync_status', $tenantId, $appId, $updated, $store, $this->latencyMs($startedAt), [
            'link_id' => (string) ($updated['id'] ?? ''),
            'local_product_id' => (string) ($updated['local_product_id'] ?? ''),
            'external_product_id' => (string) ($updated['external_product_id'] ?? ''),
            'sync_status' => (string) ($updated['sync_status'] ?? ''),
            'sync_direction' => (string) ($updated['last_sync_direction'] ?? ''),
            'adapter_key' => $adapter->getPlatformKey(),
            'validation_result' => 'sync_status_recorded',
            'result_status' => 'success',
        ]);

        return $updated;
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
     * @return array<string, mixed>
     */
    private function loadProductLink(string $tenantId, string $linkId, ?string $appId = null): array
    {
        $link = $this->repository->findProductLink($tenantId, $linkId, $appId);
        if (!is_array($link)) {
            throw new RuntimeException('ECOMMERCE_PRODUCT_LINK_NOT_FOUND');
        }

        return $link;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadOrderLink(string $tenantId, string $linkId, ?string $appId = null): array
    {
        $link = $this->repository->findOrderLink($tenantId, $linkId, $appId);
        if (!is_array($link)) {
            throw new RuntimeException('ECOMMERCE_ORDER_LINK_NOT_FOUND');
        }

        return $link;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadOrderSnapshot(string $tenantId, string $storeId, string $externalOrderId, ?string $appId = null): array
    {
        $snapshot = $this->repository->findLatestOrderSnapshot($tenantId, $storeId, $externalOrderId, $appId);
        if (!is_array($snapshot)) {
            throw new RuntimeException('ECOMMERCE_ORDER_SNAPSHOT_NOT_FOUND');
        }

        return $snapshot;
    }

    /**
     * @param array<string, mixed> $inputMetadata
     * @param array<string, mixed> $existingStore
     * @return array<string, mixed>
     */
    private function buildStoreMetadata(array $inputMetadata, array $existingStore, ?string $platformOverride = null): array
    {
        $platform = (string) ($platformOverride ?? $existingStore['platform'] ?? $inputMetadata['platform'] ?? 'unknown');
        $adapter = $this->resolveAdapter($platform);
        $adapterMetadata = $adapter->getStoreMetadata(
            $existingStore + $inputMetadata + ['platform' => $platform]
        );

        return $this->mergeMetadata(
            is_array($existingStore['metadata'] ?? null) ? (array) $existingStore['metadata'] : [],
            $inputMetadata,
            [
                'hooks' => $this->futureHooks($adapter),
                'adapter_state' => [
                    'provider_runtime' => $adapter->getPlatformKey() === 'unknown' ? 'fallback' : 'foundation_ready',
                    'adapter_key' => $adapter->getPlatformKey(),
                    'product_sync' => $adapter->supportsProductSync() ? 'foundation_ready' : 'pending',
                    'order_sync' => $adapter->supportsOrderSync() ? 'foundation_ready' : 'pending',
                    'inventory_sync' => 'pending',
                    'fiscal_linkage' => 'pending',
                ],
                'adapter_foundation' => [
                    'adapter_key' => $adapter->getPlatformKey(),
                    'platform_label' => (string) ($adapterMetadata['platform_label'] ?? ucfirst($platform)),
                    'capabilities' => $adapter->listCapabilities(),
                    'remote_ping_supported' => (bool) ($adapterMetadata['remote_ping_supported'] ?? false),
                    'scope' => 'foundation',
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
    private function decryptCredentialPayload(string $encryptedPayload): array
    {
        $package = base64_decode($encryptedPayload, true);
        if (!is_string($package) || $package === '') {
            throw new RuntimeException('ECOMMERCE_CREDENTIAL_DECRYPT_FAILED');
        }

        $decoded = json_decode($package, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('ECOMMERCE_CREDENTIAL_DECRYPT_FAILED');
        }

        $cipher = (string) ($decoded['cipher'] ?? '');
        $iv = base64_decode((string) ($decoded['iv'] ?? ''), true);
        $payload = base64_decode((string) ($decoded['payload'] ?? ''), true);
        if ($cipher === '' || !is_string($iv) || $iv === '' || !is_string($payload) || $payload === '') {
            throw new RuntimeException('ECOMMERCE_CREDENTIAL_DECRYPT_FAILED');
        }

        $json = openssl_decrypt($payload, $cipher, $this->encryptionKey(), OPENSSL_RAW_DATA, $iv);
        if (!is_string($json) || $json === '') {
            throw new RuntimeException('ECOMMERCE_CREDENTIAL_DECRYPT_FAILED');
        }

        $decodedPayload = json_decode($json, true);
        if (!is_array($decodedPayload)) {
            throw new RuntimeException('ECOMMERCE_CREDENTIAL_DECRYPT_FAILED');
        }

        return $decodedPayload;
    }

    /**
     * @param array<int, array<string, mixed>> $credentials
     * @return array<string, mixed>
     */
    private function summarizeCredentials(array $credentials): array
    {
        $latest = is_array($credentials[0] ?? null) ? (array) $credentials[0] : [];
        $metadata = is_array($latest['metadata'] ?? null) ? (array) $latest['metadata'] : [];

        return [
            'configured' => $credentials !== [],
            'count' => count($credentials),
            'types' => array_values(array_unique(array_filter(array_map(
                fn(array $credential): string => trim((string) ($credential['credential_type'] ?? '')),
                $credentials
            )))),
            'latest_credential_id' => trim((string) ($latest['id'] ?? '')),
            'latest_credential_type' => trim((string) ($latest['credential_type'] ?? '')),
            'latest_last_validated_at' => $this->nullableString($latest['last_validated_at'] ?? null),
            'latest_masked_payload' => is_array($metadata['masked_payload'] ?? null) ? (array) $metadata['masked_payload'] : [],
        ];
    }

    private function resolveAdapter(string $platform): EcommerceAdapterInterface
    {
        return $this->adapterResolver->resolve($platform);
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveLocalProductReference(string $tenantId, string $localProductReference, ?string $appId = null): array
    {
        $resolved = $this->entitySearchService->getByReference(
            $tenantId,
            'product',
            $localProductReference,
            ['app_id' => $appId],
            $appId
        );
        if (!is_array($resolved)) {
            throw new RuntimeException('ECOMMERCE_LOCAL_PRODUCT_NOT_FOUND');
        }

        $metadata = is_array($resolved['metadata_json'] ?? null) ? (array) $resolved['metadata_json'] : [];

        return [
            'local_product_id' => (string) ($resolved['entity_id'] ?? ''),
            'entity_type' => 'product',
            'label' => trim((string) ($resolved['label'] ?? '')),
            'subtitle' => $this->nullableString($resolved['subtitle'] ?? null),
            'reference' => $this->nullableString($metadata['raw_identifier'] ?? null),
            'sku' => $this->nullableString($metadata['raw_identifier'] ?? null),
            'status' => $this->nullableString($metadata['status'] ?? null),
            'source_module' => trim((string) ($resolved['source_module'] ?? '')),
            'entity_contract' => $this->nullableString($metadata['entity_contract'] ?? null),
            'matched_by' => trim((string) ($resolved['matched_by'] ?? '')),
            'score' => is_numeric($resolved['score'] ?? null) ? (float) $resolved['score'] : 0.0,
        ];
    }

    /**
     * @param array<string, mixed> $store
     * @param array<int, array<string, mixed>> $credentials
     * @return array<string, mixed>
     */
    private function evaluateCredentialValidation(array $store, array $credentials, EcommerceAdapterInterface $adapter): array
    {
        $latest = is_array($credentials[0] ?? null) ? (array) $credentials[0] : [];
        if ($latest === []) {
            return $adapter->validateCredentials($store, []);
        }

        try {
            $decrypted = $this->decryptCredentialPayload((string) ($latest['encrypted_payload'] ?? ''));
        } catch (RuntimeException $e) {
            $metadata = is_array($latest['metadata'] ?? null) ? (array) $latest['metadata'] : [];
            return [
                'platform' => (string) ($store['platform'] ?? 'unknown'),
                'adapter_key' => $adapter->getPlatformKey(),
                'valid' => false,
                'validation_result' => 'credential_decrypt_failed',
                'connection_status' => 'failed',
                'required_credentials' => [],
                'required_store_fields' => [],
                'missing_credentials' => [],
                'missing_store_fields' => [],
                'remote_ping_supported' => false,
                'capabilities' => $adapter->listCapabilities(),
                'masked_credentials' => is_array($metadata['masked_payload'] ?? null) ? (array) $metadata['masked_payload'] : [],
            ];
        }

        $validation = $adapter->validateCredentials($store, $decrypted);
        $validation['masked_credentials'] = $this->maskSensitivePayload($decrypted);

        return $validation;
    }

    /**
     * @param array<string, mixed> $store
     * @param array<int, array<string, mixed>> $credentials
     * @return array<string, mixed>
     */
    private function evaluatePing(array $store, array $credentials, EcommerceAdapterInterface $adapter): array
    {
        $latest = is_array($credentials[0] ?? null) ? (array) $credentials[0] : [];
        if ($latest === []) {
            return $adapter->ping($store, []);
        }

        try {
            $decrypted = $this->decryptCredentialPayload((string) ($latest['encrypted_payload'] ?? ''));
        } catch (RuntimeException $e) {
            return [
                'platform' => (string) ($store['platform'] ?? 'unknown'),
                'adapter_key' => $adapter->getPlatformKey(),
                'ping_attempted' => false,
                'reachable' => false,
                'validation_result' => 'credential_decrypt_failed',
                'connection_status' => 'failed',
                'result_status' => 'failed',
                'checked_endpoint' => null,
                'message' => 'Stored credentials could not be decrypted safely.',
            ];
        }

        return $adapter->ping($store, $decrypted);
    }

    private function productSyncStatus($value): string
    {
        $value = strtolower(trim((string) $value));
        if (!in_array($value, self::PRODUCT_SYNC_STATUSES, true)) {
            throw new RuntimeException('ECOMMERCE_PRODUCT_SYNC_STATUS_INVALID');
        }

        return $value;
    }

    private function orderSyncStatus($value): string
    {
        $value = strtolower(trim((string) $value));
        if (!in_array($value, self::ORDER_SYNC_STATUSES, true)) {
            throw new RuntimeException('ECOMMERCE_ORDER_SYNC_STATUS_INVALID');
        }

        return $value;
    }

    private function orderStatus($value): string
    {
        $value = strtolower(trim((string) $value));
        $value = match ($value) {
            'on-hold', 'awaiting_payment', 'unpaid', 'payment_pending', 'held' => 'pending',
            'authorized' => 'paid',
            'in_progress', 'confirmed', 'preparing' => 'processing',
            'delivered', 'shipped', 'fulfilled' => 'completed',
            'cancelled', 'void', 'annulled' => 'canceled',
            'partial_refund', 'partially_refunded', 'returned' => 'refunded',
            default => $value,
        };
        if (!in_array($value, self::ORDER_STATUSES, true)) {
            throw new RuntimeException('ECOMMERCE_ORDER_STATUS_INVALID');
        }

        return $value;
    }

    private function nullableOrderStatus($value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return $this->orderStatus($value);
    }

    private function syncDirection($value): string
    {
        $value = strtolower(trim((string) $value));
        if (!in_array($value, self::SYNC_DIRECTIONS, true)) {
            throw new RuntimeException('ECOMMERCE_SYNC_DIRECTION_INVALID');
        }

        return $value;
    }

    private function nullableSyncDirection($value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return $this->syncDirection($value);
    }

    private function futureHooks(?EcommerceAdapterInterface $adapter = null): array
    {
        $adapterKey = $adapter?->getPlatformKey() ?? 'unknown';

        return [
            'woocommerce_adapter' => $adapterKey === 'woocommerce' ? 'foundation_ready' : 'pending',
            'tiendanube_adapter' => $adapterKey === 'tiendanube' ? 'foundation_ready' : 'pending',
            'prestashop_adapter' => $adapterKey === 'prestashop' ? 'foundation_ready' : 'pending',
            'product_sync' => $adapter?->supportsProductSync() === true ? 'foundation_ready' : 'pending',
            'order_sync' => $adapter?->supportsOrderSync() === true ? 'foundation_ready' : 'pending',
            'inventory_sync' => 'pending',
            'fiscal_order_linkage' => 'pending',
            'internal_order_ingest' => 'pending',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function futureOrderHooks(): array
    {
        return [
            'local_sale_creation' => 'pending',
            'fiscal_document_linkage' => 'pending',
            'inventory_movement' => 'pending',
            'customer_resolution' => 'pending',
            'payment_reconciliation' => 'pending',
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

    private function nullableCurrencyCode($value): ?string
    {
        $currency = $this->nullableString($value);

        return $currency !== null ? strtoupper($currency) : null;
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
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function validatedProductLink(array $item): array
    {
        $item['metadata'] = is_array($item['metadata'] ?? null) ? (array) $item['metadata'] : [];
        EcommerceHubContractValidator::validateProductLink($item);

        return $item;
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function validatedOrderLink(array $item): array
    {
        $item['metadata'] = is_array($item['metadata'] ?? null) ? (array) $item['metadata'] : [];
        EcommerceHubContractValidator::validateOrderLink($item);

        return $item;
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function validatedOrderSnapshot(array $item): array
    {
        $item['snapshot_payload'] = is_array($item['snapshot_payload'] ?? null) ? (array) $item['snapshot_payload'] : [];
        if (!array_key_exists('normalized_payload', $item) || !is_array($item['normalized_payload'])) {
            $item['normalized_payload'] = $item['normalized_payload'] ?? null;
        }
        $item['metadata'] = is_array($item['metadata'] ?? null) ? (array) $item['metadata'] : [];
        EcommerceHubContractValidator::validateOrderSnapshot($item);

        return $item;
    }

    /**
     * @param array<string, mixed> $store
     */
    private function logStoreEvent(string $actionName, string $tenantId, ?string $appId, array $store, int $latencyMs, array $extra = []): void
    {
        $payload = array_merge([
            'tenant_id' => $tenantId,
            'app_id' => $appId,
            'module' => 'ecommerce',
            'action_name' => $actionName,
            'store_id' => (string) ($store['id'] ?? ''),
            'platform' => (string) ($store['platform'] ?? 'unknown'),
            'connection_status' => (string) ($store['connection_status'] ?? 'not_configured'),
            'latency_ms' => $latencyMs,
            'result_status' => 'success',
        ], $extra);
        $this->auditLogger->log('ecommerce_' . $actionName, 'ecommerce_store', (string) ($store['id'] ?? ''), $payload);
        $this->eventLogger->log($actionName, $tenantId, $payload);
    }

    /**
     * @param array<string, mixed> $credential
     * @param array<string, mixed> $store
     */
    private function logCredentialEvent(string $actionName, string $tenantId, ?string $appId, array $credential, array $store, int $latencyMs, array $extra = []): void
    {
        $payload = array_merge([
            'tenant_id' => $tenantId,
            'app_id' => $appId,
            'module' => 'ecommerce',
            'action_name' => $actionName,
            'store_id' => (string) ($store['id'] ?? ''),
            'platform' => (string) ($store['platform'] ?? 'unknown'),
            'connection_status' => (string) ($store['connection_status'] ?? 'not_configured'),
            'latency_ms' => $latencyMs,
            'result_status' => 'success',
        ], $extra);
        $this->auditLogger->log('ecommerce_' . $actionName, 'ecommerce_credential', (string) ($credential['id'] ?? ''), $payload);
        $this->eventLogger->log($actionName, $tenantId, $payload);
    }

    /**
     * @param array<string, mixed> $job
     * @param array<string, mixed> $store
     */
    private function logSyncJobEvent(string $actionName, string $tenantId, ?string $appId, array $job, array $store, int $latencyMs, array $extra = []): void
    {
        $payload = array_merge([
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
        ], $extra);
        $this->auditLogger->log('ecommerce_' . $actionName, 'ecommerce_sync_job', (string) ($job['id'] ?? ''), $payload);
        $this->eventLogger->log($actionName, $tenantId, $payload);
    }

    /**
     * @param array<string, mixed> $link
     * @param array<string, mixed> $store
     */
    private function logProductLinkEvent(string $actionName, string $tenantId, ?string $appId, array $link, array $store, int $latencyMs, array $extra = []): void
    {
        $payload = array_merge([
            'tenant_id' => $tenantId,
            'app_id' => $appId,
            'module' => 'ecommerce',
            'action_name' => $actionName,
            'store_id' => (string) ($store['id'] ?? $link['store_id'] ?? ''),
            'platform' => (string) ($store['platform'] ?? 'unknown'),
            'connection_status' => (string) ($store['connection_status'] ?? 'not_configured'),
            'link_id' => (string) ($link['id'] ?? ''),
            'local_product_id' => (string) ($link['local_product_id'] ?? ''),
            'external_product_id' => (string) ($link['external_product_id'] ?? ''),
            'sync_status' => (string) ($link['sync_status'] ?? ''),
            'sync_direction' => (string) ($link['last_sync_direction'] ?? ''),
            'latency_ms' => $latencyMs,
            'result_status' => 'success',
        ], $extra);
        $this->auditLogger->log('ecommerce_' . $actionName, 'ecommerce_product_link', (string) ($link['id'] ?? ''), $payload);
        $this->eventLogger->log($actionName, $tenantId, $payload);
    }

    /**
     * @param array<string, mixed> $link
     * @param array<string, mixed> $store
     */
    private function logOrderLinkEvent(string $actionName, string $tenantId, ?string $appId, array $link, array $store, int $latencyMs, array $extra = []): void
    {
        $payload = array_merge([
            'tenant_id' => $tenantId,
            'app_id' => $appId,
            'module' => 'ecommerce',
            'action_name' => $actionName,
            'store_id' => (string) ($store['id'] ?? $link['store_id'] ?? ''),
            'platform' => (string) ($store['platform'] ?? 'unknown'),
            'connection_status' => (string) ($store['connection_status'] ?? 'not_configured'),
            'link_id' => (string) ($link['id'] ?? ''),
            'external_order_id' => (string) ($link['external_order_id'] ?? ''),
            'local_reference_type' => (string) ($link['local_reference_type'] ?? ''),
            'local_reference_id' => (string) ($link['local_reference_id'] ?? ''),
            'sync_status' => (string) ($link['sync_status'] ?? ''),
            'latency_ms' => $latencyMs,
            'result_status' => 'success',
        ], $extra);
        $this->auditLogger->log('ecommerce_' . $actionName, 'ecommerce_order_link', (string) ($link['id'] ?? ''), $payload);
        $this->eventLogger->log($actionName, $tenantId, $payload);
    }

    /**
     * @param array<string, mixed> $snapshot
     * @param array<string, mixed> $store
     */
    private function logOrderSnapshotEvent(string $actionName, string $tenantId, ?string $appId, array $snapshot, array $store, int $latencyMs, array $extra = []): void
    {
        $payload = array_merge([
            'tenant_id' => $tenantId,
            'app_id' => $appId,
            'module' => 'ecommerce',
            'action_name' => $actionName,
            'store_id' => (string) ($store['id'] ?? $snapshot['store_id'] ?? ''),
            'platform' => (string) ($store['platform'] ?? 'unknown'),
            'connection_status' => (string) ($store['connection_status'] ?? 'not_configured'),
            'link_id' => '',
            'external_order_id' => (string) ($snapshot['external_order_id'] ?? ''),
            'local_reference_type' => '',
            'local_reference_id' => '',
            'sync_status' => '',
            'latency_ms' => $latencyMs,
            'result_status' => 'success',
        ], $extra);
        $this->auditLogger->log('ecommerce_' . $actionName, 'ecommerce_order_snapshot', (string) ($snapshot['id'] ?? ''), $payload);
        $this->eventLogger->log($actionName, $tenantId, $payload);
    }
}
