<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\ErpTrainingDatasetVectorizer;
use App\Core\GeminiEmbeddingService;
use App\Core\QdrantVectorStore;

$failures = [];
$tmpDir = FRAMEWORK_ROOT . '/tests/tmp/erp_training_dataset_vectorization_' . time() . '_' . random_int(1000, 9999);
if (!@mkdir($tmpDir, 0777, true) && !is_dir($tmpDir)) {
    $failures[] = 'No se pudo crear directorio temporal para vectorizacion ERP.';
}

$artifact = buildArtifactFixture();
$artifactPath = $tmpDir . '/erp_vectorization_prep.json';
file_put_contents(
    $artifactPath,
    json_encode($artifact, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL
);

if ($failures === []) {
    [$vectorizer, $state] = buildVectorizer(false);

    try {
        $dryRun = $vectorizer->vectorizeArtifact($artifact, [
            'dry_run' => true,
            'strict' => true,
            'limit' => 2,
            'batch_size' => 1,
            'top_k' => 2,
            'input_path' => $artifactPath,
        ]);
        if (($dryRun['ok'] ?? false) !== true) {
            $failures[] = 'Dry-run ERP vectorization debe devolver ok=true.';
        }
        if (($dryRun['action'] ?? '') !== 'dry_run') {
            $failures[] = 'Dry-run ERP vectorization debe reportar action=dry_run.';
        }
        if ((int) ($dryRun['totals']['inserted'] ?? -1) !== 0) {
            $failures[] = 'Dry-run ERP vectorization no debe insertar puntos.';
        }
        if ((int) $state->upsert_calls !== 0) {
            $failures[] = 'Dry-run ERP vectorization no debe escribir en Qdrant.';
        }
        if ((int) ($dryRun['embedding_probe']['dimensions'] ?? 0) !== 768) {
            $failures[] = 'Dry-run ERP vectorization debe validar embedding probe 768.';
        }
    } catch (Throwable $e) {
        $failures[] = 'Dry-run ERP vectorization no debe fallar en mocks: ' . $e->getMessage();
    }

    try {
        $realRun = $vectorizer->vectorizeArtifact($artifact, [
            'dry_run' => false,
            'strict' => true,
            'limit' => 3,
            'batch_size' => 2,
            'top_k' => 2,
            'input_path' => $artifactPath,
        ]);
        if (($realRun['ok'] ?? false) !== true) {
            $failures[] = 'Vectorizacion ERP mock debe pasar completamente.';
        }
        if ((int) ($realRun['totals']['vectorized'] ?? 0) !== 3) {
            $failures[] = 'Vectorizacion ERP mock debe vectorizar 3 entries.';
        }
        if ((int) ($realRun['totals']['inserted'] ?? 0) !== 3) {
            $failures[] = 'Vectorizacion ERP mock debe insertar 3 entries.';
        }
        if ((int) ($realRun['totals']['retrieved_in_smoke'] ?? 0) < 1) {
            $failures[] = 'Smoke retrieval ERP mock debe recuperar al menos 1 hit.';
        }
        if (($realRun['smoke_test']['ok'] ?? false) !== true) {
            $failures[] = 'Smoke retrieval ERP mock debe ser coherente.';
        }

        $payloadSample = is_array($realRun['payload_sample'] ?? null) ? (array) $realRun['payload_sample'] : [];
        foreach ([
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
            'source_kind',
            'dataset_scope',
            'tenant_data_allowed',
            'source_artifact',
            'source_id',
            'content',
            'collection',
            'vector_id',
            'qdrant_point_id',
        ] as $requiredField) {
            if (!array_key_exists($requiredField, $payloadSample)) {
                $failures[] = 'Payload ERP vectorizado debe incluir ' . $requiredField . '.';
            }
        }
    } catch (Throwable $e) {
        $failures[] = 'Vectorizacion ERP mock no debe fallar: ' . $e->getMessage();
    }

    try {
        $vectorizer->vectorizeFromPath($tmpDir . '/missing.json', ['dry_run' => true]);
        $failures[] = 'vectorizeFromPath debe bloquear input inexistente.';
    } catch (Throwable $e) {
        // expected
    }

    try {
        $invalid = $artifact;
        $invalid['metadata']['target_collection'] = 'sector_knowledge';
        $vectorizer->vectorizeArtifact($invalid, ['dry_run' => true]);
        $failures[] = 'Debe bloquear target_collection distinto de agent_training.';
    } catch (Throwable $e) {
        // expected
    }

    try {
        $invalid = $artifact;
        $invalid['metadata']['tenant_data_allowed'] = true;
        $invalid['entries'][0]['metadata']['tenant_data_allowed'] = true;
        $vectorizer->vectorizeArtifact($invalid, ['dry_run' => true]);
        $failures[] = 'Debe bloquear tenant_data_allowed=true.';
    } catch (Throwable $e) {
        // expected
    }

    try {
        [$badVectorizer] = buildVectorizer(true);
        $badVectorizer->vectorizeArtifact($artifact, ['dry_run' => true, 'strict' => true]);
        $failures[] = 'Debe bloquear embedding con dimensionalidad distinta de 768.';
    } catch (Throwable $e) {
        // expected
    }
}

rrmdir($tmpDir);

$result = [
    'ok' => empty($failures),
    'failures' => $failures,
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($result['ok'] ? 0 : 1);

/**
 * @return array{0:ErpTrainingDatasetVectorizer,1:object}
 */
function buildVectorizer(bool $badDimensions): array
{
    $state = (object) [
        'upsert_calls' => 0,
        'query_calls' => 0,
        'points' => [],
        'collection' => [
            'vectors' => ['size' => 768, 'distance' => 'Cosine'],
            'payload_schema' => [],
        ],
    ];

    $embeddingTransport = static function (string $method, string $url, array $headers, array $payload, int $timeoutSec) use ($badDimensions): array {
        $dimensions = $badDimensions ? 767 : 768;
        $text = trim((string) ($payload['content']['parts'][0]['text'] ?? 'vectorize me'));
        $value = min(0.999, max(0.05, strlen($text) / 200));
        return [
            'status' => 200,
            'data' => [
                'embedding' => [
                    'values' => array_fill(0, $dimensions, $value),
                ],
            ],
        ];
    };

    $qdrantTransport = static function (string $method, string $url, array $headers, array $payload, int $timeoutSec) use ($state): array {
        if (!preg_match('#/collections/([^/?]+)#', $url, $matches)) {
            return ['status' => 404, 'data' => ['status' => ['error' => 'not found']]];
        }
        $collection = rawurldecode((string) $matches[1]);
        if ($collection !== 'agent_training') {
            return ['status' => 404, 'data' => ['status' => ['error' => 'unknown collection']]];
        }

        if ($method === 'GET' && !str_contains($url, '/points')) {
            return [
                'status' => 200,
                'data' => [
                    'result' => [
                        'config' => [
                            'params' => [
                                'vectors' => $state->collection['vectors'],
                            ],
                        ],
                        'payload_schema' => $state->collection['payload_schema'],
                    ],
                ],
            ];
        }

        if ($method === 'PUT' && str_contains($url, '/index')) {
            $field = (string) ($payload['field_name'] ?? '');
            $state->collection['payload_schema'][$field] = [
                'data_type' => (string) ($payload['field_schema'] ?? 'keyword'),
                'params' => [
                    'is_tenant' => (bool) ($payload['is_tenant'] ?? false),
                ],
            ];
            return ['status' => 200, 'data' => ['status' => 'ok']];
        }

        if ($method === 'PUT' && str_contains($url, '/points')) {
            $state->upsert_calls++;
            foreach ((array) ($payload['points'] ?? []) as $point) {
                if (!is_array($point)) {
                    continue;
                }
                $state->points[(string) ($point['id'] ?? '')] = $point;
            }
            return ['status' => 200, 'data' => ['result' => ['status' => 'acknowledged']]];
        }

        if ($method === 'POST' && str_contains($url, '/points/query')) {
            $state->query_calls++;
            $points = [];
            foreach ((array) $state->points as $point) {
                if (!is_array($point)) {
                    continue;
                }
                $pointPayload = is_array($point['payload'] ?? null) ? (array) $point['payload'] : [];
                if (!matchesFilter($pointPayload, is_array($payload['filter'] ?? null) ? (array) $payload['filter'] : [])) {
                    continue;
                }
                $points[] = [
                    'id' => $point['id'] ?? null,
                    'score' => 0.97,
                    'payload' => $pointPayload,
                ];
            }
            return ['status' => 200, 'data' => ['result' => ['points' => $points]]];
        }

        return ['status' => 404, 'data' => ['status' => ['error' => 'not found']]];
    };

    $embedding = new GeminiEmbeddingService(
        'test_key',
        'gemini-embedding-001',
        768,
        'https://generativelanguage.googleapis.com/v1beta',
        5,
        $embeddingTransport
    );
    $store = new QdrantVectorStore(
        'http://localhost:6333',
        'qdrant_key',
        null,
        768,
        'Cosine',
        5,
        $qdrantTransport,
        'agent_training'
    );

    return [
        new ErpTrainingDatasetVectorizer($embedding, $store, 2, 2, 1),
        $state,
    ];
}

/**
 * @return array<string,mixed>
 */
function buildArtifactFixture(): array
{
    return [
        'artifact_type' => 'erp_vectorization_prep',
        'schema_version' => '1.0.0',
        'metadata' => [
            'dataset_id' => 'suki_erp_dataset',
            'dataset_version' => '1.1.0',
            'target_collection' => 'agent_training',
            'available_collections' => ['agent_training', 'sector_knowledge', 'user_memory'],
            'dataset_scope' => 'shared_non_operational_training',
            'tenant_data_allowed' => false,
            'generated_at' => '2026-03-17T00:29:21+00:00',
        ],
        'entries' => [
            buildEntry('train_sample_a', 'sample_a', 'crear factura a ACME por 5 baterias'),
            buildEntry('train_sample_b', 'sample_b', 'factura 8 filtros para Taller Central', 'hard_case', ['missing_scope']),
            buildEntry('train_sample_c', 'sample_c', 'registrar cobro parcial del cliente Delta'),
        ],
    ];
}

/**
 * @param array<int,string> $ambiguityFlags
 * @return array<string,mixed>
 */
function buildEntry(
    string $vectorId,
    string $sourceId,
    string $content,
    string $sourceKind = 'training_sample',
    array $ambiguityFlags = []
): array {
    return [
        'vector_id' => $vectorId,
        'collection' => 'agent_training',
        'memory_type' => 'agent_training',
        'source_artifact' => $sourceKind === 'hard_case' ? 'erp_hard_cases' : 'erp_training_samples',
        'source_id' => $sourceId,
        'content' => $content,
        'metadata' => [
            'dataset_id' => 'suki_erp_dataset',
            'dataset_version' => '1.1.0',
            'domain' => 'sales',
            'subdomain' => 'invoicing',
            'locale' => 'es-CO',
            'intent_key' => 'sales.create_invoice',
            'target_skill' => 'create_invoice',
            'skill_type' => 'tool',
            'required_action' => 'invoice.create',
            'risk_level' => 'high',
            'needs_clarification' => $ambiguityFlags !== [],
            'ambiguity_flags' => $ambiguityFlags,
            'tenant_data_allowed' => false,
            'dataset_scope' => 'shared_non_operational_training',
            'source_kind' => $sourceKind,
        ],
    ];
}

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
        $expected = $condition['match']['value'] ?? null;
        if (!array_key_exists($key, $payload)) {
            return false;
        }
        if ((string) $payload[$key] !== (string) $expected) {
            return false;
        }
    }
    return true;
}

function rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = scandir($dir);
    if (!is_array($items)) {
        @rmdir($dir);
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            rrmdir($path);
            continue;
        }
        @unlink($path);
    }
    @rmdir($dir);
}
