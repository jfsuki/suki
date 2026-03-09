<?php
// app/Core/SemanticChunkContract.php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class SemanticChunkContract
{
    /**
     * @var array<int,string>
     */
    public const REQUIRED_PAYLOAD_FIELDS = [
        'memory_type',
        'tenant_id',
        'app_id',
        'agent_role',
        'sector',
        'source_type',
        'source_id',
        'source',
        'content',
        'tags',
        'created_at',
        'updated_at',
        'metadata',
        'chunk_id',
        'type',
        'version',
        'quality_score',
    ];

    /**
     * @param array<string,mixed> $chunk
     * @return array<string,mixed>
     */
    public static function normalizeChunk(array $chunk): array
    {
        $content = trim((string) ($chunk['content'] ?? $chunk['text'] ?? ''));
        if ($content === '') {
            throw new RuntimeException('Chunk invalido: content requerido.');
        }

        $tenantId = trim((string) ($chunk['tenant_id'] ?? ''));
        if ($tenantId === '') {
            throw new RuntimeException('Chunk invalido: tenant_id requerido.');
        }

        $sourceType = trim((string) ($chunk['source_type'] ?? ''));
        if ($sourceType === '') {
            throw new RuntimeException('Chunk invalido: source_type requerido.');
        }

        $memoryType = self::normalizeMemoryType(
            trim((string) ($chunk['memory_type'] ?? '')),
            $sourceType
        );
        if ($memoryType === '') {
            throw new RuntimeException('Chunk invalido: memory_type requerido o no reconocido.');
        }

        $sourceId = trim((string) ($chunk['source_id'] ?? ''));
        if ($sourceId === '') {
            throw new RuntimeException('Chunk invalido: source_id requerido.');
        }

        $source = trim((string) ($chunk['source'] ?? $sourceId));
        if ($source === '') {
            $source = $sourceId;
        }

        $chunkId = trim((string) ($chunk['chunk_id'] ?? ''));
        if ($chunkId === '') {
            throw new RuntimeException('Chunk invalido: chunk_id requerido.');
        }

        $type = trim((string) ($chunk['type'] ?? 'knowledge'));
        if ($type === '') {
            $type = 'knowledge';
        }

        $appId = self::normalizeNullableString($chunk['app_id'] ?? null);
        $tags = self::normalizeTags($chunk['tags'] ?? []);
        $agentRole = self::normalizeNullableString(
            $chunk['agent_role'] ?? self::extractTaggedValue($tags, 'agent_role')
        );
        $sector = self::normalizeNullableString(
            $chunk['sector'] ?? self::extractTaggedValue($tags, 'sector')
        );

        $version = trim((string) ($chunk['version'] ?? getenv('AKP_VERSION') ?: '1.0.0'));
        if ($version === '') {
            $version = '1.0.0';
        }

        $qualityScore = $chunk['quality_score'] ?? 1.0;
        if (!is_numeric($qualityScore)) {
            throw new RuntimeException('Chunk invalido: quality_score debe ser numerico.');
        }
        $quality = max(0.0, min(1.0, (float) $qualityScore));

        $createdAt = trim((string) ($chunk['created_at'] ?? $chunk['timestamp'] ?? date('c')));
        if ($createdAt === '') {
            $createdAt = date('c');
        }

        $updatedAt = trim((string) ($chunk['updated_at'] ?? $createdAt));
        if ($updatedAt === '') {
            $updatedAt = $createdAt;
        }

        $metadata = is_array($chunk['metadata'] ?? null) ? (array) $chunk['metadata'] : [];
        $userId = self::normalizeNullableString($chunk['user_id'] ?? ($metadata['user_id'] ?? null));

        return [
            'memory_type' => $memoryType,
            'tenant_id' => $tenantId,
            'app_id' => $appId,
            'agent_role' => $agentRole,
            'sector' => $sector,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'source' => $source,
            'chunk_id' => $chunkId,
            'type' => $type,
            'tags' => $tags,
            'version' => $version,
            'quality_score' => $quality,
            'created_at' => $createdAt,
            'updated_at' => $updatedAt,
            'metadata' => $metadata,
            'content' => $content,
            'user_id' => $userId,
        ];
    }

    /**
     * @param array<string,mixed> $chunk
     * @return array<string,mixed>
     */
    public static function buildPayload(array $chunk): array
    {
        $normalized = self::normalizeChunk($chunk);
        $payload = [
            'memory_type' => $normalized['memory_type'],
            'tenant_id' => $normalized['tenant_id'],
            'app_id' => $normalized['app_id'],
            'agent_role' => $normalized['agent_role'],
            'sector' => $normalized['sector'],
            'source_type' => $normalized['source_type'],
            'source_id' => $normalized['source_id'],
            'source' => $normalized['source'],
            'chunk_id' => $normalized['chunk_id'],
            'type' => $normalized['type'],
            'tags' => $normalized['tags'],
            'version' => $normalized['version'],
            'quality_score' => $normalized['quality_score'],
            'created_at' => $normalized['created_at'],
            'updated_at' => $normalized['updated_at'],
            'metadata' => $normalized['metadata'],
            'content' => $normalized['content'],
            'content_hash' => sha1((string) $normalized['content']),
        ];

        if ($normalized['user_id'] !== null) {
            $payload['user_id'] = $normalized['user_id'];
        }

        self::validatePayload($payload);
        return $payload;
    }

    /**
     * @param array<string,mixed> $payload
     */
    public static function validatePayload(array $payload): void
    {
        foreach (self::REQUIRED_PAYLOAD_FIELDS as $key) {
            if (!array_key_exists($key, $payload)) {
                throw new RuntimeException('Payload semantico invalido: falta campo ' . $key . '.');
            }
        }

        $tenant = trim((string) ($payload['tenant_id'] ?? ''));
        if ($tenant === '') {
            throw new RuntimeException('Payload semantico invalido: tenant_id vacio.');
        }

        $memoryType = trim((string) ($payload['memory_type'] ?? ''));
        if (!in_array($memoryType, ['agent_training', 'sector_knowledge', 'user_memory'], true)) {
            throw new RuntimeException('Payload semantico invalido: memory_type no permitido.');
        }

        foreach (['source_type', 'source_id', 'source', 'chunk_id', 'type', 'version', 'created_at', 'updated_at', 'content'] as $required) {
            if (trim((string) ($payload[$required] ?? '')) === '') {
                throw new RuntimeException('Payload semantico invalido: ' . $required . ' vacio.');
            }
        }

        if (($payload['app_id'] ?? null) !== null && trim((string) $payload['app_id']) === '') {
            throw new RuntimeException('Payload semantico invalido: app_id vacio.');
        }
        if (($payload['agent_role'] ?? null) !== null && trim((string) $payload['agent_role']) === '') {
            throw new RuntimeException('Payload semantico invalido: agent_role vacio.');
        }
        if (($payload['sector'] ?? null) !== null && trim((string) $payload['sector']) === '') {
            throw new RuntimeException('Payload semantico invalido: sector vacio.');
        }
        if (($payload['user_id'] ?? null) !== null && trim((string) $payload['user_id']) === '') {
            throw new RuntimeException('Payload semantico invalido: user_id vacio.');
        }

        if (!is_array($payload['tags'] ?? null)) {
            throw new RuntimeException('Payload semantico invalido: tags debe ser arreglo.');
        }
        if (!is_array($payload['metadata'] ?? null)) {
            throw new RuntimeException('Payload semantico invalido: metadata debe ser arreglo.');
        }
        if (!is_numeric($payload['quality_score'] ?? null)) {
            throw new RuntimeException('Payload semantico invalido: quality_score no numerico.');
        }
    }

    /**
     * @param array<string,mixed> $chunk
     */
    public static function buildPointId(array $chunk): string
    {
        $normalized = self::normalizeChunk($chunk);
        $parts = [
            (string) $normalized['memory_type'],
            (string) $normalized['tenant_id'],
            (string) ($normalized['app_id'] ?? ''),
            (string) $normalized['source_id'],
            (string) $normalized['chunk_id'],
            (string) $normalized['version'],
        ];
        return substr(hash('sha256', implode('|', $parts)), 0, 32);
    }

    private static function normalizeMemoryType(string $memoryType, string $sourceType): string
    {
        $memoryType = strtolower(trim($memoryType));
        if (in_array($memoryType, ['agent_training', 'sector_knowledge', 'user_memory'], true)) {
            return $memoryType;
        }

        $sourceType = strtolower(trim($sourceType));
        if (in_array($sourceType, ['conversation', 'chat_log', 'session_state', 'user_memory'], true)) {
            return 'user_memory';
        }
        if (in_array($sourceType, ['agent_training', 'framework_docs', 'policy_pack', 'contracts'], true)) {
            return 'agent_training';
        }
        return 'sector_knowledge';
    }

    /**
     * @param mixed $value
     */
    private static function normalizeNullableString($value): ?string
    {
        $value = trim((string) $value);
        return $value !== '' ? $value : null;
    }

    /**
     * @param mixed $tagsInput
     * @return array<int,string>
     */
    private static function normalizeTags($tagsInput): array
    {
        if (is_string($tagsInput)) {
            $tagsInput = preg_split('/\s*,\s*/', $tagsInput) ?: [];
        }

        $tags = [];
        if (is_array($tagsInput)) {
            foreach ($tagsInput as $tag) {
                $tag = trim((string) $tag);
                if ($tag !== '' && !in_array($tag, $tags, true)) {
                    $tags[] = $tag;
                }
            }
        }

        return $tags;
    }

    /**
     * @param array<int,string> $tags
     */
    private static function extractTaggedValue(array $tags, string $prefix): ?string
    {
        $needle = strtolower(trim($prefix)) . ':';
        foreach ($tags as $tag) {
            $value = trim((string) $tag);
            if ($value === '') {
                continue;
            }
            if (str_starts_with(strtolower($value), $needle)) {
                $normalized = trim(substr($value, strlen($needle)));
                if ($normalized !== '') {
                    return $normalized;
                }
            }
        }
        return null;
    }
}
