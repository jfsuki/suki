<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\ChatAgent;
use App\Core\GeminiEmbeddingService;
use App\Core\IntentRouter;
use App\Core\QdrantVectorStore;
use App\Core\SemanticMemoryService;

$failures = [];
$previousMode = getenv('ENFORCEMENT_MODE');
$previousSemantic = getenv('SEMANTIC_MEMORY_ENABLED');
putenv('ENFORCEMENT_MODE=warn');
putenv('SEMANTIC_MEMORY_ENABLED=1');

$semantic = buildSemanticService([
    [
        'memory_type' => 'agent_training',
        'tenant_id' => 'tenant_demo',
        'app_id' => 'app_demo',
        'agent_role' => null,
        'sector' => 'FERRETERIA_MINORISTA',
        'source_type' => 'business_discovery',
        'source_id' => 'bd:intent:skill_inventory_check',
        'source' => 'bd:intent:skill_inventory_check',
        'chunk_id' => 'inventory_implicit_1',
        'type' => 'intent_utterance_implicit',
        'tags' => ['action:inventory_check', 'intent:skill_inventory_check'],
        'version' => '1.0.0',
        'quality_score' => 1.0,
        'created_at' => '2026-03-20T00:00:00+00:00',
        'updated_at' => '2026-03-20T00:00:00+00:00',
        'metadata' => ['action' => 'inventory_check', 'intent' => 'skill_inventory_check'],
        'content' => 'mira si aun queda en vitrina',
    ],
    [
        'memory_type' => 'agent_training',
        'tenant_id' => 'tenant_demo',
        'app_id' => 'app_demo',
        'agent_role' => null,
        'sector' => 'FERRETERIA_MINORISTA',
        'source_type' => 'business_discovery',
        'source_id' => 'bd:intent:skill_product_lookup',
        'source' => 'bd:intent:skill_product_lookup',
        'chunk_id' => 'product_implicit_1',
        'type' => 'intent_utterance_implicit',
        'tags' => ['action:product_lookup', 'intent:skill_product_lookup'],
        'version' => '1.0.0',
        'quality_score' => 1.0,
        'created_at' => '2026-03-20T00:00:00+00:00',
        'updated_at' => '2026-03-20T00:00:00+00:00',
        'metadata' => ['action' => 'product_lookup', 'intent' => 'skill_product_lookup'],
        'content' => 'busca ese articulo por referencia',
    ],
    [
        'memory_type' => 'agent_training',
        'tenant_id' => 'tenant_demo',
        'app_id' => 'app_demo',
        'agent_role' => null,
        'sector' => 'FERRETERIA_MINORISTA',
        'source_type' => 'business_discovery',
        'source_id' => 'bd:intent:skill_create_invoice',
        'source' => 'bd:intent:skill_create_invoice',
        'chunk_id' => 'invoice_implicit_1',
        'type' => 'intent_utterance_implicit',
        'tags' => ['action:create_invoice', 'intent:skill_create_invoice'],
        'version' => '1.0.0',
        'quality_score' => 1.0,
        'created_at' => '2026-03-20T00:00:00+00:00',
        'updated_at' => '2026-03-20T00:00:00+00:00',
        'metadata' => ['action' => 'create_invoice', 'intent' => 'skill_create_invoice'],
        'content' => 'me falta facturar esa venta de mostrador',
    ],
    [
        'memory_type' => 'sector_knowledge',
        'tenant_id' => 'tenant_demo',
        'app_id' => 'app_demo',
        'agent_role' => null,
        'sector' => 'FERRETERIA_MINORISTA',
        'source_type' => 'business_discovery',
        'source_id' => 'bd:faq:mostrador',
        'source' => 'bd:faq:mostrador',
        'chunk_id' => 'faq_1',
        'type' => 'support_faq',
        'tags' => ['sector_seed', 'mostrador'],
        'version' => '1.0.0',
        'quality_score' => 1.0,
        'created_at' => '2026-03-20T00:00:00+00:00',
        'updated_at' => '2026-03-20T00:00:00+00:00',
        'metadata' => ['layer' => 'support_faq'],
        'content' => 'Pregunta: Como atiendo una venta rapida de mostrador? Respuesta: Primero valida referencia, existencias y forma de pago antes de emitir la factura.',
    ],
    [
        'memory_type' => 'sector_knowledge',
        'tenant_id' => 'tenant_demo',
        'app_id' => 'app_demo',
        'agent_role' => null,
        'sector' => 'FERRETERIA_MINORISTA',
        'source_type' => 'business_discovery',
        'source_id' => 'bd:knowledge:mostrador',
        'source' => 'bd:knowledge:mostrador',
        'chunk_id' => 'knowledge_1',
        'type' => 'knowledge_stable',
        'tags' => ['sector_seed', 'mostrador'],
        'version' => '1.0.0',
        'quality_score' => 1.0,
        'created_at' => '2026-03-20T00:00:00+00:00',
        'updated_at' => '2026-03-20T00:00:00+00:00',
        'metadata' => ['layer' => 'knowledge_stable'],
        'content' => 'Operacion de mostrador. Una venta rapida de mostrador suele requerir validar existencias antes de facturar.',
    ],
]);

$router = new IntentRouter(null, 'warn', null, $semantic);

$routerCases = [
    [
        'query' => 'mira si aun queda en vitrina',
        'expected_skill' => 'inventory_check',
    ],
    [
        'query' => 'busca ese articulo por referencia',
        'expected_skill' => 'product_lookup',
    ],
    [
        'query' => 'me falta facturar esa venta de mostrador',
        'expected_skill' => 'create_invoice',
    ],
];

foreach ($routerCases as $index => $case) {
    $route = $router->route([
        'action' => 'send_to_llm',
        'llm_request' => [
            'messages' => [
                ['role' => 'user', 'content' => (string) $case['query']],
            ],
            'user_message' => (string) $case['query'],
        ],
    ], [
        'tenant_id' => 'tenant_demo',
        'app_id' => 'app_demo',
        'project_id' => 'app_demo',
        'sector' => 'FERRETERIA_MINORISTA',
        'session_id' => 'semantic_router_case_' . $index,
        'mode' => 'app',
        'role' => 'admin',
        'is_authenticated' => true,
        'message_text' => (string) $case['query'],
    ]);
    $telemetry = $route->telemetry();
    if ((string) ($telemetry['skill_selected'] ?? '') !== (string) $case['expected_skill']) {
        $failures[] = 'Router debe resolver ' . $case['expected_skill'] . ' para query: ' . $case['query'];
    }
    if ((string) ($telemetry['semantic_intent_source'] ?? '') !== 'agent_training') {
        $failures[] = 'Router debe trazar semantic_intent_source=agent_training.';
    }
    if ((float) ($telemetry['semantic_intent_similarity_score'] ?? 0.0) < 0.75) {
        $failures[] = 'Router debe dejar similarity fuerte para query: ' . $case['query'];
    }
    if ((string) ($telemetry['route_reason'] ?? '') === 'loop_guard_blocked_before_llm') {
        $failures[] = 'Router no debe bloquear por guard cuando ya hay semantic intent valido.';
    }
}

$informativeRoute = $router->route([
    'action' => 'send_to_llm',
    'llm_request' => [
        'messages' => [
            ['role' => 'user', 'content' => 'como atiendo una venta rapida de mostrador'],
        ],
        'user_message' => 'como atiendo una venta rapida de mostrador',
    ],
], [
    'tenant_id' => 'tenant_demo',
    'app_id' => 'app_demo',
    'project_id' => 'app_demo',
    'sector' => 'FERRETERIA_MINORISTA',
    'session_id' => 'semantic_router_info',
    'mode' => 'app',
    'role' => 'admin',
    'is_authenticated' => true,
    'request_mode' => 'research',
    'message_text' => 'como atiendo una venta rapida de mostrador',
]);
$informativeTelemetry = $informativeRoute->telemetry();
$informativeContext = is_array($informativeRoute->llmRequest()['semantic_context'] ?? null)
    ? (array) $informativeRoute->llmRequest()['semantic_context']
    : [];
if (!$informativeRoute->isLlmRequest()) {
    $failures[] = 'Consulta informativa debe continuar con contexto verificado en vez de bloquearse.';
}
if ((bool) ($informativeTelemetry['rag_used'] ?? false) !== true) {
    $failures[] = 'Consulta informativa debe usar RAG sectorial.';
}
if ((bool) ($informativeTelemetry['evidence_override'] ?? false) !== true) {
    $failures[] = 'Consulta informativa con score alto debe activar evidence_override.';
}
if ((int) ($informativeContext['used_count'] ?? 0) < 1) {
    $failures[] = 'Consulta informativa debe propagar semantic_context util al LLM/local reply.';
}

$chatAgent = new ChatAgent();
$reflection = new ReflectionClass($chatAgent);
$intentRouterProperty = $reflection->getProperty('intentRouter');
$intentRouterProperty->setAccessible(true);
$intentRouterProperty->setValue($chatAgent, new IntentRouter(null, 'warn', null, $semantic));

$chatCases = [
    [
        'message' => 'mira si aun queda en vitrina',
        'contains' => 'inventory_check',
    ],
    [
        'message' => 'busca ese articulo por referencia',
        'contains' => 'product_lookup',
    ],
    [
        'message' => 'me falta facturar esa venta de mostrador',
        'contains' => 'create_invoice',
    ],
    [
        'message' => 'como atiendo una venta rapida de mostrador',
        'contains' => 'Primero valida referencia, existencias y forma de pago antes de emitir la factura.',
    ],
];

foreach ($chatCases as $index => $case) {
    $reply = $chatAgent->handle([
        'tenant_id' => 'tenant_demo',
        'project_id' => 'app_demo',
        'session_id' => 'semantic_chat_case_' . $index,
        'user_id' => 'architect',
        'role' => 'admin',
        'is_authenticated' => true,
        'mode' => 'app',
        'channel' => 'test',
        'message_id' => 'semantic_chat_msg_' . $index,
        'message' => (string) $case['message'],
        'request_mode' => $case['message'] === 'como atiendo una venta rapida de mostrador' ? 'research' : 'operation',
        'sector' => 'FERRETERIA_MINORISTA',
    ]);
    $replyText = (string) (($reply['data']['reply'] ?? $reply['reply'] ?? ''));
    if (!str_contains($replyText, (string) $case['contains'])) {
        $failures[] = 'ChatAgent debe responder con salida util para query: ' . $case['message'];
    }
    if (str_contains($replyText, 'Solo puedo responder con datos reales de esta app.')) {
        $failures[] = 'ChatAgent no debe caer en fallback generico para query: ' . $case['message'];
    }
}

if ($previousMode === false) {
    putenv('ENFORCEMENT_MODE');
} else {
    putenv('ENFORCEMENT_MODE=' . $previousMode);
}
if ($previousSemantic === false) {
    putenv('SEMANTIC_MEMORY_ENABLED');
} else {
    putenv('SEMANTIC_MEMORY_ENABLED=' . $previousSemantic);
}

$ok = $failures === [];
echo json_encode([
    'ok' => $ok,
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 1);

/**
 * @param array<int,array<string,mixed>> $chunks
 */
function buildSemanticService(array $chunks): SemanticMemoryService
{
    $storedPoints = [];
    $collections = [];

    $embeddingTransport = static function (string $method, string $url, array $headers, array $payload, int $timeoutSec): array {
        $text = strtolower(trim((string) ($payload['content']['parts'][0]['text'] ?? '')));
        $value = 0.05;
        if (str_contains($text, 'operacion de mostrador') || str_contains($text, 'forma de pago')) {
            $value = 0.80;
        } elseif (str_contains($text, 'atiendo una venta rapida de mostrador') || str_contains($text, 'venta rapida de mostrador')) {
            $value = 0.78;
        } elseif (str_contains($text, 'vitrina') || str_contains($text, 'stock')) {
            $value = 0.10;
        } elseif (str_contains($text, 'referencia') || str_contains($text, 'articulo')) {
            $value = 0.30;
        } elseif (str_contains($text, 'facturar') || str_contains($text, 'factura')) {
            $value = 0.50;
        }

        return [
            'status' => 200,
            'data' => [
                'embedding' => [
                    'values' => array_fill(0, 768, $value),
                ],
            ],
        ];
    };

    $qdrantTransport = static function (string $method, string $url, array $headers, array $payload, int $timeoutSec) use (&$storedPoints, &$collections): array {
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
            ];
            return ['status' => 200, 'data' => ['status' => 'ok']];
        }

        if ($method === 'PUT' && str_contains($url, '/points')) {
            $storedPoints[$collection] = is_array($payload['points'] ?? null) ? (array) $payload['points'] : [];
            return ['status' => 200, 'data' => ['result' => ['status' => 'acknowledged']]];
        }

        if ($method === 'POST' && str_contains($url, '/points/query')) {
            $queryVector = is_array($payload['query'] ?? null) ? (array) $payload['query'] : [];
            $queryValue = is_numeric($queryVector[0] ?? null) ? (float) $queryVector[0] : 0.0;
            $points = [];
            foreach ((array) ($storedPoints[$collection] ?? []) as $point) {
                if (!is_array($point)) {
                    continue;
                }
                $pointPayload = is_array($point['payload'] ?? null) ? (array) $point['payload'] : [];
                if (!matchesFilter($pointPayload, is_array($payload['filter'] ?? null) ? (array) $payload['filter'] : [])) {
                    continue;
                }
                $pointVector = is_array($point['vector'] ?? null) ? (array) $point['vector'] : [];
                $pointValue = is_numeric($pointVector[0] ?? null) ? (float) $pointVector[0] : 0.0;
                $score = max(0.0, 1.0 - abs($queryValue - $pointValue));
                $points[] = [
                    'id' => $point['id'] ?? null,
                    'score' => $score,
                    'payload' => $pointPayload,
                ];
            }
            usort($points, static fn(array $left, array $right): int => ((float) ($right['score'] ?? 0.0)) <=> ((float) ($left['score'] ?? 0.0)));

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
    $prototype = new QdrantVectorStore(
        'http://localhost:6333',
        '',
        'suki_akp_default',
        768,
        'Cosine',
        5,
        $qdrantTransport
    );
    $service = new SemanticMemoryService($embedding, $prototype, 5);

    $grouped = [];
    foreach ($chunks as $chunk) {
        $memoryType = (string) ($chunk['memory_type'] ?? '');
        if ($memoryType === '') {
            continue;
        }
        if (!isset($grouped[$memoryType])) {
            $grouped[$memoryType] = [];
        }
        $grouped[$memoryType][] = $chunk;
    }
    foreach ($grouped as $memoryType => $memoryTypeChunks) {
        $service->ingest($memoryTypeChunks, ['memory_type' => $memoryType, 'wait' => true]);
    }

    return $service;
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
        $expected = (string) ($condition['match']['value'] ?? '');
        $actual = $payload[$key] ?? null;
        if ($actual === null || trim((string) $actual) !== $expected) {
            return false;
        }
    }

    return true;
}
