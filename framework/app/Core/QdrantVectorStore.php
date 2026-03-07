<?php
// app/Core/QdrantVectorStore.php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class QdrantVectorStore
{
    private const CANONICAL_DISTANCE = 'Cosine';
    private const CANONICAL_DIMENSION = 768;
    private const DEFAULT_COLLECTION = 'suki_akp_default';

    private string $baseUrl;
    private string $apiKey;
    private string $collection;
    private string $memoryType;
    private int $vectorSize;
    private string $distance;
    private int $timeoutSec;

    /** @var callable|null */
    private $transport;

    /**
     * @param callable|null $transport function(string $method, string $url, array<string,string> $headers, array<string,mixed> $payload, int $timeoutSec): array{status:int,data:array<string,mixed>}
     */
    public function __construct(
        ?string $baseUrl = null,
        ?string $apiKey = null,
        ?string $collection = null,
        ?int $vectorSize = null,
        ?string $distance = null,
        ?int $timeoutSec = null,
        ?callable $transport = null,
        ?string $memoryType = null
    ) {
        $this->baseUrl = rtrim((string) ($baseUrl ?? getenv('QDRANT_URL') ?? ''), '/');
        if ($this->baseUrl === '') {
            throw new RuntimeException('QDRANT_URL requerido para usar memoria semantica.');
        }

        $this->apiKey = trim((string) ($apiKey ?? getenv('QDRANT_API_KEY') ?? ''));
        $requestedMemoryType = self::normalizeMemoryType((string) ($memoryType ?? getenv('SEMANTIC_MEMORY_TYPE') ?? ''));
        $fallbackCollection = trim((string) (getenv('QDRANT_COLLECTION') ?? self::DEFAULT_COLLECTION));
        if ($fallbackCollection === '') {
            $fallbackCollection = self::DEFAULT_COLLECTION;
        }
        $explicitCollection = trim((string) ($collection ?? ''));
        if ($explicitCollection !== '') {
            $this->collection = $explicitCollection;
        } else {
            $this->collection = self::resolveCollection($requestedMemoryType, $fallbackCollection);
        }
        if ($this->collection === '') {
            throw new RuntimeException('QDRANT_COLLECTION requerido para memoria semantica.');
        }
        $this->memoryType = self::normalizeMemoryType($requestedMemoryType);

        $resolvedSize = (int) ($vectorSize ?? getenv('EMBEDDING_OUTPUT_DIMENSIONALITY') ?: self::CANONICAL_DIMENSION);
        if ($resolvedSize !== self::CANONICAL_DIMENSION) {
            throw new RuntimeException('Qdrant vector size no canonico. Se requiere 768.');
        }
        $this->vectorSize = $resolvedSize;

        $resolvedDistance = trim((string) ($distance ?? getenv('EMBEDDING_DISTANCE') ?? self::CANONICAL_DISTANCE));
        if (strcasecmp($resolvedDistance, self::CANONICAL_DISTANCE) !== 0) {
            throw new RuntimeException('Qdrant distance no canonica. Se requiere Cosine.');
        }
        $this->distance = self::CANONICAL_DISTANCE;

        $this->timeoutSec = max(3, (int) ($timeoutSec ?? getenv('QDRANT_TIMEOUT_SEC') ?: 10));
        $this->transport = $transport;
    }

    public function collectionName(): string
    {
        return $this->collection;
    }

    /**
     * @return array{base_url:string,collection:string,memory_type:string,size:int,distance:string}
     */
    public function profile(): array
    {
        return [
            'base_url' => $this->baseUrl,
            'collection' => $this->collection,
            'memory_type' => $this->memoryType !== '' ? $this->memoryType : 'unspecified',
            'size' => $this->vectorSize,
            'distance' => $this->distance,
        ];
    }

    public static function resolveCollection(string $memoryType, ?string $fallbackCollection = null): string
    {
        $memoryType = self::normalizeMemoryType($memoryType);
        if ($memoryType === 'agent_training') {
            return self::resolveCollectionEnv('AGENT_TRAINING_COLLECTION', 'agent_training');
        }
        if ($memoryType === 'sector_knowledge') {
            return self::resolveCollectionEnv('SECTOR_KNOWLEDGE_COLLECTION', 'sector_knowledge');
        }
        if ($memoryType === 'user_memory') {
            return self::resolveCollectionEnv('USER_MEMORY_COLLECTION', 'user_memory');
        }

        $fallbackCollection = trim((string) ($fallbackCollection ?? getenv('QDRANT_COLLECTION') ?? self::DEFAULT_COLLECTION));
        if ($fallbackCollection !== '') {
            return $fallbackCollection;
        }

        return self::DEFAULT_COLLECTION;
    }

    /**
     * @return array{ok:bool,status:int,collection:string}
     */
    public function ensureCollection(): array
    {
        $payload = [
            'vectors' => [
                'size' => $this->vectorSize,
                'distance' => $this->distance,
            ],
        ];

        $response = $this->request('PUT', '/collections/' . rawurlencode($this->collection), $payload, true);
        $status = (int) ($response['status'] ?? 0);
        if ($status < 200 || $status >= 300) {
            $data = is_array($response['data'] ?? null) ? (array) $response['data'] : [];
            $rawMessage = strtolower(trim((string) ($data['status']['error'] ?? $data['error'] ?? $data['message'] ?? '')));
            if ($rawMessage !== '' && str_contains($rawMessage, 'already exists')) {
                return [
                    'ok' => true,
                    'status' => $status,
                    'collection' => $this->collection,
                ];
            }
            throw new RuntimeException($this->extractErrorMessage($data, $status, 'Qdrant ensureCollection'));
        }

        return [
            'ok' => true,
            'status' => $status,
            'collection' => $this->collection,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $points
     * @return array{ok:bool,upserted:int,status:int}
     */
    public function upsertPoints(array $points, bool $wait = true): array
    {
        $normalized = [];
        foreach ($points as $point) {
            if (!is_array($point)) {
                continue;
            }
            $id = $point['id'] ?? null;
            if (!is_string($id) && !is_int($id)) {
                throw new RuntimeException('Qdrant point id invalido.');
            }
            $vector = $this->normalizeVector($point['vector'] ?? []);
            $payload = is_array($point['payload'] ?? null) ? (array) $point['payload'] : [];
            $normalized[] = [
                'id' => $id,
                'vector' => $vector,
                'payload' => $payload,
            ];
        }

        if (empty($normalized)) {
            return ['ok' => true, 'upserted' => 0, 'status' => 200];
        }

        $path = '/collections/' . rawurlencode($this->collection) . '/points?wait=' . ($wait ? 'true' : 'false');
        $response = $this->request('PUT', $path, ['points' => $normalized], false);

        return [
            'ok' => true,
            'upserted' => count($normalized),
            'status' => (int) ($response['status'] ?? 200),
        ];
    }

    /**
     * @param array<int,float|int|string> $vector
     * @param array<string,mixed> $filter
     * @return array<int,array<string,mixed>>
     */
    public function query(array $vector, array $filter = [], int $limit = 5, bool $withPayload = true): array
    {
        $vector = $this->normalizeVector($vector);
        $limit = max(1, $limit);

        $payload = [
            'query' => $vector,
            'limit' => $limit,
            'with_payload' => $withPayload,
            'with_vector' => false,
        ];
        if (!empty($filter)) {
            $payload['filter'] = $filter;
        }

        $path = '/collections/' . rawurlencode($this->collection) . '/points/query';
        $response = $this->request('POST', $path, $payload, true);
        $status = (int) ($response['status'] ?? 0);
        $data = is_array($response['data'] ?? null) ? (array) $response['data'] : [];

        if ($status < 200 || $status >= 300) {
            // Qdrant versions before query endpoint support /points/search.
            $fallbackPayload = [
                'vector' => $vector,
                'limit' => $limit,
                'with_payload' => $withPayload,
                'with_vector' => false,
            ];
            if (!empty($filter)) {
                $fallbackPayload['filter'] = $filter;
            }
            $fallbackPath = '/collections/' . rawurlencode($this->collection) . '/points/search';
            $fallback = $this->request('POST', $fallbackPath, $fallbackPayload, false);
            $data = is_array($fallback['data'] ?? null) ? (array) $fallback['data'] : [];
        }

        return $this->normalizeSearchResult($data);
    }

    /**
     * @param array<int,float|int|string> $vector
     * @return array<int,float>
     */
    private function normalizeVector(array $vector): array
    {
        if (empty($vector)) {
            throw new RuntimeException('Vector vacio para Qdrant.');
        }

        $normalized = [];
        foreach ($vector as $value) {
            if (!is_numeric($value)) {
                throw new RuntimeException('Vector contiene valor no numerico.');
            }
            $normalized[] = (float) $value;
        }

        if (count($normalized) !== $this->vectorSize) {
            throw new RuntimeException(
                'Vector con dimensionalidad invalida. Esperado '
                . $this->vectorSize
                . ', recibido '
                . count($normalized)
                . '.'
            );
        }

        return $normalized;
    }

    /**
     * @param array<string,mixed> $data
     * @return array<int,array<string,mixed>>
     */
    private function normalizeSearchResult(array $data): array
    {
        $result = $data['result'] ?? [];
        if (is_array($result) && is_array($result['points'] ?? null)) {
            $points = (array) $result['points'];
        } elseif (is_array($result)) {
            $points = $result;
        } else {
            $points = [];
        }

        $normalized = [];
        foreach ($points as $point) {
            if (!is_array($point)) {
                continue;
            }
            $normalized[] = [
                'id' => $point['id'] ?? null,
                'score' => isset($point['score']) && is_numeric($point['score']) ? (float) $point['score'] : null,
                'payload' => is_array($point['payload'] ?? null) ? (array) $point['payload'] : [],
            ];
        }
        return $normalized;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{status:int,data:array<string,mixed>}
     */
    private function request(string $method, string $path, array $payload = [], bool $allowErrors = false): array
    {
        $url = $this->baseUrl . '/' . ltrim($path, '/');
        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
        ];
        if ($this->apiKey !== '') {
            $headers[] = 'api-key: ' . $this->apiKey;
        }

        if ($this->transport !== null) {
            $response = ($this->transport)($method, $url, $headers, $payload, $this->timeoutSec);
            if (!is_array($response)) {
                throw new RuntimeException('Transporte Qdrant devolvio respuesta invalida.');
            }
            $status = (int) ($response['status'] ?? 0);
            $data = is_array($response['data'] ?? null) ? (array) $response['data'] : [];
            if (!$allowErrors && ($status < 200 || $status >= 300)) {
                throw new RuntimeException($this->extractErrorMessage($data, $status, 'Qdrant'));
            }
            return ['status' => $status, 'data' => $data];
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('No se pudo iniciar cliente Qdrant.');
        }

        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper(trim($method)));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeoutSec);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        if (!empty($payload)) {
            curl_setopt(
                $ch,
                CURLOPT_POSTFIELDS,
                json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
        }

        $responseBody = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($responseBody === false) {
            throw new RuntimeException('Fallo HTTP Qdrant: ' . $error);
        }

        $decoded = json_decode((string) $responseBody, true);
        $data = is_array($decoded) ? $decoded : ['raw' => (string) $responseBody];
        if (!$allowErrors && ($status < 200 || $status >= 300)) {
            throw new RuntimeException($this->extractErrorMessage($data, $status, 'Qdrant'));
        }

        return ['status' => $status, 'data' => $data];
    }

    /**
     * @param array<string,mixed> $data
     */
    private function extractErrorMessage(array $data, int $status, string $prefix): string
    {
        $message = trim((string) ($data['status']['error'] ?? $data['error'] ?? $data['message'] ?? ''));
        if ($message !== '') {
            return $prefix . ' error: ' . $message;
        }
        return $prefix . ' error HTTP ' . $status . '.';
    }

    private static function normalizeMemoryType(string $memoryType): string
    {
        $memoryType = strtolower(trim($memoryType));
        return in_array($memoryType, ['agent_training', 'sector_knowledge', 'user_memory'], true)
            ? $memoryType
            : '';
    }

    private static function resolveCollectionEnv(string $envKey, string $default): string
    {
        $value = trim((string) (getenv($envKey) ?: $default));
        if ($value === '') {
            return $default;
        }
        return $value;
    }
}
