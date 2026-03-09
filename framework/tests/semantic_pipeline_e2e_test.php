<?php
// framework/tests/semantic_pipeline_e2e_test.php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\GeminiEmbeddingService;
use App\Core\IntentRouter;
use App\Core\QdrantVectorStore;
use App\Core\SemanticMemoryService;

$php = PHP_BINARY ?: 'php';
$publishScript = FRAMEWORK_ROOT . '/scripts/training_dataset_publication_gate.php';
$vectorizeScript = FRAMEWORK_ROOT . '/scripts/training_dataset_vectorize.php';
$templatePath = PROJECT_ROOT . '/contracts/knowledge/training_dataset_template.json';

$failures = [];
$tmpDir = FRAMEWORK_ROOT . '/tests/tmp/semantic_pipeline_e2e_' . time() . '_' . random_int(1000, 9999);
if (!@mkdir($tmpDir, 0777, true) && !is_dir($tmpDir)) {
    $failures[] = 'No se pudo crear directorio temporal de prueba.';
}

if (!is_file($publishScript)) {
    $failures[] = 'Falta publication gate script.';
}
if (!is_file($vectorizeScript)) {
    $failures[] = 'Falta training_dataset_vectorize.php.';
}
if (!is_file($templatePath)) {
    $failures[] = 'Falta training_dataset_template.json.';
}

if (empty($failures)) {
    $tenantId = 'tenant_semantic_e2e';
    $appId = 'app_semantic_e2e';

    $datasetPath = $tmpDir . '/dataset.json';
    copy($templatePath, $datasetPath);

    // 1) Draft must be blocked by vectorization command.
    $draftVectorize = runScript($php, $vectorizeScript, [
        '--in=' . $datasetPath,
        '--tenant-id=' . $tenantId,
        '--app-id=' . $appId,
        '--dry-run',
    ]);
    if ($draftVectorize['code'] === 0) {
        $failures[] = 'Vectorizacion debe bloquear dataset draft.';
    }

    // 2) Publish dataset for the semantic flow.
    $publish = runScript($php, $publishScript, [
        '--in=' . $datasetPath,
        '--min-explicit=1',
        '--min-implicit=1',
        '--min-hard-negatives=1',
        '--min-dialogues=1',
        '--min-qa=1',
        '--min-quality=0.10',
        '--min-coverage=0.10',
    ]);
    if ($publish['code'] !== 0) {
        $failures[] = 'No se pudo publicar dataset para e2e semantico.';
    }

    // 3) Vectorization default policy check (dry-run, no network/keys).
    $vectorize = runScript($php, $vectorizeScript, [
        '--in=' . $datasetPath,
        '--tenant-id=' . $tenantId,
        '--app-id=' . $appId,
        '--max-chars=200',
        '--dry-run',
    ]);
    $vectorizeJson = $vectorize['json'];
    if ($vectorize['code'] !== 0 || !is_array($vectorizeJson) || (($vectorizeJson['ok'] ?? false) !== true)) {
        $failures[] = 'Vectorizacion dry-run publicada debe pasar.';
    } else {
        $enabledLayers = is_array($vectorizeJson['vectorization_policy']['enabled_layers'] ?? null)
            ? $vectorizeJson['vectorization_policy']['enabled_layers']
            : [];
        if (!in_array('knowledge_stable', $enabledLayers, true) || !in_array('support_faq', $enabledLayers, true)) {
            $failures[] = 'Default vectorization debe incluir knowledge_stable y support_faq.';
        }
        if (in_array('intents_expansion', $enabledLayers, true)) {
            $failures[] = 'Default vectorization no debe incluir intents_expansion.';
        }
        if ((int) ($vectorizeJson['trace']['layers']['intents_expansion']['chunks'] ?? -1) !== 0) {
            $failures[] = 'Default vectorization debe dejar intents_expansion en 0 chunks.';
        }
    }

    // 4) Mocked semantic ingest + retrieval (no red, no llaves reales).
    $publishedDataset = readJson($datasetPath);
    if (!is_array($publishedDataset)) {
        $failures[] = 'Dataset publicado no legible.';
    } else {
        $chunks = buildSemanticChunksFromPublishedDataset($publishedDataset, $tenantId, $appId);
        if ($chunks === []) {
            $failures[] = 'No se pudieron construir chunks semanticos de knowledge/faq.';
        } else {
            $storedPoints = [];
            $lastQueryPayload = [];
            $collectionCreated = false;

            $embeddingTransport = static function (
                string $method,
                string $url,
                array $headers,
                array $payload,
                int $timeoutSec
            ): array {
                $text = trim((string) ($payload['content']['parts'][0]['text'] ?? ''));
                $seed = max(1, strlen($text));
                $value = min(1.0, $seed / 200.0);
                return [
                    'status' => 200,
                    'data' => [
                        'embedding' => [
                            'values' => array_fill(0, 768, $value),
                        ],
                    ],
                ];
            };

            $qdrantTransport = static function (
                string $method,
                string $url,
                array $headers,
                array $payload,
                int $timeoutSec
            ) use (&$storedPoints, &$lastQueryPayload, &$collectionCreated): array {
                if ($method === 'GET' && str_contains($url, '/collections/sector_knowledge') && !str_contains($url, '/points')) {
                    if (!$collectionCreated) {
                        return ['status' => 404, 'data' => ['status' => ['error' => 'not found']]];
                    }
                    return [
                        'status' => 200,
                        'data' => [
                            'result' => [
                                'config' => [
                                    'params' => [
                                        'vectors' => [
                                            'size' => 768,
                                            'distance' => 'Cosine',
                                        ],
                                    ],
                                ],
                                'payload_schema' => [
                                    'tenant_id' => ['params' => ['is_tenant' => true]],
                                    'memory_type' => ['params' => []],
                                    'app_id' => ['params' => []],
                                    'sector' => ['params' => []],
                                    'agent_role' => ['params' => []],
                                ],
                            ],
                        ],
                    ];
                }
                if ($method === 'PUT' && str_contains($url, '/collections/sector_knowledge') && !str_contains($url, '/points') && !str_contains($url, '/index')) {
                    $collectionCreated = true;
                    return ['status' => 200, 'data' => ['status' => 'ok']];
                }
                if ($method === 'PUT' && str_contains($url, '/collections/sector_knowledge/index')) {
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
                            'score' => 0.93,
                            'payload' => is_array($point['payload'] ?? null) ? (array) $point['payload'] : [],
                        ];
                    }
                    return ['status' => 200, 'data' => ['result' => ['points' => $points]]];
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
                    null,
                    768,
                    'Cosine',
                    5,
                    $qdrantTransport,
                    'sector_knowledge'
                );
                $semantic = new SemanticMemoryService($embedding, $qdrant, 5);

                $ingest = $semantic->ingest($chunks);
                if ((int) ($ingest['upserted'] ?? 0) < 1) {
                    $failures[] = 'Ingest semantico mock debe upsertar al menos 1 chunk.';
                }

                $retrieval = $semantic->retrieve('Necesito ayuda con facturas', [
                    'memory_type' => 'sector_knowledge',
                    'tenant_id' => $tenantId,
                    'app_id' => $appId,
                ], 3);
                if (!(bool) ($retrieval['rag_hit'] ?? false)) {
                    $failures[] = 'Retrieval debe reportar rag_hit=true.';
                }
                if (empty((array) ($retrieval['source_ids'] ?? []))) {
                    $failures[] = 'Retrieval debe devolver source_ids.';
                }
                if (empty((array) ($retrieval['evidence_ids'] ?? []))) {
                    $failures[] = 'Retrieval debe devolver evidence_ids.';
                }
                if ((string) ($retrieval['telemetry']['collection'] ?? '') !== 'sector_knowledge') {
                    $failures[] = 'Retrieval debe usar la coleccion sector_knowledge.';
                }

                // 5) Router consumes retrieval evidence.
                $router = new IntentRouter(null, 'warn', null, $semantic);
                $routed = $router->route([
                    'action' => 'send_to_llm',
                    'llm_request' => [
                        'messages' => [
                            ['role' => 'user', 'content' => 'Necesito ayuda con facturas'],
                        ],
                    ],
                ], [
                    'tenant_id' => $tenantId,
                    'app_id' => $appId,
                    'message_text' => 'Necesito ayuda con facturas',
                    'is_authenticated' => false,
                    'role' => 'guest',
                ]);

                $telemetry = $routed->telemetry();
                if (!(bool) ($telemetry['rag_hit'] ?? false)) {
                    $failures[] = 'Router debe recibir rag_hit desde retrieval.';
                }
                if ((string) ($telemetry['memory_type'] ?? '') !== 'sector_knowledge') {
                    $failures[] = 'Router debe propagar memory_type explicito.';
                }
                if (!(bool) ($telemetry['rag_used'] ?? false)) {
                    $failures[] = 'Router debe marcar rag_used=true cuando inyecta contexto valido.';
                }
                if ((string) ($telemetry['evidence_gate_status'] ?? '') !== 'passed') {
                    $failures[] = 'Router debe marcar evidence_gate_status=passed con retrieval valido.';
                }
                if (empty((array) ($telemetry['source_ids'] ?? []))) {
                    $failures[] = 'Router debe exponer source_ids en telemetry.';
                }
                if (empty((array) ($telemetry['evidence_ids'] ?? []))) {
                    $failures[] = 'Router debe exponer evidence_ids en telemetry.';
                }
                $semanticContext = is_array($routed->llmRequest()['semantic_context'] ?? null)
                    ? (array) $routed->llmRequest()['semantic_context']
                    : [];
                if (count((array) ($semanticContext['chunks'] ?? [])) < 1) {
                    $failures[] = 'Router debe inyectar semantic_context estable al LLM.';
                }
            } catch (\Throwable $e) {
                $failures[] = 'Flujo semantico e2e mock no debe fallar: ' . $e->getMessage();
            }
        }
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
 * @param array<int,string> $args
 * @return array{code:int,output:string,json:array<string,mixed>|null}
 */
function runScript(string $php, string $script, array $args): array
{
    $parts = [escapeshellarg($php), escapeshellarg($script)];
    foreach ($args as $arg) {
        $parts[] = escapeshellarg($arg);
    }
    $command = implode(' ', $parts);
    $output = [];
    $code = 0;
    exec($command . ' 2>&1', $output, $code);
    $raw = trim(implode("\n", $output));
    $json = json_decode($raw, true);
    if (!is_array($json)) {
        $json = parseLastJsonObject($raw);
    }
    return [
        'code' => $code,
        'output' => $raw,
        'json' => is_array($json) ? $json : null,
    ];
}

/**
 * @return array<string,mixed>|null
 */
function parseLastJsonObject(string $output): ?array
{
    $output = trim($output);
    if ($output === '') {
        return null;
    }
    $start = strrpos($output, '{');
    if ($start === false) {
        return null;
    }
    $candidate = substr($output, $start);
    $decoded = json_decode($candidate, true);
    return is_array($decoded) ? $decoded : null;
}

/**
 * @return array<string,mixed>|null
 */
function readJson(string $path): ?array
{
    if (!is_file($path)) {
        return null;
    }
    $raw = file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return null;
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

/**
 * @param array<string,mixed> $dataset
 * @return array<int,array<string,mixed>>
 */
function buildSemanticChunksFromPublishedDataset(array $dataset, string $tenantId, ?string $appId): array
{
    $chunks = [];
    $batchId = safeId((string) ($dataset['batch_id'] ?? 'batch_unknown'));
    $version = trim((string) ($dataset['dataset_version'] ?? '1.0.0'));
    if ($version === '') {
        $version = '1.0.0';
    }
    $createdAt = trim((string) ($dataset['publication']['published_at'] ?? date('c')));
    if ($createdAt === '') {
        $createdAt = date('c');
    }

    $knowledge = is_array($dataset['knowledge_stable'] ?? null) ? $dataset['knowledge_stable'] : [];
    foreach ($knowledge as $k => $record) {
        if (!is_array($record) || isset($record['vectorize']) && $record['vectorize'] === false) {
            continue;
        }
        $id = safeId((string) ($record['id'] ?? 'knowledge_' . $k));
        $title = trim((string) ($record['title'] ?? ''));
        $facts = is_array($record['facts'] ?? null) ? $record['facts'] : [];
        $factIndex = 0;
        foreach ($facts as $fact) {
            $fact = trim((string) $fact);
            if ($fact === '') {
                continue;
            }
            $text = trim($title !== '' ? ($title . '. ' . $fact) : $fact);
            $chunks[] = [
                'memory_type' => 'sector_knowledge',
                'tenant_id' => $tenantId,
                'app_id' => $appId,
                'agent_role' => null,
                'sector' => trim((string) ($record['sector'] ?? '')) ?: null,
                'source_type' => 'training_dataset',
                'source_id' => $batchId . ':knowledge_stable:' . $id,
                'source' => $batchId . ':knowledge_stable:' . $id,
                'chunk_id' => $id . '_fact_' . $factIndex,
                'type' => 'knowledge_stable',
                'tags' => ['layer:knowledge_stable', 'batch:' . $batchId, 'sector:' . safeId((string) ($record['sector'] ?? 'unknown'))],
                'version' => $version,
                'quality_score' => 0.95,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
                'metadata' => ['batch_id' => $batchId, 'layer' => 'knowledge_stable'],
                'content' => $text,
            ];
            $factIndex++;
        }
    }

    $faq = is_array($dataset['support_faq'] ?? null) ? $dataset['support_faq'] : [];
    foreach ($faq as $f => $record) {
        if (!is_array($record) || isset($record['vectorize']) && $record['vectorize'] === false) {
            continue;
        }
        $id = safeId((string) ($record['id'] ?? 'faq_' . $f));
        $question = trim((string) ($record['question'] ?? ''));
        $answer = trim((string) ($record['answer'] ?? ''));
        if ($question === '' || $answer === '') {
            continue;
        }
        $chunks[] = [
            'memory_type' => 'sector_knowledge',
            'tenant_id' => $tenantId,
            'app_id' => $appId,
            'agent_role' => null,
            'sector' => trim((string) ($record['sector'] ?? '')) ?: null,
            'source_type' => 'training_dataset',
            'source_id' => $batchId . ':support_faq:' . $id,
            'source' => $batchId . ':support_faq:' . $id,
            'chunk_id' => $id . '_qa',
            'type' => 'support_faq',
            'tags' => ['layer:support_faq', 'batch:' . $batchId],
            'version' => $version,
            'quality_score' => 0.92,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
            'metadata' => ['batch_id' => $batchId, 'layer' => 'support_faq'],
            'content' => 'Pregunta: ' . $question . "\nRespuesta: " . $answer,
        ];
    }

    return $chunks;
}

function safeId(string $value): string
{
    $value = preg_replace('/[^a-zA-Z0-9_\-]/', '_', trim($value));
    if (!is_string($value)) {
        return 'unknown';
    }
    $value = trim($value, '_');
    return $value !== '' ? $value : 'unknown';
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
