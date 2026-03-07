<?php
// framework/tests/qdrant_vector_store_test.php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\QdrantVectorStore;

$failures = [];
$calls = [];

$transport = static function (string $method, string $url, array $headers, array $payload, int $timeoutSec) use (&$calls): array {
    $calls[] = [
        'method' => $method,
        'url' => $url,
        'headers' => $headers,
        'payload' => $payload,
        'timeout_sec' => $timeoutSec,
    ];

    if ($method === 'PUT' && str_contains($url, '/collections/suki_akp_default') && !str_contains($url, '/points')) {
        return ['status' => 200, 'data' => ['status' => 'ok']];
    }
    if ($method === 'PUT' && str_contains($url, '/points')) {
        return ['status' => 200, 'data' => ['result' => ['operation_id' => 10, 'status' => 'acknowledged']]];
    }
    if ($method === 'POST' && str_contains($url, '/points/query')) {
        return [
            'status' => 200,
            'data' => [
                'result' => [
                    'points' => [
                        [
                            'id' => 'pt_1',
                            'score' => 0.92,
                            'payload' => [
                                'tenant_id' => 'tenant_a',
                                'app_id' => 'app_demo',
                                'source_type' => 'playbook',
                                'source_id' => 'domain_playbooks',
                                'chunk_id' => 'chunk_1',
                                'type' => 'knowledge',
                                'tags' => ['builder'],
                                'version' => '1.0.0',
                                'quality_score' => 0.95,
                                'created_at' => '2026-03-07T00:00:00+00:00',
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    return ['status' => 404, 'data' => ['status' => ['error' => 'not found']]];
};

try {
    $store = new QdrantVectorStore(
        'http://localhost:6333',
        'qdrant_key',
        'suki_akp_default',
        768,
        'Cosine',
        5,
        $transport
    );

    $store->ensureCollection();
    $store->upsertPoints([
        [
            'id' => 'pt_1',
            'vector' => array_fill(0, 768, 0.2),
            'payload' => [
                'tenant_id' => 'tenant_a',
                'app_id' => 'app_demo',
                'source_type' => 'playbook',
                'source_id' => 'domain_playbooks',
                'chunk_id' => 'chunk_1',
                'type' => 'knowledge',
                'tags' => ['builder'],
                'version' => '1.0.0',
                'quality_score' => 0.95,
                'created_at' => '2026-03-07T00:00:00+00:00',
            ],
        ],
    ]);
    $result = $store->query(array_fill(0, 768, 0.11), [
        'must' => [
            ['key' => 'tenant_id', 'match' => ['value' => 'tenant_a']],
            ['key' => 'app_id', 'match' => ['value' => 'app_demo']],
        ],
    ], 3);

    if (count($result) !== 1) {
        $failures[] = 'Query debe devolver 1 resultado mock.';
    }
} catch (\Throwable $e) {
    $failures[] = 'QdrantVectorStore no debe fallar en flujo basico mock: ' . $e->getMessage();
}

$ensureCall = false;
$upsertCall = false;
$queryCall = false;
foreach ($calls as $call) {
    $url = (string) ($call['url'] ?? '');
    $method = (string) ($call['method'] ?? '');
    if ($method === 'PUT' && str_contains($url, '/collections/suki_akp_default') && !str_contains($url, '/points')) {
        $ensureCall = true;
        $payload = is_array($call['payload'] ?? null) ? (array) $call['payload'] : [];
        $vectors = is_array($payload['vectors'] ?? null) ? (array) $payload['vectors'] : [];
        if ((int) ($vectors['size'] ?? 0) !== 768) {
            $failures[] = 'ensureCollection debe usar size=768.';
        }
        if ((string) ($vectors['distance'] ?? '') !== 'Cosine') {
            $failures[] = 'ensureCollection debe usar distance=Cosine.';
        }
    }
    if ($method === 'PUT' && str_contains($url, '/points')) {
        $upsertCall = true;
    }
    if ($method === 'POST' && str_contains($url, '/points/query')) {
        $queryCall = true;
    }
}

if (!$ensureCall) {
    $failures[] = 'No se detecto llamada ensureCollection.';
}
if (!$upsertCall) {
    $failures[] = 'No se detecto llamada upsertPoints.';
}
if (!$queryCall) {
    $failures[] = 'No se detecto llamada query.';
}

putenv('AGENT_TRAINING_COLLECTION=agent_training');
putenv('SECTOR_KNOWLEDGE_COLLECTION=sector_knowledge');
putenv('USER_MEMORY_COLLECTION=user_memory');
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
    $mappedStore = new QdrantVectorStore(
        'http://localhost:6333',
        'qdrant_key',
        null,
        768,
        'Cosine',
        5,
        $transport,
        'sector_knowledge'
    );
    if ($mappedStore->collectionName() !== 'sector_knowledge') {
        $failures[] = 'Constructor con memory_type debe resolver colección sector_knowledge.';
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
