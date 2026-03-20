<?php
// framework/tests/gemini_embedding_service_test.php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\GeminiEmbeddingService;

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
    return [
        'status' => 200,
        'data' => [
            'embedding' => [
                'values' => array_fill(0, 768, 0.125),
            ],
        ],
    ];
};

try {
    $service = new GeminiEmbeddingService(
        'test_key',
        'gemini-embedding-001',
        768,
        'https://generativelanguage.googleapis.com/v1beta',
        5,
        $transport
    );
    $result = $service->embed('hola mundo', ['task_type' => 'RETRIEVAL_DOCUMENT']);
    $queryResult = $service->embed('buscar documento', ['task_type' => 'RETRIEVAL_QUERY']);
    if (count((array) ($result['vector'] ?? [])) !== 768) {
        $failures[] = 'Embedding debe devolver 768 dimensiones.';
    }
    if (count((array) ($queryResult['vector'] ?? [])) !== 768) {
        $failures[] = 'Embedding query debe devolver 768 dimensiones.';
    }
    if ((string) ($result['model'] ?? '') !== 'gemini-embedding-001') {
        $failures[] = 'Modelo devuelto debe ser gemini-embedding-001.';
    }
} catch (\Throwable $e) {
    $failures[] = 'No debe fallar embedding con transporte mock: ' . $e->getMessage();
}

if (count($calls) !== 2) {
    $failures[] = 'Se esperaban exactamente 2 llamadas HTTP al proveedor embeddings.';
} else {
    foreach ($calls as $index => $call) {
        if ((string) ($call['method'] ?? '') !== 'POST') {
            $failures[] = 'Embedding debe usar POST.';
        }
        $url = (string) ($call['url'] ?? '');
        if (stripos($url, ':embedContent') === false) {
            $failures[] = 'Endpoint embedContent no detectado en URL.';
        }
        $payload = is_array($call['payload'] ?? null) ? (array) $call['payload'] : [];
        if ((string) ($payload['model'] ?? '') !== 'models/gemini-embedding-001') {
            $failures[] = 'Payload debe incluir model=models/gemini-embedding-001.';
        }
        if ((int) ($payload['outputDimensionality'] ?? 0) !== 768) {
            $failures[] = 'Payload debe forzar outputDimensionality=768.';
        }
        $expectedTaskType = $index === 0 ? 'RETRIEVAL_DOCUMENT' : 'RETRIEVAL_QUERY';
        if ((string) ($payload['taskType'] ?? '') !== $expectedTaskType) {
            $failures[] = 'Embedding debe propagar taskType=' . $expectedTaskType . '.';
        }
    }
}

try {
    new GeminiEmbeddingService(
        'test_key',
        'text-embedding-004',
        768,
        'https://generativelanguage.googleapis.com/v1beta',
        5,
        $transport
    );
    $failures[] = 'Debe bloquear modelo no canonico.';
} catch (\Throwable $e) {
    // expected
}

try {
    $service = new GeminiEmbeddingService(
        'test_key',
        'gemini-embedding-001',
        768,
        'https://generativelanguage.googleapis.com/v1beta',
        5,
        $transport
    );
    $service->embed('hola mundo', ['task_type' => 'UNKNOWN_TASK']);
    $failures[] = 'Debe bloquear task_type no permitido.';
} catch (\Throwable $e) {
    // expected
}

try {
    new GeminiEmbeddingService(
        'test_key',
        'gemini-embedding-001',
        512,
        'https://generativelanguage.googleapis.com/v1beta',
        5,
        $transport
    );
    $failures[] = 'Debe bloquear dimensionalidad no canonica.';
} catch (\Throwable $e) {
    // expected
}

$ok = empty($failures);
echo json_encode([
    'ok' => $ok,
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 1);
