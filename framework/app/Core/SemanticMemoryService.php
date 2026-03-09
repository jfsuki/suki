<?php
// app/Core/SemanticMemoryService.php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class SemanticMemoryService
{
    private const DEFAULT_MIN_EVIDENCE_CHUNKS = 1;
    private const DEFAULT_MAX_CONTEXT_CHUNKS = 3;

    private GeminiEmbeddingService $embeddingService;
    private ?QdrantVectorStore $vectorStorePrototype;
    private int $defaultTopK;

    /**
     * @var array<string,QdrantVectorStore>
     */
    private array $vectorStores = [];

    public function __construct(
        ?GeminiEmbeddingService $embeddingService = null,
        ?QdrantVectorStore $vectorStore = null,
        ?int $defaultTopK = null
    ) {
        $this->embeddingService = $embeddingService ?? new GeminiEmbeddingService();
        $this->vectorStorePrototype = $vectorStore;
        $this->defaultTopK = max(1, (int) ($defaultTopK ?? getenv('SEMANTIC_MEMORY_TOP_K') ?: 5));
    }

    public static function isEnabledFromEnv(): bool
    {
        return self::availabilityFromEnv()['enabled'];
    }

    /**
     * @return array{enabled:bool,status:string,reason:string}
     */
    public static function availabilityFromEnv(): array
    {
        $raw = trim((string) (getenv('SEMANTIC_MEMORY_ENABLED') ?: '0'));
        if ($raw === '1') {
            return [
                'enabled' => true,
                'status' => 'enabled',
                'reason' => 'semantic_memory_enabled',
            ];
        }

        return [
            'enabled' => false,
            'status' => 'disabled',
            'reason' => 'semantic_memory_disabled_by_config',
        ];
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public static function disabledResult(string $reason, array $context = []): array
    {
        return [
            'ok' => true,
            'rag_hit' => false,
            'hits' => [],
            'source_ids' => [],
            'evidence_ids' => [],
            'telemetry' => array_merge([
                'retrieval_attempted' => false,
                'retrieval_result_count' => 0,
                'semantic_memory_status' => 'disabled',
                'reason' => trim($reason) !== '' ? trim($reason) : 'semantic_memory_unavailable',
            ], $context),
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $chunks
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function ingest(array $chunks, array $options = []): array
    {
        $requestedMemoryType = trim((string) ($options['memory_type'] ?? ''));
        $received = count($chunks);
        $sanitized = $this->sanitizeChunks($chunks, $requestedMemoryType !== '' ? $requestedMemoryType : null);
        $accepted = $sanitized['chunks'];
        $deduped = (int) $sanitized['deduped'];
        $dropped = (int) $sanitized['dropped'];
        $memoryType = (string) ($sanitized['memory_type'] ?? '');
        $collection = $memoryType !== '' ? QdrantVectorStore::resolveCollectionOrFail($memoryType) : null;

        if ($accepted === []) {
            return [
                'ok' => true,
                'received' => $received,
                'accepted' => 0,
                'upserted' => 0,
                'deduped' => $deduped,
                'dropped' => $dropped,
                'memory_type' => $memoryType !== '' ? $memoryType : null,
                'collection' => $collection,
                'embedding_profile' => $this->embeddingService->profile(),
                'vector_normalization' => 'none_provider_cosine_native',
                'flow' => 'ingest->canonical_payload->embedding(768)->upsert_qdrant',
            ];
        }

        $store = $this->storeForMemoryType($memoryType);
        $store->ensureCollection();
        $store->ensurePayloadIndexes((bool) ($options['wait'] ?? true));

        $points = [];
        foreach ($accepted as $chunk) {
            $payload = SemanticChunkContract::buildPayload($chunk);
            $embedding = $this->embeddingService->embed((string) $payload['content'], [
                'task_type' => 'RETRIEVAL_DOCUMENT',
                'title' => (string) $payload['source'],
            ]);
            $points[] = [
                'id' => SemanticChunkContract::buildPointId($chunk),
                'vector' => $embedding['vector'],
                'payload' => $payload,
            ];
        }

        $upsertResult = $store->upsertPoints(
            $points,
            (bool) ($options['wait'] ?? true)
        );

        return [
            'ok' => true,
            'received' => $received,
            'accepted' => count($accepted),
            'upserted' => (int) ($upsertResult['upserted'] ?? 0),
            'deduped' => $deduped,
            'dropped' => $dropped,
            'memory_type' => $memoryType,
            'collection' => $store->collectionName(),
            'embedding_profile' => $this->embeddingService->profile(),
            'vector_normalization' => 'none_provider_cosine_native',
            'flow' => 'ingest->canonical_payload->embedding(768)->upsert_qdrant',
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $chunks
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function ingestToMemoryType(string $memoryType, array $chunks, array $options = []): array
    {
        $options['memory_type'] = QdrantVectorStore::assertMemoryType($memoryType);
        return $this->ingest($chunks, $options);
    }

    /**
     * @param array<int,array<string,mixed>> $chunks
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function ingestAgentTraining(array $chunks, array $options = []): array
    {
        return $this->ingestToMemoryType('agent_training', $chunks, $options);
    }

    /**
     * @param array<int,array<string,mixed>> $chunks
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function ingestSectorKnowledge(array $chunks, array $options = []): array
    {
        return $this->ingestToMemoryType('sector_knowledge', $chunks, $options);
    }

    /**
     * @param array<int,array<string,mixed>> $chunks
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function ingestUserMemory(array $chunks, array $options = []): array
    {
        return $this->ingestToMemoryType('user_memory', $chunks, $options);
    }

    /**
     * @param array<string,mixed> $scope
     * @return array<string,mixed>
     */
    public function retrieve(string $query, array $scope, ?int $limit = null): array
    {
        $query = trim($query);
        if ($query === '') {
            return [
                'ok' => true,
                'rag_hit' => false,
                'hits' => [],
                'source_ids' => [],
                'evidence_ids' => [],
                'telemetry' => [
                    'retrieval_attempted' => false,
                    'reason' => 'empty_query',
                ],
            ];
        }

        $memoryType = QdrantVectorStore::assertMemoryType((string) ($scope['memory_type'] ?? ''));
        $tenantId = trim((string) ($scope['tenant_id'] ?? ''));
        if ($tenantId === '') {
            throw new RuntimeException('retrieve requiere tenant_id.');
        }

        $appId = $this->normalizeNullableString($scope['app_id'] ?? null);
        $sector = $this->normalizeNullableString($scope['sector'] ?? null);
        $agentRole = $this->normalizeNullableString($scope['agent_role'] ?? null);
        $userId = $this->normalizeNullableString($scope['user_id'] ?? null);

        $runtimeConfig = self::retrievalRuntimeConfig();
        $queryEmbedding = $this->embeddingService->embed($query, [
            'task_type' => 'RETRIEVAL_QUERY',
        ]);
        $store = $this->storeForMemoryType($memoryType);
        $store->ensureCollection();
        $store->ensurePayloadIndexes();

        $filter = $this->buildScopeFilter($memoryType, $tenantId, $appId, $sector, $agentRole, $userId);
        $topK = max(1, (int) ($limit ?? $this->defaultTopK ?? $runtimeConfig['top_k']));
        $minScore = (float) $runtimeConfig['min_score'];

        $results = $store->query($queryEmbedding['vector'], $filter, $topK, true);
        $hits = [];
        $sourceIds = [];
        $evidenceIds = [];

        foreach ($results as $row) {
            $payload = is_array($row['payload'] ?? null) ? (array) $row['payload'] : [];
            $score = isset($row['score']) && is_numeric($row['score']) ? (float) $row['score'] : 0.0;
            if ($score < $minScore) {
                continue;
            }

            $sourceId = trim((string) ($payload['source_id'] ?? ''));
            $chunkId = trim((string) ($payload['chunk_id'] ?? ''));
            if ($sourceId !== '' && !in_array($sourceId, $sourceIds, true)) {
                $sourceIds[] = $sourceId;
            }
            if ($chunkId !== '' && !in_array($chunkId, $evidenceIds, true)) {
                $evidenceIds[] = $chunkId;
            }

            $hits[] = [
                'id' => $row['id'] ?? null,
                'score' => $score,
                'memory_type' => (string) ($payload['memory_type'] ?? ''),
                'tenant_id' => (string) ($payload['tenant_id'] ?? ''),
                'app_id' => $this->normalizeNullableString($payload['app_id'] ?? null),
                'agent_role' => $this->normalizeNullableString($payload['agent_role'] ?? null),
                'sector' => $this->normalizeNullableString($payload['sector'] ?? null),
                'user_id' => $this->normalizeNullableString($payload['user_id'] ?? null),
                'source_id' => $sourceId,
                'source' => (string) ($payload['source'] ?? $sourceId),
                'chunk_id' => $chunkId,
                'source_type' => (string) ($payload['source_type'] ?? ''),
                'type' => (string) ($payload['type'] ?? ''),
                'tags' => is_array($payload['tags'] ?? null) ? (array) $payload['tags'] : [],
                'version' => (string) ($payload['version'] ?? ''),
                'quality_score' => (float) ($payload['quality_score'] ?? 0.0),
                'created_at' => (string) ($payload['created_at'] ?? ''),
                'updated_at' => (string) ($payload['updated_at'] ?? ''),
                'metadata' => is_array($payload['metadata'] ?? null) ? (array) $payload['metadata'] : [],
                'content' => (string) ($payload['content'] ?? ''),
            ];
        }

        return [
            'ok' => true,
            'rag_hit' => $hits !== [],
            'hits' => $hits,
            'source_ids' => $sourceIds,
            'evidence_ids' => $evidenceIds,
            'telemetry' => [
                'retrieval_attempted' => true,
                'retrieval_result_count' => count($hits),
                'semantic_memory_status' => 'enabled',
                'collection' => $store->collectionName(),
                'memory_type' => $memoryType,
                'distance' => 'Cosine',
                'top_k' => $topK,
                'tenant_id' => $tenantId,
                'app_id' => $appId,
                'sector' => $sector,
                'agent_role' => $agentRole,
                'user_id' => $userId,
                'vector_normalization' => 'none_provider_cosine_native',
            ],
        ];
    }

    /**
     * @return array{top_k:int,min_score:float,min_evidence_chunks:int,max_context_chunks:int}
     */
    public static function retrievalRuntimeConfig(): array
    {
        $topK = max(1, (int) (getenv('SEMANTIC_MEMORY_TOP_K') ?: 5));
        $minEvidenceChunks = max(
            1,
            (int) (getenv('SEMANTIC_MEMORY_MIN_EVIDENCE_CHUNKS') ?: self::DEFAULT_MIN_EVIDENCE_CHUNKS)
        );
        $maxContextChunks = max(
            1,
            (int) (getenv('SEMANTIC_MEMORY_MAX_CONTEXT_CHUNKS') ?: min($topK, self::DEFAULT_MAX_CONTEXT_CHUNKS))
        );

        return [
            'top_k' => $topK,
            'min_score' => (float) (getenv('SEMANTIC_MEMORY_MIN_SCORE') ?: 0.0),
            'min_evidence_chunks' => $minEvidenceChunks,
            'max_context_chunks' => min($topK, $maxContextChunks),
        ];
    }

    /**
     * @param array<string,mixed> $retrieval
     * @return array{chunks:array<int,array<string,mixed>>,used_count:int,source_ids:array<int,string>,evidence_ids:array<int,string>,max_context_chunks:int,min_evidence_chunks:int}
     */
    public static function prepareContext(array $retrieval, ?int $maxContextChunks = null): array
    {
        $config = self::retrievalRuntimeConfig();
        if ($maxContextChunks !== null) {
            $config['max_context_chunks'] = max(1, min((int) $maxContextChunks, max(1, (int) $config['top_k'])));
        }
        $hits = is_array($retrieval['hits'] ?? null) ? (array) $retrieval['hits'] : [];
        $chunks = [];
        $sourceIds = [];
        $evidenceIds = [];
        $seen = [];

        foreach ($hits as $hit) {
            if (!is_array($hit)) {
                continue;
            }
            $chunkId = trim((string) ($hit['chunk_id'] ?? ''));
            $sourceId = trim((string) ($hit['source_id'] ?? ''));
            $content = trim((string) ($hit['content'] ?? ''));
            if ($content === '') {
                continue;
            }

            $dedupeKey = $chunkId !== '' ? $chunkId : sha1($sourceId . '|' . $content);
            if (isset($seen[$dedupeKey])) {
                continue;
            }
            $seen[$dedupeKey] = true;

            if ($sourceId !== '' && !in_array($sourceId, $sourceIds, true)) {
                $sourceIds[] = $sourceId;
            }
            if ($chunkId !== '' && !in_array($chunkId, $evidenceIds, true)) {
                $evidenceIds[] = $chunkId;
            }

            $chunks[] = [
                'id' => $hit['id'] ?? null,
                'score' => isset($hit['score']) && is_numeric($hit['score']) ? (float) $hit['score'] : 0.0,
                'memory_type' => (string) ($hit['memory_type'] ?? ''),
                'tenant_id' => (string) ($hit['tenant_id'] ?? ''),
                'app_id' => $hit['app_id'] ?? null,
                'agent_role' => $hit['agent_role'] ?? null,
                'sector' => $hit['sector'] ?? null,
                'user_id' => $hit['user_id'] ?? null,
                'source_id' => $sourceId,
                'source' => (string) ($hit['source'] ?? $sourceId),
                'chunk_id' => $chunkId,
                'source_type' => (string) ($hit['source_type'] ?? ''),
                'content' => $content,
                'tags' => is_array($hit['tags'] ?? null) ? (array) $hit['tags'] : [],
            ];

            if (count($chunks) >= $config['max_context_chunks']) {
                break;
            }
        }

        return [
            'chunks' => $chunks,
            'used_count' => count($chunks),
            'source_ids' => $sourceIds,
            'evidence_ids' => $evidenceIds,
            'max_context_chunks' => $config['max_context_chunks'],
            'min_evidence_chunks' => $config['min_evidence_chunks'],
        ];
    }

    /**
     * @param array<string,mixed> $scope
     * @return array<string,mixed>
     */
    public function retrieveFromMemoryType(string $memoryType, string $query, array $scope, ?int $limit = null): array
    {
        $scope['memory_type'] = QdrantVectorStore::assertMemoryType($memoryType);
        return $this->retrieve($query, $scope, $limit);
    }

    /**
     * @param array<string,mixed> $scope
     * @return array<string,mixed>
     */
    public function retrieveAgentTraining(string $query, array $scope, ?int $limit = null): array
    {
        return $this->retrieveFromMemoryType('agent_training', $query, $scope, $limit);
    }

    /**
     * @param array<string,mixed> $scope
     * @return array<string,mixed>
     */
    public function retrieveSectorKnowledge(string $query, array $scope, ?int $limit = null): array
    {
        return $this->retrieveFromMemoryType('sector_knowledge', $query, $scope, $limit);
    }

    /**
     * @param array<string,mixed> $scope
     * @return array<string,mixed>
     */
    public function retrieveUserMemory(string $query, array $scope, ?int $limit = null): array
    {
        return $this->retrieveFromMemoryType('user_memory', $query, $scope, $limit);
    }

    /**
     * @param array<int,array<string,mixed>> $chunks
     * @return array{chunks:array<int,array<string,mixed>>,deduped:int,dropped:int,memory_type:string}
     */
    private function sanitizeChunks(array $chunks, ?string $requestedMemoryType = null): array
    {
        $accepted = [];
        $seen = [];
        $deduped = 0;
        $dropped = 0;
        $resolvedMemoryType = $requestedMemoryType !== null
            ? QdrantVectorStore::assertMemoryType($requestedMemoryType)
            : '';

        foreach ($chunks as $chunk) {
            if (!is_array($chunk)) {
                $dropped++;
                continue;
            }

            try {
                $normalized = SemanticChunkContract::normalizeChunk($chunk);
            } catch (\Throwable $e) {
                $dropped++;
                continue;
            }
            $chunkMemoryType = (string) $normalized['memory_type'];
            if ($resolvedMemoryType === '') {
                $resolvedMemoryType = $chunkMemoryType;
            }
            if ($chunkMemoryType !== $resolvedMemoryType) {
                throw new RuntimeException('ingest requiere un unico memory_type por operacion.');
            }

            $dedupeKey = implode('|', [
                (string) $normalized['memory_type'],
                (string) $normalized['tenant_id'],
                (string) ($normalized['app_id'] ?? ''),
                (string) $normalized['source_id'],
                (string) $normalized['chunk_id'],
                (string) $normalized['version'],
            ]);
            if (isset($seen[$dedupeKey])) {
                $deduped++;
                continue;
            }
            $seen[$dedupeKey] = true;
            $accepted[] = $normalized;
        }

        return [
            'chunks' => $accepted,
            'deduped' => $deduped,
            'dropped' => $dropped,
            'memory_type' => $resolvedMemoryType,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function buildScopeFilter(
        string $memoryType,
        string $tenantId,
        ?string $appId,
        ?string $sector,
        ?string $agentRole,
        ?string $userId
    ): array {
        $must = [
            [
                'key' => 'memory_type',
                'match' => ['value' => $memoryType],
            ],
            [
                'key' => 'tenant_id',
                'match' => ['value' => $tenantId],
            ],
        ];

        if ($appId !== null) {
            $must[] = [
                'key' => 'app_id',
                'match' => ['value' => $appId],
            ];
        }
        if ($sector !== null) {
            $must[] = [
                'key' => 'sector',
                'match' => ['value' => $sector],
            ];
        }
        if ($agentRole !== null) {
            $must[] = [
                'key' => 'agent_role',
                'match' => ['value' => $agentRole],
            ];
        }
        if ($memoryType === 'user_memory' && $userId !== null) {
            $must[] = [
                'key' => 'user_id',
                'match' => ['value' => $userId],
            ];
        }

        return ['must' => $must];
    }

    private function storeForMemoryType(string $memoryType): QdrantVectorStore
    {
        $memoryType = QdrantVectorStore::assertMemoryType($memoryType);
        if (isset($this->vectorStores[$memoryType])) {
            return $this->vectorStores[$memoryType];
        }

        if ($this->vectorStorePrototype !== null) {
            $store = $this->vectorStorePrototype->forMemoryType($memoryType);
        } else {
            $store = new QdrantVectorStore(null, null, null, null, null, null, null, $memoryType);
        }

        $this->vectorStores[$memoryType] = $store;
        return $store;
    }

    /**
     * @param mixed $value
     */
    private function normalizeNullableString($value): ?string
    {
        $value = trim((string) $value);
        return $value !== '' ? $value : null;
    }
}
