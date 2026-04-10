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

    /**
     * @var array<int,string>
     */
    private const ALLOWED_MEMORY_TYPES = [
        'agent_training',
        'sector_knowledge',
        'user_memory',
    ];

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
        } elseif ($requestedMemoryType !== '') {
            $this->collection = self::resolveCollectionOrFail($requestedMemoryType);
        } else {
            $this->collection = self::resolveCollection('', $fallbackCollection);
        }

        if ($this->collection === '') {
            throw new RuntimeException('QDRANT_COLLECTION requerido para memoria semantica.');
        }
        $this->memoryType = $requestedMemoryType;

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

        $this->timeoutSec = max(3, (int) ($timeoutSec ?? getenv('QDRANT_TIMEOUT_SEC') ?: 30));
        $this->transport = $transport;
    }

    public function collectionName(): string
    {
        return $this->collection;
    }

    public function memoryType(): string
    {
        return $this->memoryType;
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

    /**
     * @return array{ok:bool,exists:bool,collection:string,memory_type:string,size:int|null,distance:string|null,canonical:bool,payload_schema:array<string,mixed>}
     */
    public function inspectCollection(): array
    {
        $collectionInfo = $this->describeCollection(true);
        if ($collectionInfo === null) {
            return [
                'ok' => true,
                'exists' => false,
                'collection' => $this->collection,
                'memory_type' => $this->memoryType !== '' ? $this->memoryType : 'unspecified',
                'size' => null,
                'distance' => null,
                'canonical' => false,
                'payload_schema' => [],
            ];
        }

        $vectors = $this->extractVectorConfig($collectionInfo);
        $payloadSchema = is_array($collectionInfo['result']['payload_schema'] ?? null)
            ? (array) $collectionInfo['result']['payload_schema']
            : [];
        $size = isset($vectors['size']) ? (int) $vectors['size'] : null;
        $distance = isset($vectors['distance']) ? (string) $vectors['distance'] : null;
        $canonical = $size === $this->vectorSize
            && is_string($distance)
            && strcasecmp($distance, $this->distance) === 0;

        return [
            'ok' => true,
            'exists' => true,
            'collection' => $this->collection,
            'memory_type' => $this->memoryType !== '' ? $this->memoryType : 'unspecified',
            'size' => $size,
            'distance' => $distance,
            'canonical' => $canonical,
            'payload_schema' => $payloadSchema,
        ];
    }

    public function forMemoryType(string $memoryType): self
    {
        $memoryType = self::assertMemoryType($memoryType);

        if ($this->memoryType === $memoryType && $this->collection === self::resolveCollectionOrFail($memoryType)) {
            return $this;
        }

        return new self(
            $this->baseUrl,
            $this->apiKey,
            null,
            $this->vectorSize,
            $this->distance,
            $this->timeoutSec,
            $this->transport,
            $memoryType
        );
    }

    public static function assertMemoryType(string $memoryType): string
    {
        $memoryType = self::normalizeMemoryType($memoryType);
        if ($memoryType === '') {
            throw new RuntimeException('memory_type invalido. Permitidos: agent_training, sector_knowledge, user_memory.');
        }
        return $memoryType;
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

    public static function resolveCollectionOrFail(string $memoryType): string
    {
        return self::resolveCollection(self::assertMemoryType($memoryType), null);
    }

    /**
     * @return array{ok:bool,status:int,collection:string,created:bool}
     */
    public function ensureCollection(): array
    {
        $existing = $this->describeCollection(true);
        if ($existing !== null) {
            $this->assertCollectionConfig($existing);
            return [
                'ok' => true,
                'status' => 200,
                'collection' => $this->collection,
                'created' => false,
            ];
        }

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
                    'created' => false,
                ];
            }
            throw new RuntimeException($this->extractErrorMessage($data, $status, 'Qdrant ensureCollection'));
        }

        return [
            'ok' => true,
            'status' => $status,
            'collection' => $this->collection,
            'created' => true,
        ];
    }

    /**
     * @return array{ok:bool,collection:string,created:array<int,string>,existing:array<int,string>}
     */
    public function ensurePayloadIndexes(bool $wait = true): array
    {
        $collectionInfo = $this->describeCollection(false);
        $created = [];
        $existing = [];

        foreach ($this->payloadIndexSpecs() as $spec) {
            $fieldName = (string) $spec['field_name'];
            $isTenant = (bool) ($spec['is_tenant'] ?? false);
            if ($this->hasPayloadIndex($collectionInfo, $fieldName, $isTenant)) {
                $existing[] = $fieldName;
                continue;
            }

            $path = '/collections/' . rawurlencode($this->collection) . '/index?wait=' . ($wait ? 'true' : 'false');
            $response = $this->request('PUT', $path, $spec, true);
            $status = (int) ($response['status'] ?? 0);
            $data = is_array($response['data'] ?? null) ? (array) $response['data'] : [];
            if ($status < 200 || $status >= 300) {
                $rawMessage = strtolower(trim((string) ($data['status']['error'] ?? $data['error'] ?? $data['message'] ?? '')));
                if ($rawMessage !== '' && (str_contains($rawMessage, 'already exists') || str_contains($rawMessage, 'exists'))) {
                    $existing[] = $fieldName;
                    continue;
                }
                throw new RuntimeException($this->extractErrorMessage($data, $status, 'Qdrant ensurePayloadIndexes'));
            }

            $created[] = $fieldName;
        }

        return [
            'ok' => true,
            'collection' => $this->collection,
            'created' => $created,
            'existing' => $existing,
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

        if ($normalized === []) {
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
        if ($filter !== []) {
            $payload['filter'] = $filter;
        }

        $path = '/collections/' . rawurlencode($this->collection) . '/points/query';
        $response = $this->request('POST', $path, $payload, true);
        $status = (int) ($response['status'] ?? 0);
        $data = is_array($response['data'] ?? null) ? (array) $response['data'] : [];

        if ($status < 200 || $status >= 300) {
            $fallbackPayload = [
                'vector' => $vector,
                'limit' => $limit,
                'with_payload' => $withPayload,
                'with_vector' => false,
            ];
            if ($filter !== []) {
                $fallbackPayload['filter'] = $filter;
            }
            $fallbackPath = '/collections/' . rawurlencode($this->collection) . '/points/search';
            $fallback = $this->request('POST', $fallbackPath, $fallbackPayload, false);
            $data = is_array($fallback['data'] ?? null) ? (array) $fallback['data'] : [];
        }

        return $this->normalizeSearchResult($data);
    }

    /**
     * @return array{ok:bool,result:array<string,mixed>}
     */
    public function scroll(int $limit = 10, ?string $offset = null, bool $withPayload = true): array
    {
        $payload = [
            'limit' => $limit,
            'with_payload' => $withPayload,
            'with_vector' => false,
        ];
        if ($offset !== null) {
            $payload['offset'] = $offset;
        }

        $path = '/collections/' . rawurlencode($this->collection) . '/points/scroll';
        $response = $this->request('POST', $path, $payload, true);
        $data = is_array($response['data'] ?? null) ? (array) $response['data'] : [];

        return [
            'ok' => ($response['status'] >= 200 && $response['status'] < 300),
            'result' => $data['result'] ?? [],
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function describeCollection(bool $allowMissing): ?array
    {
        $response = $this->request('GET', '/collections/' . rawurlencode($this->collection), [], true);
        $status = (int) ($response['status'] ?? 0);
        $data = is_array($response['data'] ?? null) ? (array) $response['data'] : [];

        if ($status === 404 && $allowMissing) {
            return null;
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException($this->extractErrorMessage($data, $status, 'Qdrant describeCollection'));
        }

        return $data;
    }

    /**
     * @param array<string,mixed> $collectionInfo
     */
    private function assertCollectionConfig(array $collectionInfo): void
    {
        $vectors = $this->extractVectorConfig($collectionInfo);
        $size = (int) ($vectors['size'] ?? 0);
        $distance = trim((string) ($vectors['distance'] ?? ''));

        if ($size !== $this->vectorSize) {
            throw new RuntimeException(
                'Coleccion Qdrant con size invalido. Esperado '
                . $this->vectorSize
                . ', recibido '
                . $size
                . '.'
            );
        }
        if (strcasecmp($distance, $this->distance) !== 0) {
            throw new RuntimeException(
                'Coleccion Qdrant con distance invalido. Esperado '
                . $this->distance
                . ', recibido '
                . $distance
                . '.'
            );
        }
    }

    /**
     * @param array<string,mixed> $collectionInfo
     * @return array<string,mixed>
     */
    private function extractVectorConfig(array $collectionInfo): array
    {
        $result = is_array($collectionInfo['result'] ?? null) ? (array) $collectionInfo['result'] : [];
        $config = is_array($result['config'] ?? null) ? (array) $result['config'] : [];
        $params = is_array($config['params'] ?? null) ? (array) $config['params'] : [];
        $vectors = $params['vectors'] ?? $result['vectors'] ?? [];

        if (is_array($vectors) && array_key_exists('size', $vectors)) {
            return (array) $vectors;
        }
        if (is_array($vectors) && isset($vectors['default']) && is_array($vectors['default'])) {
            return (array) $vectors['default'];
        }
        if (is_array($vectors) && isset($vectors['']) && is_array($vectors[''])) {
            return (array) $vectors[''];
        }
        if (is_array($vectors)) {
            foreach ($vectors as $candidate) {
                if (is_array($candidate) && array_key_exists('size', $candidate)) {
                    return (array) $candidate;
                }
            }
        }

        return [];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function payloadIndexSpecs(): array
    {
        $specs = [
            [
                'field_name' => 'tenant_id',
                'field_schema' => 'keyword',
                'is_tenant' => true,
            ],
            [
                'field_name' => 'memory_type',
                'field_schema' => 'keyword',
            ],
            [
                'field_name' => 'app_id',
                'field_schema' => 'keyword',
            ],
            [
                'field_name' => 'sector',
                'field_schema' => 'keyword',
            ],
            [
                'field_name' => 'agent_role',
                'field_schema' => 'keyword',
            ],
            [
                'field_name' => 'source_id',
                'field_schema' => 'keyword',
            ],
            [
                'field_name' => 'dataset_id',
                'field_schema' => 'keyword',
            ],
        ];

        if ($this->memoryType === 'user_memory') {
            $specs[] = [
                'field_name' => 'user_id',
                'field_schema' => 'keyword',
            ];
        }

        return $specs;
    }

    /**
     * @param array<string,mixed> $collectionInfo
     */
    private function hasPayloadIndex(array $collectionInfo, string $fieldName, bool $requiresTenant): bool
    {
        $result = is_array($collectionInfo['result'] ?? null) ? (array) $collectionInfo['result'] : [];
        $payloadSchema = is_array($result['payload_schema'] ?? null) ? (array) $result['payload_schema'] : [];
        $schema = is_array($payloadSchema[$fieldName] ?? null) ? (array) $payloadSchema[$fieldName] : [];
        if ($schema === []) {
            return false;
        }
        if (!$requiresTenant) {
            return true;
        }

        $params = is_array($schema['params'] ?? null) ? (array) $schema['params'] : [];
        $isTenant = $params['is_tenant'] ?? $schema['is_tenant'] ?? false;
        return $isTenant === true || $isTenant === 1 || $isTenant === 'true';
    }

    /**
     * Gemini + cosine in Qdrant already operate on the provider vector output.
     * To keep write/query symmetry, runtime validates dimensions but does not renormalize.
     *
     * @param array<int,float|int|string> $vector
     * @return array<int,float>
     */
    private function normalizeVector(array $vector): array
    {
        if ($vector === []) {
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
        if ($payload !== []) {
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
        return in_array($memoryType, self::ALLOWED_MEMORY_TYPES, true)
            ? $memoryType
            : '';
    }

    public function getKnowledgeDistribution(): array
    {
        // 1. Get raw distribution from scroll (sample 100)
        try {
            $res = $this->scroll(100);
            $sectorsRaw = [];
            $points = $res['result']['points'] ?? [];
            foreach ($points as $p) {
                $s = $p['payload']['sector'] ?? $p['payload']['industry'] ?? $p['payload']['apps'] ?? 'General Knowledge';
                $sectorsRaw[$s] = ($sectorsRaw[$s] ?? 0) + 1;
            }
            arsort($sectorsRaw);
            
            $totalPoints = $this->countAllPoints();
            $distribution = [];
            foreach ($sectorsRaw as $label => $count) {
                $distribution[] = [
                    'id' => strtolower($label),
                    'label' => str_replace('_', ' ', $label),
                    'count' => $count,
                    'percentage' => $totalPoints > 0 ? min(100, round(($count / $totalPoints) * 100, 2)) : 0
                ];
            }
            return $distribution ?: [['label' => 'Sin Datos', 'percentage' => 0]];
        } catch (\Exception $e) {
            return [['label' => 'Error: ' . $e->getMessage(), 'percentage' => 0]];
        }
    }

    public function getDetailedKnowledgeAtlas(): array
    {
        $categories = [
            'base' => [
                'inventory' => ['label' => 'Inventario y stock', 'percentage' => 0],
                'billing' => ['label' => 'Facturación y ventas', 'percentage' => 0],
                'hr' => ['label' => 'Recursos humanos', 'percentage' => 0],
                'support' => ['label' => 'Atención al cliente', 'percentage' => 0],
                'natural_language' => ['label' => 'Lenguaje natural', 'percentage' => 0],
                'accounting' => ['label' => 'Conocimiento contable x país', 'percentage' => 0],
                'tax' => ['label' => 'Conocimiento tributario x país', 'percentage' => 0],
                'active_regions' => ['label' => 'País región activos', 'percentage' => 0],
                'antology' => ['label' => 'Lenguaje antology (país, región)', 'percentage' => 0],
                'business' => ['label' => 'Negocios', 'percentage' => 0],
                'sku_knowledge' => ['label' => 'Productos y servicios (SKU)', 'percentage' => 0],
                'enterprise_knowledge' => ['label' => 'Conocimiento de la empresa', 'percentage' => 0],
            ],
            'auto' => [
                'colloquial' => ['label' => 'Lenguaje coloquial', 'percentage' => 0],
                'regional_context' => ['label' => 'Contexto regional', 'percentage' => 0],
                'complaints' => ['label' => 'Manejo de quejas', 'percentage' => 0],
                'new_business' => ['label' => 'Nuevos negocios', 'percentage' => 0],
            ]
        ];

        // Real mapping logic based on sector/type tags in Qdrant
        try {
            $totalPoints = $this->countAllPoints();
            if ($totalPoints === 0) return $categories;

            $res = $this->scroll(200); // Sample for distribution
            $points = $res['result']['points'] ?? [];
            
            foreach ($points as $p) {
                $payload = $p['payload'] ?? [];
                $sector = $payload['sector'] ?? $payload['industry'] ?? 'unknown';
                $type = $payload['memory_type'] ?? 'unknown';

                // Map to categories
                if ($sector === 'inventory' || $sector === 'FERRETERIA_MINORISTA') $categories['base']['inventory']['percentage'] += 1;
                elseif ($sector === 'billing' || $sector === 'sales') $categories['base']['billing']['percentage'] += 1;
                elseif ($sector === 'accounting') $categories['base']['accounting']['percentage'] += 1;
                elseif ($sector === 'tax') $categories['base']['tax']['percentage'] += 1;
                elseif ($type === 'user_memory') $categories['auto']['regional_context']['percentage'] += 1;
                // ... rest of mapping
            }

            // Normalize to percentages (sample based)
            $sampleSize = count($points) ?: 1;
            foreach (['base', 'auto'] as $group) {
                foreach ($categories[$group] as $key => &$data) {
                    $data['percentage'] = min(100, round(($data['percentage'] / $sampleSize) * 300));
                    // NOTE: 0% means no indexed data for this category — do not fake it
                }
            }
        } catch (\Exception $e) {}

        return $categories;
    }

    private function countAllPoints(): int
    {
        $path = '/collections/' . rawurlencode($this->collection) . '/points/count';
        try {
            $response = $this->request('POST', $path, ['exact' => true], true);
            return (int) ($response['data']['result']['count'] ?? 0);
        } catch (\Exception $e) { return 0; }
    }

    private function countBySector(string $sectorId): int
    {
        $path = '/collections/' . rawurlencode($this->collection) . '/points/count';
        $payload = [
            'filter' => [
                'must' => [
                    ['key' => 'sector', 'match' => ['value' => $sectorId]]
                ]
            ],
            'exact' => true
        ];
        try {
            $response = $this->request('POST', $path, $payload, true);
            return (int) ($response['data']['result']['count'] ?? 0);
        } catch (\Exception $e) {
            return 0;
        }
    }

    private static function resolveCollectionEnv(string $envKey, string $default): string
    {
        $value = trim((string) (getenv($envKey) ?: $default));
        return $value !== '' ? $value : $default;
    }
}
