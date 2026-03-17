<?php

declare(strict_types=1);

namespace App\Core;

use JsonException;
use RuntimeException;
use Throwable;

final class ErpTrainingDatasetVectorizer
{
    public const EXPECTED_ARTIFACT_TYPE = 'erp_vectorization_prep';
    public const EXPECTED_SCHEMA_VERSION = '1.0.0';
    public const TARGET_COLLECTION = 'agent_training';
    public const SOURCE_TYPE = 'erp_training_dataset';
    public const DEFAULT_SHARED_TENANT_ID = 'shared_agent_training';

    /**
     * @var array<int,string>
     */
    private const ALLOWED_SOURCE_ARTIFACTS = [
        'erp_intents_catalog',
        'erp_training_samples',
        'erp_hard_cases',
    ];

    /**
     * @var array<int,string>
     */
    private const ALLOWED_SOURCE_KINDS = [
        'intent_catalog',
        'training_sample',
        'hard_case',
    ];

    private GeminiEmbeddingService $embeddingService;
    private QdrantVectorStore $vectorStore;
    private int $defaultBatchSize;
    private int $defaultTopK;
    private int $maxRetries;

    public function __construct(
        ?GeminiEmbeddingService $embeddingService = null,
        ?QdrantVectorStore $vectorStore = null,
        ?int $defaultBatchSize = null,
        ?int $defaultTopK = null,
        ?int $maxRetries = null
    ) {
        $this->embeddingService = $embeddingService ?? new GeminiEmbeddingService();
        $this->vectorStore = $vectorStore ?? new QdrantVectorStore(null, null, null, null, null, null, null, self::TARGET_COLLECTION);
        $this->defaultBatchSize = max(1, (int) ($defaultBatchSize ?? getenv('ERP_TRAINING_VECTORIZE_BATCH_SIZE') ?: 25));
        $this->defaultTopK = max(1, (int) ($defaultTopK ?? getenv('ERP_TRAINING_VECTORIZE_TOP_K') ?: 5));
        $this->maxRetries = max(1, (int) ($maxRetries ?? getenv('ERP_TRAINING_VECTORIZE_RETRIES') ?: 2));

        if ($this->vectorStore->collectionName() !== self::TARGET_COLLECTION) {
            throw new RuntimeException(
                'Politica SUKI: AGENT_TRAINING_COLLECTION debe resolver exactamente a agent_training para este pipeline.'
            );
        }
        if ($this->vectorStore->memoryType() !== self::TARGET_COLLECTION) {
            throw new RuntimeException('Politica SUKI: memory_type del vector store debe ser agent_training.');
        }
    }

    public static function defaultInputPath(): string
    {
        return FRAMEWORK_ROOT . '/training/output/erp_training_dataset_mass/erp_vectorization_prep.json';
    }

    public static function sharedTenantId(): string
    {
        $tenantId = trim((string) (getenv('AGENT_TRAINING_SHARED_TENANT_ID') ?: self::DEFAULT_SHARED_TENANT_ID));
        return $tenantId !== '' ? $tenantId : self::DEFAULT_SHARED_TENANT_ID;
    }

    public static function sharedAppId(): ?string
    {
        $appId = trim((string) (getenv('AGENT_TRAINING_SHARED_APP_ID') ?: ''));
        return $appId !== '' ? $appId : null;
    }

    /**
     * @return array<string,mixed>
     */
    public function vectorizeFromPath(string $inputPath, array $options = []): array
    {
        $inputPath = ErpDatasetSupport::normalizeCliPath($inputPath);
        $inputCheck = ErpDatasetSupport::validateCliInputPath($inputPath);
        if (($inputCheck['ok'] ?? false) !== true) {
            throw new RuntimeException((string) ($inputCheck['error'] ?? 'Dataset ERP prep invalido.'));
        }

        $raw = file_get_contents($inputPath);
        if (!is_string($raw) || trim($raw) === '') {
            throw new RuntimeException('Artifacto ERP vectorization prep vacio: ' . $inputPath);
        }

        try {
            $artifact = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('Artifacto ERP vectorization prep invalido: ' . $e->getMessage(), 0, $e);
        }

        if (!is_array($artifact)) {
            throw new RuntimeException('Artifacto ERP vectorization prep debe ser un objeto JSON.');
        }

        $options['input_path'] = realpath($inputPath) ?: $inputPath;
        return $this->vectorizeArtifact($artifact, $options);
    }

    /**
     * @param array<string,mixed> $artifact
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function vectorizeArtifact(array $artifact, array $options = []): array
    {
        $startedAt = microtime(true);
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $strict = (bool) ($options['strict'] ?? false);
        $limit = max(0, (int) ($options['limit'] ?? 0));
        $batchSize = max(1, (int) ($options['batch_size'] ?? $this->defaultBatchSize));
        $topK = max(1, (int) ($options['top_k'] ?? $this->defaultTopK));
        $inputPath = trim((string) ($options['input_path'] ?? self::defaultInputPath()));

        $validated = $this->validateArtifact($artifact);
        $allEntries = $validated['entries'];
        $selectedEntries = $limit > 0 ? array_slice($allEntries, 0, $limit) : $allEntries;
        if ($selectedEntries === []) {
            throw new RuntimeException('No hay entries vectorizables luego de aplicar el limite solicitado.');
        }

        $collectionInspection = $this->vectorStore->inspectCollection();
        if (($collectionInspection['exists'] ?? false) !== true) {
            throw new RuntimeException('La coleccion agent_training no existe en Qdrant. Abortado por politica SUKI.');
        }
        if (($collectionInspection['canonical'] ?? false) !== true) {
            throw new RuntimeException('La coleccion agent_training existe pero no cumple size=768 y distance=Cosine.');
        }
        if (($collectionInspection['collection'] ?? '') !== self::TARGET_COLLECTION) {
            throw new RuntimeException('Politica SUKI: el destino resuelto no coincide con agent_training.');
        }

        $embeddingProfile = $this->embeddingService->profile();
        $probe = $this->buildEmbeddingProbe($selectedEntries[0]);

        $report = [
            'ok' => true,
            'action' => $dryRun ? 'dry_run' : 'vectorized',
            'dry_run' => $dryRun,
            'strict' => $strict,
            'input' => $inputPath,
            'collection' => self::TARGET_COLLECTION,
            'shared_scope' => [
                'tenant_id' => self::sharedTenantId(),
                'app_id' => self::sharedAppId(),
            ],
            'artifact' => [
                'artifact_type' => self::EXPECTED_ARTIFACT_TYPE,
                'schema_version' => (string) $validated['artifact']['schema_version'],
                'dataset_id' => (string) $validated['metadata']['dataset_id'],
                'dataset_version' => (string) $validated['metadata']['dataset_version'],
                'dataset_scope' => (string) $validated['metadata']['dataset_scope'],
                'tenant_data_allowed' => false,
                'generated_at' => (string) $validated['metadata']['generated_at'],
            ],
            'totals' => [
                'entries_read' => count($allEntries),
                'entries_selected' => count($selectedEntries),
                'vectorized' => 0,
                'inserted' => 0,
                'failed' => 0,
                'retrieved_in_smoke' => 0,
            ],
            'batch_size' => $batchSize,
            'top_k' => $topK,
            'collection_inspection' => $collectionInspection,
            'embedding_profile' => $embeddingProfile,
            'embedding_probe' => $probe,
            'payload_sample' => $this->buildPayload($selectedEntries[0], $validated['metadata']),
            'contract_sources_used' => [
                ErpDatasetSupport::relativeToRepo(ErpDatasetSupport::repoRoot() . '/docs/contracts/semantic_memory_payload.json'),
                ErpDatasetSupport::relativeToRepo(ErpDatasetSupport::repoRoot() . '/docs/canon/SEMANTIC_MEMORY_RUNTIME.md'),
            ],
            'errors' => [],
        ];

        if ($dryRun) {
            $report['latency_ms'] = (int) round((microtime(true) - $startedAt) * 1000);
            return $report;
        }

        $report['payload_indexes'] = $this->retry(
            fn(): array => $this->vectorStore->ensurePayloadIndexes(true)
        );

        $successfulPayload = null;
        $successfulContent = '';
        $successfulSourceId = '';
        $successfulVectorId = '';
        $batchesProcessed = 0;
        $batchesFailed = 0;

        foreach (array_chunk($selectedEntries, $batchSize) as $batch) {
            $batchesProcessed++;
            $points = [];
            $batchVectorized = 0;

            foreach ($batch as $entry) {
                $vectorId = (string) $entry['vector_id'];
                try {
                    $payload = $this->buildPayload($entry, $validated['metadata']);
                    $pointId = $this->pointIdForVectorId($vectorId);
                    $embedding = $this->retry(
                        function () use ($payload): array {
                            return $this->embeddingService->embed((string) $payload['content'], [
                                'task_type' => 'RETRIEVAL_DOCUMENT',
                                'title' => (string) $payload['source'],
                            ]);
                        }
                    );

                    $points[] = [
                        'id' => $pointId,
                        'vector' => $embedding['vector'],
                        'payload' => $payload,
                    ];
                    $batchVectorized++;

                    if ($successfulPayload === null) {
                        $successfulPayload = $payload;
                        $successfulContent = (string) $payload['content'];
                        $successfulSourceId = (string) $payload['source_id'];
                        $successfulVectorId = $vectorId;
                    }
                } catch (Throwable $e) {
                    $report['totals']['failed']++;
                    $report['errors'][] = [
                        'phase' => 'embedding',
                        'vector_id' => $vectorId,
                        'message' => $e->getMessage(),
                    ];
                    if ($strict) {
                        break 2;
                    }
                }
            }

            $report['totals']['vectorized'] += $batchVectorized;
            if ($points === []) {
                continue;
            }

            try {
                $upsert = $this->retry(
                    fn(): array => $this->vectorStore->upsertPoints($points, true)
                );
                $report['totals']['inserted'] += (int) ($upsert['upserted'] ?? 0);
            } catch (Throwable $e) {
                $batchesFailed++;
                $report['totals']['failed'] += count($points);
                foreach ($points as $point) {
                    $report['errors'][] = [
                        'phase' => 'upsert',
                        'vector_id' => (string) ($point['id'] ?? ''),
                        'message' => $e->getMessage(),
                    ];
                }
                if ($strict) {
                    break;
                }
            }
        }

        $report['batches_processed'] = $batchesProcessed;
        $report['batches_failed'] = $batchesFailed;

        if ($report['totals']['inserted'] < 1 || $successfulPayload === null) {
            $report['ok'] = false;
            $report['action'] = 'failed';
            $report['latency_ms'] = (int) round((microtime(true) - $startedAt) * 1000);
            $report['errors'][] = [
                'phase' => 'vectorization',
                'message' => 'No se insertaron puntos en agent_training; smoke retrieval no ejecutado.',
            ];
            return $report;
        }

        try {
            $smoke = $this->runSmokeRetrieval($successfulContent, $successfulPayload, $successfulSourceId, $successfulVectorId, $topK);
        } catch (Throwable $e) {
            $report['ok'] = false;
            $report['action'] = 'partial_failure';
            $report['latency_ms'] = (int) round((microtime(true) - $startedAt) * 1000);
            $report['errors'][] = [
                'phase' => 'smoke_retrieval',
                'message' => $e->getMessage(),
            ];
            return $report;
        }
        $report['smoke_test'] = $smoke;
        $report['totals']['retrieved_in_smoke'] = (int) ($smoke['retrieved'] ?? 0);
        $report['latency_ms'] = (int) round((microtime(true) - $startedAt) * 1000);

        if (($smoke['ok'] ?? false) !== true) {
            $report['ok'] = false;
            $report['action'] = $report['totals']['inserted'] > 0 ? 'partial_failure' : 'failed';
            $report['errors'][] = [
                'phase' => 'smoke_retrieval',
                'message' => 'Smoke retrieval fallo o no devolvio payload coherente.',
            ];
            return $report;
        }

        if (
            $report['totals']['failed'] > 0
            || $report['totals']['inserted'] !== count($selectedEntries)
            || $report['totals']['vectorized'] !== count($selectedEntries)
        ) {
            $report['ok'] = false;
            $report['action'] = 'partial_failure';
        }

        return $report;
    }

    /**
     * @param array<string,mixed> $artifact
     * @return array{artifact:array<string,mixed>,metadata:array<string,mixed>,entries:array<int,array<string,mixed>>}
     */
    public function validateArtifact(array $artifact): array
    {
        $artifactType = trim((string) ($artifact['artifact_type'] ?? ''));
        if ($artifactType !== self::EXPECTED_ARTIFACT_TYPE) {
            throw new RuntimeException('artifact_type invalido. Se requiere erp_vectorization_prep.');
        }

        $schemaVersion = trim((string) ($artifact['schema_version'] ?? ''));
        if ($schemaVersion !== self::EXPECTED_SCHEMA_VERSION) {
            throw new RuntimeException('schema_version invalido. Se requiere 1.0.0.');
        }

        $metadata = is_array($artifact['metadata'] ?? null) ? (array) $artifact['metadata'] : [];
        if ($metadata === []) {
            throw new RuntimeException('metadata requerido en erp_vectorization_prep.');
        }

        $datasetId = trim((string) ($metadata['dataset_id'] ?? ''));
        $datasetVersion = trim((string) ($metadata['dataset_version'] ?? ''));
        $targetCollection = trim((string) ($metadata['target_collection'] ?? ''));
        $datasetScope = trim((string) ($metadata['dataset_scope'] ?? ''));
        $generatedAt = trim((string) ($metadata['generated_at'] ?? date('c')));
        $tenantDataAllowed = (bool) ($metadata['tenant_data_allowed'] ?? false);
        $availableCollections = is_array($metadata['available_collections'] ?? null)
            ? array_values(array_filter((array) $metadata['available_collections'], static fn ($value): bool => is_string($value)))
            : [];

        if ($datasetId === '') {
            throw new RuntimeException('metadata.dataset_id requerido.');
        }
        if ($datasetVersion === '') {
            throw new RuntimeException('metadata.dataset_version requerido.');
        }
        if ($targetCollection !== self::TARGET_COLLECTION) {
            throw new RuntimeException('Politica SUKI: metadata.target_collection debe ser agent_training.');
        }
        if (!in_array(self::TARGET_COLLECTION, $availableCollections, true)) {
            throw new RuntimeException('available_collections debe incluir agent_training.');
        }
        if ($datasetScope !== ErpDatasetSupport::SHARED_TRAINING_SCOPE) {
            throw new RuntimeException('dataset_scope invalido. Se requiere shared_non_operational_training.');
        }
        if ($tenantDataAllowed) {
            throw new RuntimeException('tenant_data_allowed=true no esta permitido para agent_training.');
        }

        $entries = is_array($artifact['entries'] ?? null) ? array_values((array) $artifact['entries']) : [];
        if ($entries === []) {
            throw new RuntimeException('entries requerido y no puede estar vacio.');
        }

        $seenVectorIds = [];
        $validatedEntries = [];
        foreach ($entries as $index => $entry) {
            if (!is_array($entry)) {
                throw new RuntimeException('Entry invalido en index ' . $index . '.');
            }
            $validatedEntries[] = $this->validateEntry($entry, $index, $datasetId, $datasetVersion, $datasetScope, $seenVectorIds);
        }

        return [
            'artifact' => [
                'artifact_type' => $artifactType,
                'schema_version' => $schemaVersion,
            ],
            'metadata' => [
                'dataset_id' => $datasetId,
                'dataset_version' => $datasetVersion,
                'target_collection' => $targetCollection,
                'available_collections' => $availableCollections,
                'dataset_scope' => $datasetScope,
                'tenant_data_allowed' => false,
                'generated_at' => $generatedAt !== '' ? $generatedAt : date('c'),
            ],
            'entries' => $validatedEntries,
        ];
    }

    /**
     * @param array<string,mixed> $entry
     * @param array<int,string> $seenVectorIds
     * @return array<string,mixed>
     */
    private function validateEntry(
        array $entry,
        int $index,
        string $datasetId,
        string $datasetVersion,
        string $datasetScope,
        array &$seenVectorIds
    ): array {
        $vectorId = trim((string) ($entry['vector_id'] ?? ''));
        $collection = trim((string) ($entry['collection'] ?? ''));
        $memoryType = trim((string) ($entry['memory_type'] ?? ''));
        $sourceArtifact = trim((string) ($entry['source_artifact'] ?? ''));
        $sourceId = trim((string) ($entry['source_id'] ?? ''));
        $content = trim((string) ($entry['content'] ?? ''));
        $metadata = is_array($entry['metadata'] ?? null) ? (array) $entry['metadata'] : [];

        if ($vectorId === '') {
            throw new RuntimeException('entries[' . $index . '].vector_id requerido.');
        }
        if (isset($seenVectorIds[$vectorId])) {
            throw new RuntimeException('vector_id duplicado detectado: ' . $vectorId);
        }
        $seenVectorIds[$vectorId] = $vectorId;

        if ($collection !== self::TARGET_COLLECTION) {
            throw new RuntimeException('entries[' . $index . '].collection debe ser agent_training.');
        }
        if ($memoryType !== self::TARGET_COLLECTION) {
            throw new RuntimeException('entries[' . $index . '].memory_type debe ser agent_training.');
        }
        if (!in_array($sourceArtifact, self::ALLOWED_SOURCE_ARTIFACTS, true)) {
            throw new RuntimeException('source_artifact invalido para vectorizacion ERP: ' . $sourceArtifact);
        }
        if ($sourceId === '') {
            throw new RuntimeException('entries[' . $index . '].source_id requerido.');
        }
        if ($content === '') {
            throw new RuntimeException('entries[' . $index . '].content requerido.');
        }
        if ($metadata === []) {
            throw new RuntimeException('entries[' . $index . '].metadata requerido.');
        }

        $requiredMetadataKeys = [
            'dataset_id',
            'dataset_version',
            'domain',
            'subdomain',
            'locale',
            'intent_key',
            'target_skill',
            'skill_type',
            'risk_level',
            'needs_clarification',
            'ambiguity_flags',
            'tenant_data_allowed',
            'dataset_scope',
            'source_kind',
        ];
        foreach ($requiredMetadataKeys as $key) {
            if (!array_key_exists($key, $metadata)) {
                throw new RuntimeException('entries[' . $index . '].metadata.' . $key . ' requerido.');
            }
        }

        if (trim((string) $metadata['dataset_id']) !== $datasetId) {
            throw new RuntimeException('entries[' . $index . '].metadata.dataset_id inconsistente.');
        }
        if (trim((string) $metadata['dataset_version']) !== $datasetVersion) {
            throw new RuntimeException('entries[' . $index . '].metadata.dataset_version inconsistente.');
        }
        if ((bool) $metadata['tenant_data_allowed']) {
            throw new RuntimeException('entries[' . $index . '].metadata.tenant_data_allowed=true no permitido.');
        }
        if (trim((string) $metadata['dataset_scope']) !== $datasetScope) {
            throw new RuntimeException('entries[' . $index . '].metadata.dataset_scope inconsistente.');
        }

        $skillType = strtolower(trim((string) $metadata['skill_type']));
        if (!in_array($skillType, ErpDatasetSupport::SKILL_TYPES, true)) {
            throw new RuntimeException('entries[' . $index . '].metadata.skill_type invalido.');
        }
        $riskLevel = strtolower(trim((string) $metadata['risk_level']));
        if (!in_array($riskLevel, ErpDatasetSupport::RISK_LEVELS, true)) {
            throw new RuntimeException('entries[' . $index . '].metadata.risk_level invalido.');
        }

        $ambiguityFlags = is_array($metadata['ambiguity_flags']) ? (array) $metadata['ambiguity_flags'] : null;
        if ($ambiguityFlags === null) {
            throw new RuntimeException('entries[' . $index . '].metadata.ambiguity_flags debe ser array.');
        }
        foreach ($ambiguityFlags as $flag) {
            if (!is_string($flag) || !in_array($flag, ErpDatasetSupport::AMBIGUITY_FLAGS, true)) {
                throw new RuntimeException('entries[' . $index . '].metadata.ambiguity_flags contiene valor invalido.');
            }
        }

        $sourceKind = trim((string) $metadata['source_kind']);
        if (!in_array($sourceKind, self::ALLOWED_SOURCE_KINDS, true)) {
            throw new RuntimeException('entries[' . $index . '].metadata.source_kind invalido.');
        }

        if (trim((string) $metadata['domain']) === '' || trim((string) $metadata['subdomain']) === '') {
            throw new RuntimeException('entries[' . $index . '].metadata domain/subdomain requeridos.');
        }
        if (trim((string) $metadata['locale']) === '') {
            throw new RuntimeException('entries[' . $index . '].metadata.locale requerido.');
        }
        if (trim((string) $metadata['intent_key']) === '' || trim((string) $metadata['target_skill']) === '') {
            throw new RuntimeException('entries[' . $index . '].metadata.intent_key y target_skill requeridos.');
        }

        $metadata['skill_type'] = $skillType;
        $metadata['risk_level'] = $riskLevel;
        $metadata['needs_clarification'] = (bool) $metadata['needs_clarification'];
        $metadata['ambiguity_flags'] = array_values(array_unique(array_map('strval', $ambiguityFlags)));

        return [
            'vector_id' => $vectorId,
            'collection' => $collection,
            'memory_type' => $memoryType,
            'source_artifact' => $sourceArtifact,
            'source_id' => $sourceId,
            'content' => $content,
            'metadata' => $metadata,
        ];
    }

    /**
     * @param array<string,mixed> $entry
     * @param array<string,mixed> $artifactMetadata
     * @return array<string,mixed>
     */
    private function buildPayload(array $entry, array $artifactMetadata): array
    {
        $entryMetadata = is_array($entry['metadata'] ?? null) ? (array) $entry['metadata'] : [];
        $sourceKind = (string) ($entryMetadata['source_kind'] ?? 'training_sample');
        $riskLevel = (string) ($entryMetadata['risk_level'] ?? 'medium');
        $needsClarification = (bool) ($entryMetadata['needs_clarification'] ?? false);
        $ambiguityFlags = is_array($entryMetadata['ambiguity_flags'] ?? null) ? (array) $entryMetadata['ambiguity_flags'] : [];

        $chunk = [
            'memory_type' => self::TARGET_COLLECTION,
            'tenant_id' => self::sharedTenantId(),
            'app_id' => self::sharedAppId(),
            'agent_role' => null,
            'sector' => (string) ($entryMetadata['domain'] ?? 'erp'),
            'source_type' => self::SOURCE_TYPE,
            'source_id' => (string) $entry['source_id'],
            'source' => (string) $entry['source_artifact'] . ':' . (string) $entry['source_id'],
            'chunk_id' => (string) $entry['vector_id'],
            'type' => $sourceKind,
            'tags' => $this->buildTags($entry, $artifactMetadata),
            'version' => (string) $artifactMetadata['dataset_version'],
            'quality_score' => $this->qualityScoreFor($sourceKind, $riskLevel, $needsClarification),
            'created_at' => (string) $artifactMetadata['generated_at'],
            'updated_at' => (string) $artifactMetadata['generated_at'],
            'metadata' => [
                'artifact_type' => self::EXPECTED_ARTIFACT_TYPE,
                'schema_version' => self::EXPECTED_SCHEMA_VERSION,
                'dataset_id' => (string) $artifactMetadata['dataset_id'],
                'dataset_version' => (string) $artifactMetadata['dataset_version'],
                'target_collection' => self::TARGET_COLLECTION,
                'dataset_scope' => (string) $artifactMetadata['dataset_scope'],
                'tenant_data_allowed' => false,
                'domain' => (string) ($entryMetadata['domain'] ?? ''),
                'subdomain' => (string) ($entryMetadata['subdomain'] ?? ''),
                'locale' => (string) ($entryMetadata['locale'] ?? ''),
                'intent_key' => (string) ($entryMetadata['intent_key'] ?? ''),
                'target_skill' => (string) ($entryMetadata['target_skill'] ?? ''),
                'skill_type' => (string) ($entryMetadata['skill_type'] ?? ''),
                'required_action' => array_key_exists('required_action', $entryMetadata)
                    ? (string) ($entryMetadata['required_action'] ?? '')
                    : null,
                'risk_level' => $riskLevel,
                'needs_clarification' => $needsClarification,
                'ambiguity_flags' => $ambiguityFlags,
                'source_kind' => $sourceKind,
                'source_artifact' => (string) $entry['source_artifact'],
                'source_id' => (string) $entry['source_id'],
                'vector_id' => (string) $entry['vector_id'],
            ],
            'content' => (string) $entry['content'],
        ];

        $payload = SemanticChunkContract::buildPayload($chunk);
        $payload['collection'] = self::TARGET_COLLECTION;
        $payload['target_collection'] = self::TARGET_COLLECTION;
        $payload['vector_id'] = (string) $entry['vector_id'];
        $payload['qdrant_point_id'] = $this->pointIdForVectorId((string) $entry['vector_id']);
        $payload['dataset_id'] = (string) $artifactMetadata['dataset_id'];
        $payload['dataset_version'] = (string) $artifactMetadata['dataset_version'];
        $payload['domain'] = (string) ($entryMetadata['domain'] ?? '');
        $payload['subdomain'] = (string) ($entryMetadata['subdomain'] ?? '');
        $payload['locale'] = (string) ($entryMetadata['locale'] ?? '');
        $payload['intent_key'] = (string) ($entryMetadata['intent_key'] ?? '');
        $payload['target_skill'] = (string) ($entryMetadata['target_skill'] ?? '');
        $payload['skill_type'] = (string) ($entryMetadata['skill_type'] ?? '');
        $payload['required_action'] = array_key_exists('required_action', $entryMetadata)
            ? (string) ($entryMetadata['required_action'] ?? '')
            : null;
        $payload['risk_level'] = $riskLevel;
        $payload['needs_clarification'] = $needsClarification;
        $payload['ambiguity_flags'] = $ambiguityFlags;
        $payload['source_kind'] = $sourceKind;
        $payload['dataset_scope'] = (string) $artifactMetadata['dataset_scope'];
        $payload['tenant_data_allowed'] = false;
        $payload['source_artifact'] = (string) $entry['source_artifact'];
        $payload['source_id'] = (string) $entry['source_id'];
        $payload['content'] = (string) $entry['content'];

        return $payload;
    }

    /**
     * @param array<string,mixed> $entry
     * @param array<string,mixed> $artifactMetadata
     * @return array<int,string>
     */
    private function buildTags(array $entry, array $artifactMetadata): array
    {
        $entryMetadata = is_array($entry['metadata'] ?? null) ? (array) $entry['metadata'] : [];
        $tags = [
            'memory_type:' . self::TARGET_COLLECTION,
            'dataset_id:' . $this->safeTag((string) $artifactMetadata['dataset_id']),
            'dataset_version:' . $this->safeTag((string) $artifactMetadata['dataset_version']),
            'domain:' . $this->safeTag((string) ($entryMetadata['domain'] ?? 'erp')),
            'subdomain:' . $this->safeTag((string) ($entryMetadata['subdomain'] ?? 'general')),
            'locale:' . $this->safeTag((string) ($entryMetadata['locale'] ?? 'es-co')),
            'intent:' . $this->safeTag((string) ($entryMetadata['intent_key'] ?? 'unknown')),
            'skill:' . $this->safeTag((string) ($entryMetadata['target_skill'] ?? 'unknown')),
            'source_kind:' . $this->safeTag((string) ($entryMetadata['source_kind'] ?? 'training_sample')),
            'risk_level:' . $this->safeTag((string) ($entryMetadata['risk_level'] ?? 'medium')),
            'scope:' . $this->safeTag((string) $artifactMetadata['dataset_scope']),
        ];

        return array_values(array_filter(array_unique($tags), static fn ($tag): bool => $tag !== '' && !str_ends_with($tag, ':')));
    }

    /**
     * @param array<string,mixed> $entry
     * @return array<string,mixed>
     */
    private function buildEmbeddingProbe(array $entry): array
    {
        $embedding = $this->retry(
            fn(): array => $this->embeddingService->embed((string) $entry['content'], [
                'task_type' => 'RETRIEVAL_DOCUMENT',
                'title' => (string) $entry['source_id'],
            ])
        );

        return [
            'ok' => true,
            'vector_id' => (string) $entry['vector_id'],
            'source_id' => (string) $entry['source_id'],
            'dimensions' => (int) ($embedding['dimensions'] ?? 0),
            'model' => (string) ($embedding['model'] ?? ''),
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function runSmokeRetrieval(
        string $content,
        array $payload,
        string $sourceId,
        string $vectorId,
        int $topK
    ): array {
        $queryEmbedding = $this->retry(
            fn(): array => $this->embeddingService->embed($content, ['task_type' => 'RETRIEVAL_QUERY'])
        );

        $filter = [
            'must' => [
                [
                    'key' => 'memory_type',
                    'match' => ['value' => self::TARGET_COLLECTION],
                ],
                [
                    'key' => 'tenant_id',
                    'match' => ['value' => self::sharedTenantId()],
                ],
                [
                    'key' => 'dataset_id',
                    'match' => ['value' => (string) ($payload['dataset_id'] ?? '')],
                ],
                [
                    'key' => 'source_id',
                    'match' => ['value' => $sourceId],
                ],
            ],
        ];
        if (($payload['app_id'] ?? null) !== null) {
            $filter['must'][] = [
                'key' => 'app_id',
                'match' => ['value' => (string) $payload['app_id']],
            ];
        }

        $hits = $this->retry(
            fn(): array => $this->vectorStore->query((array) $queryEmbedding['vector'], $filter, $topK, true)
        );
        $topHit = is_array($hits[0] ?? null) ? (array) $hits[0] : [];
        $topPayload = is_array($topHit['payload'] ?? null) ? (array) $topHit['payload'] : [];
        $topVectorId = (string) ($topPayload['vector_id'] ?? '');
        $topSourceId = (string) ($topPayload['source_id'] ?? '');
        $topDatasetId = (string) ($topPayload['dataset_id'] ?? '');
        $expectedPointId = (string) ($payload['qdrant_point_id'] ?? '');
        $coherent = $topPayload !== []
            && $topVectorId !== ''
            && $topDatasetId === (string) ($payload['dataset_id'] ?? '')
            && $topSourceId === $sourceId
            && ($topPayload['tenant_data_allowed'] ?? null) === false
            && (string) ($topPayload['collection'] ?? '') === self::TARGET_COLLECTION
            && (string) ($topHit['id'] ?? '') === $expectedPointId;

        return [
            'ok' => $coherent && count($hits) > 0,
            'retrieved' => count($hits),
            'top_k' => $topK,
            'expected_point_id' => $expectedPointId,
            'expected_vector_id' => $vectorId,
            'expected_source_id' => $sourceId,
            'top_hit' => [
                'id' => $topHit['id'] ?? null,
                'score' => $topHit['score'] ?? null,
                'vector_id' => $topVectorId,
                'source_id' => $topSourceId,
                'dataset_id' => $topDatasetId,
                'collection' => (string) ($topPayload['collection'] ?? ''),
                'tenant_data_allowed' => $topPayload['tenant_data_allowed'] ?? null,
            ],
        ];
    }

    /**
     * @template T
     * @param callable():T $operation
     * @return T
     */
    private function retry(callable $operation)
    {
        $attempt = 0;
        $lastError = null;

        while ($attempt < $this->maxRetries) {
            $attempt++;
            try {
                return $operation();
            } catch (Throwable $e) {
                $lastError = $e;
                if ($attempt >= $this->maxRetries) {
                    break;
                }
                usleep(150000);
            }
        }

        throw new RuntimeException(
            $lastError instanceof Throwable ? $lastError->getMessage() : 'Operacion vectorial fallo sin detalle.'
        );
    }

    private function qualityScoreFor(string $sourceKind, string $riskLevel, bool $needsClarification): float
    {
        $base = match ($sourceKind) {
            'hard_case' => 0.96,
            'intent_catalog' => 0.88,
            default => 0.92,
        };

        if ($needsClarification) {
            $base -= 0.03;
        }
        if ($riskLevel === 'critical') {
            $base += 0.01;
        }

        return max(0.0, min(1.0, $base));
    }

    private function pointIdForVectorId(string $vectorId): string
    {
        $hash = md5(self::TARGET_COLLECTION . '|' . $vectorId);
        $timeHi = sprintf('%04x', (hexdec(substr($hash, 12, 4)) & 0x0fff) | 0x5000);
        $clockSeq = sprintf('%04x', (hexdec(substr($hash, 16, 4)) & 0x3fff) | 0x8000);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            $timeHi,
            $clockSeq,
            substr($hash, 20, 12)
        );
    }

    private function safeTag(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9:_-]+/', '_', $value);
        $value = is_string($value) ? trim($value, '_') : '';
        return $value !== '' ? $value : 'unknown';
    }
}
