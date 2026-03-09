<?php
// framework/tests/enforcement_warn_allows_but_logs_when_missing_test.php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\GeminiEmbeddingService;
use App\Core\IntentRouter;
use App\Core\QdrantVectorStore;
use App\Core\SemanticMemoryService;

$failures = [];
$previousMode = getenv('ENFORCEMENT_MODE');
$previousSemantic = getenv('SEMANTIC_MEMORY_ENABLED');
putenv('ENFORCEMENT_MODE=warn');
putenv('SEMANTIC_MEMORY_ENABLED=1');

$router = new IntentRouter(null, 'warn', null, buildEmptySemanticService());
$result = $router->route([
    'action' => 'send_to_llm',
    'llm_request' => [
        'messages' => [
            ['role' => 'user', 'content' => 'Como configuro Qdrant para tenant y app?'],
        ],
        'user_message' => 'Como configuro Qdrant para tenant y app?',
    ],
], [
    'tenant_id' => 'default',
    'project_id' => 'default',
    'session_id' => 'enf_warn_min_evidence',
    'role' => 'admin',
    'mode' => 'app',
]);

$telemetry = $result->telemetry();
if ((string) ($telemetry['gate_decision'] ?? '') !== 'warn') {
    $failures[] = 'warn: gate_decision esperado warn cuando falta minimum evidence.';
}
if ((string) ($telemetry['evidence_gate_status'] ?? '') !== 'insufficient_evidence') {
    $failures[] = 'warn: evidence_gate_status esperado insufficient_evidence.';
}
if ((string) ($telemetry['fallback_reason'] ?? '') === '') {
    $failures[] = 'warn: fallback_reason debe quedar trazado.';
}

$violations = is_array($telemetry['contract_violations'] ?? null) ? (array) $telemetry['contract_violations'] : [];
$foundMissingEvidence = false;
foreach ($violations as $violation) {
    if (str_starts_with((string) $violation, 'minimum_evidence_missing:')) {
        $foundMissingEvidence = true;
        break;
    }
}
if (!$foundMissingEvidence) {
    $failures[] = 'warn: debe registrar violation minimum_evidence_missing.';
}

if (!$result->isLocalResponse() && !$result->isLlmRequest()) {
    $failures[] = 'warn: debe permitir flujo (LLM) o degradar a ASK; no debe quedar en estado invalido.';
}

if ($result->isLocalResponse()) {
    $reply = mb_strtolower($result->reply(), 'UTF-8');
    if (!str_contains($reply, 'evidencia minima')) {
        $failures[] = 'warn: cuando degrada a ASK, reply debe explicar evidencia minima faltante.';
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

$ok = empty($failures);
echo json_encode([
    'ok' => $ok,
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 1);

function buildEmptySemanticService(): SemanticMemoryService
{
    $embeddingTransport = static function (string $method, string $url, array $headers, array $payload, int $timeoutSec): array {
        return [
            'status' => 200,
            'data' => [
                'embedding' => [
                    'values' => array_fill(0, 768, 0.25),
                ],
            ],
        ];
    };

    $collections = [];
    $qdrantTransport = static function (string $method, string $url, array $headers, array $payload, int $timeoutSec) use (&$collections): array {
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

        if ($method === 'POST' && str_contains($url, '/points/query')) {
            return ['status' => 200, 'data' => ['result' => ['points' => []]]];
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

    return new SemanticMemoryService($embedding, $prototype, 5);
}
