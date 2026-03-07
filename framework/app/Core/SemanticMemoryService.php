<?php
// app/Core/SemanticMemoryService.php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class SemanticMemoryService
{
    private GeminiEmbeddingService $embeddingService;
    private QdrantVectorStore $vectorStore;
    private int $defaultTopK;

    public function __construct(
        ?GeminiEmbeddingService $embeddingService = null,
        ?QdrantVectorStore $vectorStore = null,
        ?int $defaultTopK = null
    ) {
        $this->embeddingService = $embeddingService ?? new GeminiEmbeddingService();
        $this->vectorStore = $vectorStore ?? new QdrantVectorStore();
        $this->defaultTopK = max(1, (int) ($defaultTopK ?? getenv('SEMANTIC_MEMORY_TOP_K') ?: 5));
    }

    public static function isEnabledFromEnv(): bool
    {
        return (string) (getenv('SEMANTIC_MEMORY_ENABLED') ?: '0') === '1';
    }

    /**
     * Canonical flow:
     * ingest -> hygiene -> embedding(768) -> upsert Qdrant
     *
     * @param array<int,array<string,mixed>> $chunks
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function ingest(array $chunks, array $options = []): array
    {
        $received = count($chunks);
        $sanitized = $this->sanitizeChunks($chunks);
        $accepted = $sanitized['chunks'];
        $deduped = (int) $sanitized['deduped'];
        $dropped = (int) $sanitized['dropped'];

        if (empty($accepted)) {
            return [
                'ok' => true,
                'received' => $received,
                'accepted' => 0,
                'upserted' => 0,
                'deduped' => $deduped,
                'dropped' => $dropped,
                'collection' => $this->vectorStore->collectionName(),
                'flow' => 'ingest->hygiene->embedding(768)->upsert_qdrant',
            ];
        }

        $this->vectorStore->ensureCollection();

        $points = [];
        foreach ($accepted as $chunk) {
            $payload = SemanticChunkContract::buildPayload($chunk);
            $embedding = $this->embeddingService->embed((string) $payload['content'], [
                'task_type' => 'RETRIEVAL_DOCUMENT',
                'title' => (string) $payload['source_id'],
            ]);
            $points[] = [
                'id' => SemanticChunkContract::buildPointId($chunk),
                'vector' => $embedding['vector'],
                'payload' => $payload,
            ];
        }

        $upsertResult = $this->vectorStore->upsertPoints(
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
            'collection' => $this->vectorStore->collectionName(),
            'embedding_profile' => $this->embeddingService->profile(),
            'flow' => 'ingest->hygiene->embedding(768)->upsert_qdrant',
        ];
    }

    /**
     * Canonical retrieval:
     * embedding(768 query) -> cosine search in Qdrant
     *
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

        $tenantId = trim((string) ($scope['tenant_id'] ?? ''));
        if ($tenantId === '') {
            throw new RuntimeException('retrieve requiere tenant_id.');
        }
        $appIdRaw = trim((string) ($scope['app_id'] ?? ''));
        $appId = $appIdRaw !== '' ? $appIdRaw : null;

        $queryEmbedding = $this->embeddingService->embed($query, [
            'task_type' => 'RETRIEVAL_QUERY',
        ]);
        $filter = $this->buildScopeFilter($tenantId, $appId);
        $topK = max(1, (int) ($limit ?? $this->defaultTopK));
        $minScore = (float) (getenv('SEMANTIC_MEMORY_MIN_SCORE') ?: 0.0);

        $results = $this->vectorStore->query($queryEmbedding['vector'], $filter, $topK, true);
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
                'source_id' => $sourceId,
                'chunk_id' => $chunkId,
                'source_type' => (string) ($payload['source_type'] ?? ''),
                'type' => (string) ($payload['type'] ?? ''),
                'tags' => is_array($payload['tags'] ?? null) ? (array) $payload['tags'] : [],
                'version' => (string) ($payload['version'] ?? ''),
                'quality_score' => (float) ($payload['quality_score'] ?? 0.0),
                'created_at' => (string) ($payload['created_at'] ?? ''),
                'content' => (string) ($payload['content'] ?? ''),
            ];
        }

        return [
            'ok' => true,
            'rag_hit' => !empty($hits),
            'hits' => $hits,
            'source_ids' => $sourceIds,
            'evidence_ids' => $evidenceIds,
            'telemetry' => [
                'retrieval_attempted' => true,
                'retrieval_result_count' => count($hits),
                'collection' => $this->vectorStore->collectionName(),
                'distance' => 'Cosine',
                'top_k' => $topK,
                'tenant_id' => $tenantId,
                'app_id' => $appId,
            ],
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $chunks
     * @return array{chunks:array<int,array<string,mixed>>,deduped:int,dropped:int}
     */
    private function sanitizeChunks(array $chunks): array
    {
        $accepted = [];
        $seen = [];
        $deduped = 0;
        $dropped = 0;

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

            $dedupeKey = implode('|', [
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
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function buildScopeFilter(string $tenantId, ?string $appId): array
    {
        $must = [
            [
                'key' => 'tenant_id',
                'match' => ['value' => $tenantId],
            ],
        ];

        if ($appId !== null && $appId !== '') {
            $must[] = [
                'key' => 'app_id',
                'match' => ['value' => $appId],
            ];
        }

        return ['must' => $must];
    }
}

