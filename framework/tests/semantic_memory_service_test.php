<?php
// framework/tests/semantic_memory_service_test.php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\GeminiEmbeddingService;
use App\Core\QdrantVectorStore;
use App\Core\SemanticMemoryService;

$failures = [];
$lastQueryPayload = [];
$collections = [];
$collectionCalls = [];

$embeddingTransport = static function (string $method, string $url, array $headers, array $payload, int $timeoutSec): array {
    $text = trim((string) ($payload['content']['parts'][0]['text'] ?? ''));
    $seed = max(1, strlen($text));
    $value = min(1.0, $seed / 100.0);
    return [
        'status' => 200,
        'data' => [
            'embedding' => [
                'values' => array_fill(0, 768, $value),
            ],
        ],
    ];
};

$qdrantTransport = static function (string $method, string $url, array $headers, array $payload, int $timeoutSec) use (&$collections, &$lastQueryPayload, &$collectionCalls): array {
    if (!preg_match('#/collections/([^/?]+)#', $url, $matches)) {
        return ['status' => 404, 'data' => ['status' => ['error' => 'not found']]];
    }
    $collection = rawurldecode((string) $matches[1]);
    $collectionCalls[] = $collection;

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
        $collections[$collection]['points'] = is_array($payload['points'] ?? null) ? (array) $payload['points'] : [];
        return ['status' => 200, 'data' => ['result' => ['status' => 'acknowledged']]];
    }

    if ($method === 'POST' && str_contains($url, '/points/query')) {
        $lastQueryPayload = $payload;
        $points = [];
        foreach ((array) ($collections[$collection]['points'] ?? []) as $point) {
            if (!is_array($point)) {
                continue;
            }
            $pointPayload = is_array($point['payload'] ?? null) ? (array) $point['payload'] : [];
            if (!matchesFilter($pointPayload, is_array($payload['filter'] ?? null) ? (array) $payload['filter'] : [])) {
                continue;
            }
            $points[] = [
                'id' => $point['id'] ?? null,
                'score' => 0.91,
                'payload' => $pointPayload,
            ];
        }
        return [
            'status' => 200,
            'data' => ['result' => ['points' => $points]],
        ];
    }

    return ['status' => 404, 'data' => ['status' => ['error' => 'not found']]];
};

putenv('QDRANT_COLLECTION=suki_akp_default');
putenv('AGENT_TRAINING_COLLECTION=agent_training');
putenv('SECTOR_KNOWLEDGE_COLLECTION=sector_knowledge');
putenv('USER_MEMORY_COLLECTION=user_memory');

try {
    $embedding = new GeminiEmbeddingService(
        'test_key',
        'gemini-embedding-001',
        768,
        'https://generativelanguage.googleapis.com/v1beta',
        5,
        $embeddingTransport
    );
    $prototype = new QdrantVectorStore(
        'http://localhost:6333',
        '',
        'suki_akp_default',
        768,
        'Cosine',
        5,
        $qdrantTransport
    );
    $service = new SemanticMemoryService($embedding, $prototype, 3);

    $ingestSector = $service->ingestSectorKnowledge([
        [
            'memory_type' => 'sector_knowledge',
            'tenant_id' => 'tenant_demo',
            'app_id' => 'app_demo',
            'sector' => 'retail',
            'source_type' => 'playbook',
            'source_id' => 'domain_playbooks',
            'source' => 'domain_playbooks',
            'chunk_id' => 'chunk_001',
            'type' => 'knowledge',
            'tags' => ['builder', 'inventory', 'sector:retail'],
            'version' => '1.0.0',
            'quality_score' => 0.97,
            'created_at' => '2026-03-07T00:00:00+00:00',
            'updated_at' => '2026-03-07T00:00:00+00:00',
            'metadata' => ['channel' => 'training_dataset'],
            'content' => 'Control de inventario por lote y ubicacion.',
        ],
        [
            'memory_type' => 'sector_knowledge',
            'tenant_id' => 'tenant_demo',
            'app_id' => 'app_demo',
            'sector' => 'retail',
            'source_type' => 'playbook',
            'source_id' => 'domain_playbooks',
            'source' => 'domain_playbooks',
            'chunk_id' => 'chunk_001',
            'type' => 'knowledge',
            'tags' => ['builder', 'inventory', 'sector:retail'],
            'version' => '1.0.0',
            'quality_score' => 0.97,
            'created_at' => '2026-03-07T00:00:00+00:00',
            'updated_at' => '2026-03-07T00:00:00+00:00',
            'metadata' => ['channel' => 'training_dataset'],
            'content' => 'Control de inventario por lote y ubicacion.',
        ],
    ]);

    $ingestAgent = $service->ingestAgentTraining([
        [
            'memory_type' => 'agent_training',
            'tenant_id' => 'tenant_demo',
            'app_id' => null,
            'agent_role' => 'ops',
            'sector' => null,
            'source_type' => 'framework_docs',
            'source_id' => 'ops_policy',
            'source' => 'ops_policy',
            'chunk_id' => 'chunk_ops_001',
            'type' => 'policy',
            'tags' => ['agent_role:ops'],
            'version' => '1.0.0',
            'quality_score' => 0.9,
            'created_at' => '2026-03-07T00:00:00+00:00',
            'updated_at' => '2026-03-07T00:00:00+00:00',
            'metadata' => [],
            'content' => 'El operador valida contratos antes de ejecutar.',
        ],
    ]);

    $ingestUserMemory = $service->ingestUserMemory([
        [
            'memory_type' => 'user_memory',
            'tenant_id' => 'tenant_demo',
            'app_id' => 'app_demo',
            'source_type' => 'conversation',
            'source_id' => 'conv_001',
            'source' => 'conv_001',
            'chunk_id' => 'memory_001',
            'type' => 'conversation',
            'tags' => ['session'],
            'version' => '1.0.0',
            'quality_score' => 0.88,
            'created_at' => '2026-03-07T00:00:00+00:00',
            'updated_at' => '2026-03-07T00:00:00+00:00',
            'metadata' => [],
            'user_id' => 'user_a',
            'content' => 'El usuario prefiere facturas semanales.',
        ],
        [
            'memory_type' => 'user_memory',
            'tenant_id' => 'tenant_other',
            'app_id' => 'app_demo',
            'source_type' => 'conversation',
            'source_id' => 'conv_002',
            'source' => 'conv_002',
            'chunk_id' => 'memory_002',
            'type' => 'conversation',
            'tags' => ['session'],
            'version' => '1.0.0',
            'quality_score' => 0.88,
            'created_at' => '2026-03-07T00:00:00+00:00',
            'updated_at' => '2026-03-07T00:00:00+00:00',
            'metadata' => [],
            'user_id' => 'user_b',
            'content' => 'Memoria de otro tenant.',
        ],
    ]);

    if ((int) ($ingestSector['accepted'] ?? 0) !== 1) {
        $failures[] = 'Ingest sector_knowledge debe deduplicar chunks repetidos.';
    }
    if ((string) ($ingestSector['collection'] ?? '') !== 'sector_knowledge') {
        $failures[] = 'Ingest sector_knowledge debe resolver la coleccion canonica.';
    }
    if ((string) ($ingestAgent['collection'] ?? '') !== 'agent_training') {
        $failures[] = 'Ingest agent_training debe resolver la coleccion canonica.';
    }
    if ((string) ($ingestUserMemory['collection'] ?? '') !== 'user_memory') {
        $failures[] = 'Ingest user_memory debe resolver la coleccion canonica.';
    }

    $sectorPoints = (array) ($collections['sector_knowledge']['points'] ?? []);
    if ($sectorPoints === []) {
        $failures[] = 'Ingest sector_knowledge debe persistir puntos.';
    } else {
        $payload = is_array($sectorPoints[0]['payload'] ?? null) ? (array) $sectorPoints[0]['payload'] : [];
        foreach (['tenant_id', 'memory_type', 'app_id', 'agent_role', 'sector', 'source_type', 'source_id', 'source', 'content', 'tags', 'created_at', 'updated_at', 'metadata'] as $requiredField) {
            if (!array_key_exists($requiredField, $payload)) {
                $failures[] = 'Payload canónico incompleto: falta ' . $requiredField . '.';
            }
        }
        if ((string) ($payload['memory_type'] ?? '') !== 'sector_knowledge') {
            $failures[] = 'Payload persistido debe conservar memory_type.';
        }
    }

    $retrieval = $service->retrieveSectorKnowledge('Necesito control de inventario', [
        'tenant_id' => 'tenant_demo',
        'app_id' => 'app_demo',
        'sector' => 'retail',
    ], 3);

    if (!(bool) ($retrieval['rag_hit'] ?? false)) {
        $failures[] = 'Retrieval sector_knowledge debe reportar rag_hit=true.';
    }
    if (count((array) ($retrieval['hits'] ?? [])) < 1) {
        $failures[] = 'Retrieval sector_knowledge debe devolver al menos un hit.';
    }

    $agentRetrieval = $service->retrieveAgentTraining('Necesito revisar politicas', [
        'tenant_id' => 'tenant_demo',
        'agent_role' => 'ops',
    ], 3);
    if (!(bool) ($agentRetrieval['rag_hit'] ?? false)) {
        $failures[] = 'Retrieval agent_training debe usar su coleccion y devolver evidencia.';
    }

    $userRetrieval = $service->retrieveUserMemory('Quiero ver mis facturas', [
        'tenant_id' => 'tenant_demo',
        'app_id' => 'app_demo',
        'user_id' => 'user_a',
    ], 5);
    if (count((array) ($userRetrieval['hits'] ?? [])) !== 1) {
        $failures[] = 'user_memory no debe mezclar tenants ni usuarios.';
    }
    $userHit = (array) (($userRetrieval['hits'] ?? [])[0] ?? []);
    if ((string) ($userHit['tenant_id'] ?? '') !== 'tenant_demo' || (string) ($userHit['user_id'] ?? '') !== 'user_a') {
        $failures[] = 'Retrieval user_memory debe respetar tenant_id y user_id.';
    }
} catch (\Throwable $e) {
    $failures[] = 'SemanticMemoryService no debe fallar en flujo mock: ' . $e->getMessage();
}

$must = is_array($lastQueryPayload['filter']['must'] ?? null) ? (array) $lastQueryPayload['filter']['must'] : [];
$requiredMatches = [
    'memory_type' => 'user_memory',
    'tenant_id' => 'tenant_demo',
    'app_id' => 'app_demo',
    'user_id' => 'user_a',
];
foreach ($requiredMatches as $key => $expected) {
    $matched = false;
    foreach ($must as $condition) {
        if (!is_array($condition)) {
            continue;
        }
        if ((string) ($condition['key'] ?? '') === $key && (string) ($condition['match']['value'] ?? '') === $expected) {
            $matched = true;
            break;
        }
    }
    if (!$matched) {
        $failures[] = 'Retrieval debe forzar filtro ' . $key . '.';
    }
}

if (in_array('suki_akp_default', $collectionCalls, true)) {
    $failures[] = 'Rutas semanticas reales no deben operar sobre la coleccion default generica.';
}
foreach (['sector_knowledge', 'agent_training', 'user_memory'] as $requiredCollection) {
    if (!in_array($requiredCollection, $collectionCalls, true)) {
        $failures[] = 'Falta uso runtime de coleccion ' . $requiredCollection . '.';
    }
}

$prepared = SemanticMemoryService::prepareContext([
    'hits' => [
        [
            'chunk_id' => 'dup_1',
            'source_id' => 'source_a',
            'source' => 'source_a',
            'content' => 'Contexto A',
            'memory_type' => 'sector_knowledge',
            'tenant_id' => 'tenant_demo',
            'score' => 0.9,
        ],
        [
            'chunk_id' => 'dup_1',
            'source_id' => 'source_a',
            'source' => 'source_a',
            'content' => 'Contexto A',
            'memory_type' => 'sector_knowledge',
            'tenant_id' => 'tenant_demo',
            'score' => 0.89,
        ],
        [
            'chunk_id' => 'uniq_2',
            'source_id' => 'source_b',
            'source' => 'source_b',
            'content' => 'Contexto B',
            'memory_type' => 'sector_knowledge',
            'tenant_id' => 'tenant_demo',
            'score' => 0.88,
        ],
        [
            'chunk_id' => 'uniq_3',
            'source_id' => 'source_c',
            'source' => 'source_c',
            'content' => 'Contexto C',
            'memory_type' => 'sector_knowledge',
            'tenant_id' => 'tenant_demo',
            'score' => 0.87,
        ],
        [
            'chunk_id' => 'uniq_4',
            'source_id' => 'source_d',
            'source' => 'source_d',
            'content' => 'Contexto D',
            'memory_type' => 'sector_knowledge',
            'tenant_id' => 'tenant_demo',
            'score' => 0.86,
        ],
    ],
]);
if ((int) ($prepared['used_count'] ?? 0) !== 3) {
    $failures[] = 'prepareContext debe deduplicar y limitar chunks al maximo configurado.';
}
if (count((array) ($prepared['source_ids'] ?? [])) !== 3) {
    $failures[] = 'prepareContext debe mantener source_ids utiles y deduplicados.';
}

$ok = empty($failures);
echo json_encode([
    'ok' => $ok,
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 1);

/**
 * @param array<string,mixed> $payload
 * @param array<string,mixed> $filter
 */
function matchesFilter(array $payload, array $filter): bool
{
    $must = is_array($filter['must'] ?? null) ? (array) $filter['must'] : [];
    foreach ($must as $condition) {
        if (!is_array($condition)) {
            continue;
        }
        $key = (string) ($condition['key'] ?? '');
        $expected = (string) ($condition['match']['value'] ?? '');
        $actual = $payload[$key] ?? null;
        if ($actual === null || trim((string) $actual) !== $expected) {
            return false;
        }
    }
    return true;
}
