<?php
// framework/tests/qdrant_vector_store_test.php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\QdrantVectorStore;

$failures = [];
$calls = [];
$collections = [];

$transport = static function (string $method, string $url, array $headers, array $payload, int $timeoutSec) use (&$calls, &$collections): array {
    $calls[] = [
        'method' => $method,
        'url' => $url,
        'headers' => $headers,
        'payload' => $payload,
        'timeout_sec' => $timeoutSec,
    ];

    if (!preg_match('#/collections/([^/?]+)#', $url, $matches)) {
        return ['status' => 404, 'data' => ['status' => ['error' => 'not found']]];
    }
    $collection = rawurldecode((string) $matches[1]);

    if ($method === 'GET' && !str_contains($url, '/points')) {
        if (!isset($collections[$collection])) {
            return ['status' => 404, 'data' => ['status' => ['error' => 'not found']]];
        }

        return [
            'status' => 200,
            'data' => [
                'result' => [
                    'config' => [
                        'params' => [
                            'vectors' => $collections[$collection]['vectors'],
                        ],
                    ],
                    'payload_schema' => $collections[$collection]['payload_schema'],
                ],
            ],
        ];
    }

    if ($method === 'PUT' && str_contains($url, '/index')) {
        if (!isset($collections[$collection])) {
            return ['status' => 404, 'data' => ['status' => ['error' => 'collection missing']]];
        }

        $field = (string) ($payload['field_name'] ?? '');
        $collections[$collection]['payload_schema'][$field] = [
            'data_type' => (string) ($payload['field_schema'] ?? 'keyword'),
            'params' => [
                'is_tenant' => (bool) ($payload['is_tenant'] ?? false),
            ],
        ];

        return ['status' => 200, 'data' => ['status' => 'ok']];
    }

    if ($method === 'PUT' && !str_contains($url, '/points')) {
        $collections[$collection] = [
            'vectors' => is_array($payload['vectors'] ?? null) ? (array) $payload['vectors'] : [],
            'payload_schema' => [],
            'points' => [],
        ];
        return ['status' => 200, 'data' => ['status' => 'ok']];
    }

    if ($method === 'PUT' && str_contains($url, '/points')) {
        if (!isset($collections[$collection])) {
            return ['status' => 404, 'data' => ['status' => ['error' => 'collection missing']]];
        }
        $collections[$collection]['points'] = is_array($payload['points'] ?? null) ? (array) $payload['points'] : [];
        return ['status' => 200, 'data' => ['result' => ['operation_id' => 10, 'status' => 'acknowledged']]];
    }

    if ($method === 'POST' && str_contains($url, '/points/query')) {
        $points = [];
        foreach ((array) ($collections[$collection]['points'] ?? []) as $point) {
            if (!is_array($point)) {
                continue;
            }
            $points[] = [
                'id' => $point['id'] ?? null,
                'score' => 0.92,
                'payload' => is_array($point['payload'] ?? null) ? (array) $point['payload'] : [],
            ];
        }

        return [
            'status' => 200,
            'data' => [
                'result' => [
                    'points' => $points,
                ],
            ],
        ];
    }

    return ['status' => 404, 'data' => ['status' => ['error' => 'not found']]];
};

putenv('QDRANT_COLLECTION=suki_akp_default');
putenv('AGENT_TRAINING_COLLECTION=agent_training');
putenv('SECTOR_KNOWLEDGE_COLLECTION=sector_knowledge');
putenv('USER_MEMORY_COLLECTION=user_memory');

try {
    $store = new QdrantVectorStore(
        'http://localhost:6333',
        'qdrant_key',
        null,
        768,
        'Cosine',
        5,
        $transport,
        'sector_knowledge'
    );

    $firstEnsure = $store->ensureCollection();
    $secondEnsure = $store->ensureCollection();
    $inspection = $store->inspectCollection();
    if (!(bool) ($firstEnsure['created'] ?? false)) {
        $failures[] = 'ensureCollection debe crear la coleccion cuando no existe.';
    }
    if ((bool) ($secondEnsure['created'] ?? true)) {
        $failures[] = 'ensureCollection debe ser idempotente cuando la coleccion ya existe.';
    }
    if (($inspection['exists'] ?? false) !== true || ($inspection['canonical'] ?? false) !== true) {
        $failures[] = 'inspectCollection debe reportar coleccion canonica existente.';
    }

    $firstIndexes = $store->ensurePayloadIndexes();
    $secondIndexes = $store->ensurePayloadIndexes();
    if (count((array) ($firstIndexes['created'] ?? [])) < 5) {
        $failures[] = 'ensurePayloadIndexes debe crear indexes canonicos para sector_knowledge.';
    }
    if (count((array) ($secondIndexes['created'] ?? [])) !== 0) {
        $failures[] = 'ensurePayloadIndexes debe ser idempotente en segunda llamada.';
    }

    $store->upsertPoints([
        [
            'id' => 'pt_1',
            'vector' => array_fill(0, 768, 0.2),
            'payload' => [
                'memory_type' => 'sector_knowledge',
                'tenant_id' => 'tenant_a',
                'app_id' => 'app_demo',
                'agent_role' => null,
                'sector' => 'retail',
                'source_type' => 'playbook',
                'source_id' => 'domain_playbooks',
                'source' => 'domain_playbooks',
                'chunk_id' => 'chunk_1',
                'type' => 'knowledge',
                'tags' => ['builder'],
                'version' => '1.0.0',
                'quality_score' => 0.95,
                'created_at' => '2026-03-07T00:00:00+00:00',
                'updated_at' => '2026-03-07T00:00:00+00:00',
                'metadata' => [],
                'content' => 'knowledge chunk',
            ],
        ],
    ]);
    $result = $store->query(array_fill(0, 768, 0.11), [
        'must' => [
            ['key' => 'memory_type', 'match' => ['value' => 'sector_knowledge']],
            ['key' => 'tenant_id', 'match' => ['value' => 'tenant_a']],
            ['key' => 'app_id', 'match' => ['value' => 'app_demo']],
        ],
    ], 3);

    if (count($result) !== 1) {
        $failures[] = 'Query debe devolver 1 resultado mock.';
    }

    $collections['agent_training'] = [
        'vectors' => [
            '' => [
                'size' => 768,
                'distance' => 'Cosine',
            ],
        ],
        'payload_schema' => [],
        'points' => [],
    ];
    $cloudStyleStore = new QdrantVectorStore(
        'http://localhost:6333',
        'qdrant_key',
        null,
        768,
        'Cosine',
        5,
        $transport,
        'agent_training'
    );
    $cloudInspection = $cloudStyleStore->inspectCollection();
    if (($cloudInspection['canonical'] ?? false) !== true || (int) ($cloudInspection['size'] ?? 0) !== 768) {
        $failures[] = 'inspectCollection debe aceptar config Qdrant Cloud con clave vacia en vectors.';
    }
} catch (\Throwable $e) {
    $failures[] = 'QdrantVectorStore no debe fallar en flujo mock: ' . $e->getMessage();
}

$collectionCreates = 0;
$indexCreates = [];
$sectorCollectionUsed = false;
foreach ($calls as $call) {
    $url = (string) ($call['url'] ?? '');
    $method = (string) ($call['method'] ?? '');
    if (str_contains($url, '/collections/sector_knowledge')) {
        $sectorCollectionUsed = true;
    }
    if ($method === 'PUT' && str_contains($url, '/collections/sector_knowledge') && !str_contains($url, '/points') && !str_contains($url, '/index')) {
        $collectionCreates++;
        $payload = is_array($call['payload'] ?? null) ? (array) $call['payload'] : [];
        $vectors = is_array($payload['vectors'] ?? null) ? (array) $payload['vectors'] : [];
        if ((int) ($vectors['size'] ?? 0) !== 768) {
            $failures[] = 'ensureCollection debe usar size=768.';
        }
        if ((string) ($vectors['distance'] ?? '') !== 'Cosine') {
            $failures[] = 'ensureCollection debe usar distance=Cosine.';
        }
    }
    if ($method === 'PUT' && str_contains($url, '/collections/sector_knowledge/index')) {
        $payload = is_array($call['payload'] ?? null) ? (array) $call['payload'] : [];
        $field = (string) ($payload['field_name'] ?? '');
        if ($field !== '') {
            $indexCreates[$field] = $payload;
        }
    }
}

if (!$sectorCollectionUsed) {
    $failures[] = 'Operaciones con memory_type=sector_knowledge deben usar esa coleccion.';
}
if ($collectionCreates !== 1) {
    $failures[] = 'ensureCollection no debe recrear la coleccion existente.';
}
foreach (['tenant_id', 'memory_type', 'app_id', 'sector', 'agent_role'] as $requiredIndex) {
    if (!isset($indexCreates[$requiredIndex])) {
        $failures[] = 'Falta creacion de payload index: ' . $requiredIndex . '.';
    }
}
if ((bool) ($indexCreates['tenant_id']['is_tenant'] ?? false) !== true) {
    $failures[] = 'tenant_id debe marcarse con is_tenant=true.';
}

if (QdrantVectorStore::resolveCollection('agent_training', 'fallback_collection') !== 'agent_training') {
    $failures[] = 'resolveCollection debe mapear agent_training -> agent_training.';
}
if (QdrantVectorStore::resolveCollection('sector_knowledge', 'fallback_collection') !== 'sector_knowledge') {
    $failures[] = 'resolveCollection debe mapear sector_knowledge -> sector_knowledge.';
}
if (QdrantVectorStore::resolveCollection('user_memory', 'fallback_collection') !== 'user_memory') {
    $failures[] = 'resolveCollection debe mapear user_memory -> user_memory.';
}
if (QdrantVectorStore::resolveCollection('unknown_type', 'fallback_collection') !== 'fallback_collection') {
    $failures[] = 'resolveCollection debe usar fallback para memory_type desconocido.';
}

try {
    QdrantVectorStore::resolveCollectionOrFail('unknown_type');
    $failures[] = 'resolveCollectionOrFail debe bloquear memory_type desconocido.';
} catch (\Throwable $e) {
    // expected
}

try {
    $mappedStore = new QdrantVectorStore(
        'http://localhost:6333',
        'qdrant_key',
        null,
        768,
        'Cosine',
        5,
        $transport,
        'user_memory'
    );
    if ($mappedStore->collectionName() !== 'user_memory') {
        $failures[] = 'Constructor con memory_type debe resolver coleccion user_memory.';
    }
} catch (\Throwable $e) {
    $failures[] = 'Constructor con memory_type no debe fallar: ' . $e->getMessage();
}

try {
    new QdrantVectorStore(
        'http://localhost:6333',
        'qdrant_key',
        'suki_akp_default',
        1024,
        'Cosine',
        5,
        $transport
    );
    $failures[] = 'Debe bloquear vector size no canonico.';
} catch (\Throwable $e) {
    // expected
}

$ok = empty($failures);
echo json_encode([
    'ok' => $ok,
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 1);
