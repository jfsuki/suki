<?php
// framework/tests/semantic_memory_service_test.php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\GeminiEmbeddingService;
use App\Core\QdrantVectorStore;
use App\Core\SemanticMemoryService;

$failures = [];
$storedPoints = [];
$lastQueryPayload = [];

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

$qdrantTransport = static function (string $method, string $url, array $headers, array $payload, int $timeoutSec) use (&$storedPoints, &$lastQueryPayload): array {
    if ($method === 'PUT' && str_contains($url, '/collections/suki_akp_default') && !str_contains($url, '/points')) {
        return ['status' => 200, 'data' => ['status' => 'ok']];
    }
    if ($method === 'PUT' && str_contains($url, '/points')) {
        $storedPoints = is_array($payload['points'] ?? null) ? (array) $payload['points'] : [];
        return ['status' => 200, 'data' => ['result' => ['status' => 'acknowledged']]];
    }
    if ($method === 'POST' && str_contains($url, '/points/query')) {
        $lastQueryPayload = $payload;
        $points = [];
        foreach ($storedPoints as $point) {
            if (!is_array($point)) {
                continue;
            }
            $points[] = [
                'id' => $point['id'] ?? null,
                'score' => 0.91,
                'payload' => is_array($point['payload'] ?? null) ? (array) $point['payload'] : [],
            ];
        }
        return [
            'status' => 200,
            'data' => ['result' => ['points' => $points]],
        ];
    }

    return ['status' => 404, 'data' => ['status' => ['error' => 'not found']]];
};

try {
    $embedding = new GeminiEmbeddingService(
        'test_key',
        'gemini-embedding-001',
        768,
        'https://generativelanguage.googleapis.com/v1beta',
        5,
        $embeddingTransport
    );
    $qdrant = new QdrantVectorStore(
        'http://localhost:6333',
        '',
        'suki_akp_default',
        768,
        'Cosine',
        5,
        $qdrantTransport
    );
    $service = new SemanticMemoryService($embedding, $qdrant, 3);

    $ingest = $service->ingest([
        [
            'tenant_id' => 'tenant_demo',
            'app_id' => 'app_demo',
            'source_type' => 'playbook',
            'source_id' => 'domain_playbooks',
            'chunk_id' => 'chunk_001',
            'type' => 'knowledge',
            'tags' => ['builder', 'inventory'],
            'version' => '1.0.0',
            'quality_score' => 0.97,
            'created_at' => '2026-03-07T00:00:00+00:00',
            'content' => 'Control de inventario por lote y ubicacion.',
        ],
        [
            'tenant_id' => 'tenant_demo',
            'app_id' => 'app_demo',
            'source_type' => 'playbook',
            'source_id' => 'domain_playbooks',
            'chunk_id' => 'chunk_001',
            'type' => 'knowledge',
            'tags' => ['builder', 'inventory'],
            'version' => '1.0.0',
            'quality_score' => 0.97,
            'created_at' => '2026-03-07T00:00:00+00:00',
            'content' => 'Control de inventario por lote y ubicacion.',
        ],
        [
            'tenant_id' => 'tenant_demo',
            'app_id' => 'app_demo',
            'source_type' => 'playbook',
            'source_id' => 'domain_playbooks',
            'chunk_id' => 'chunk_002',
            'type' => 'knowledge',
            'tags' => ['builder'],
            'version' => '1.0.0',
            'quality_score' => 0.88,
            'created_at' => '2026-03-07T00:00:00+00:00',
            'content' => 'Relaciona pedidos, clientes y facturas.',
        ],
    ]);

    if ((int) ($ingest['accepted'] ?? 0) !== 2) {
        $failures[] = 'Ingest hygiene debe aceptar 2 chunks unicos.';
    }
    if ((int) ($ingest['deduped'] ?? 0) !== 1) {
        $failures[] = 'Ingest hygiene debe deduplicar 1 chunk repetido.';
    }
    if ((int) ($ingest['upserted'] ?? 0) !== 2) {
        $failures[] = 'Ingest debe upsertar 2 chunks.';
    }

    $retrieval = $service->retrieve('Necesito control de inventario', [
        'tenant_id' => 'tenant_demo',
        'app_id' => 'app_demo',
    ], 3);

    if (!(bool) ($retrieval['rag_hit'] ?? false)) {
        $failures[] = 'Retrieval debe reportar rag_hit=true cuando hay resultados.';
    }
    if (count((array) ($retrieval['hits'] ?? [])) < 1) {
        $failures[] = 'Retrieval debe devolver al menos un hit.';
    }
    if (empty((array) ($retrieval['source_ids'] ?? []))) {
        $failures[] = 'Retrieval debe incluir source_ids para evidencia.';
    }
    if (empty((array) ($retrieval['evidence_ids'] ?? []))) {
        $failures[] = 'Retrieval debe incluir evidence_ids para evidencia.';
    }
} catch (\Throwable $e) {
    $failures[] = 'SemanticMemoryService no debe fallar en flujo mock: ' . $e->getMessage();
}

$must = is_array($lastQueryPayload['filter']['must'] ?? null) ? (array) $lastQueryPayload['filter']['must'] : [];
$tenantFilterOk = false;
$appFilterOk = false;
foreach ($must as $condition) {
    if (!is_array($condition)) {
        continue;
    }
    $key = (string) ($condition['key'] ?? '');
    $value = (string) ($condition['match']['value'] ?? '');
    if ($key === 'tenant_id' && $value === 'tenant_demo') {
        $tenantFilterOk = true;
    }
    if ($key === 'app_id' && $value === 'app_demo') {
        $appFilterOk = true;
    }
}
if (!$tenantFilterOk) {
    $failures[] = 'Retrieval debe forzar filtro tenant_id.';
}
if (!$appFilterOk) {
    $failures[] = 'Retrieval debe aplicar filtro app_id cuando existe.';
}

$ok = empty($failures);
echo json_encode([
    'ok' => $ok,
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 1);

