<?php
// framework/tests/qdrant_tenant_isolation_test.php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\QdrantVectorStore;

$failures = [];
$capturedPayloads = [];

$transport = static function (
    string $method,
    string $url,
    array $headers,
    array $payload,
    int $timeoutSec
) use (&$capturedPayloads): array {
    $capturedPayloads[] = ['method' => $method, 'url' => $url, 'payload' => $payload];

    if ($method === 'GET') {
        return ['status' => 200, 'data' => [
            'result' => ['config' => ['params' => ['vectors' => ['size' => 768, 'distance' => 'Cosine']]], 'payload_schema' => []],
        ]];
    }
    if ($method === 'PUT' && !str_contains($url, '/points')) {
        return ['status' => 200, 'data' => ['status' => 'ok']];
    }
    if ($method === 'PUT' && str_contains($url, '/points')) {
        return ['status' => 200, 'data' => ['result' => ['operation_id' => 1, 'status' => 'acknowledged']]];
    }
    if ($method === 'POST' && str_contains($url, '/points/query')) {
        return ['status' => 200, 'data' => ['result' => ['points' => []]]];
    }
    return ['status' => 404, 'data' => []];
};

putenv('QDRANT_URL=http://localhost:6333');
putenv('QDRANT_COLLECTION=agent_training');
putenv('AGENT_TRAINING_COLLECTION=agent_training');
putenv('SECTOR_KNOWLEDGE_COLLECTION=sector_knowledge');
putenv('USER_MEMORY_COLLECTION=user_memory');

$vector = array_fill(0, 768, 0.01);

// ── TEST 1: queryForTenant() includes tenant_id in must[] ─────────────────────
$capturedPayloads = [];
$store = new QdrantVectorStore('http://localhost:6333', 'k', null, 768, 'Cosine', 5, $transport, 'agent_training');

$store->queryForTenant('tenant_alpha', $vector, [], 3, true);

$queryCall = null;
foreach ($capturedPayloads as $call) {
    if ($call['method'] === 'POST' && str_contains($call['url'], '/points/query')) {
        $queryCall = $call;
        break;
    }
}

if ($queryCall === null) {
    $failures[] = 'TEST 1: No POST /points/query call captured';
} else {
    $must = $queryCall['payload']['filter']['must'] ?? [];
    $tenantFilter = null;
    foreach ($must as $condition) {
        if (($condition['key'] ?? '') === 'tenant_id' && ($condition['match']['value'] ?? '') === 'tenant_alpha') {
            $tenantFilter = $condition;
            break;
        }
    }
    if ($tenantFilter === null) {
        $failures[] = 'TEST 1: queryForTenant() does NOT inject tenant_id into filter must[]. Payload: ' . json_encode($queryCall['payload']['filter'] ?? []);
    }
}

// ── TEST 2: queryForTenant() merges existing filter conditions ────────────────
$capturedPayloads = [];
$store->queryForTenant('tenant_beta', $vector, [
    'must' => [['key' => 'memory_type', 'match' => ['value' => 'agent_training']]],
], 5, true);

$queryCall2 = null;
foreach ($capturedPayloads as $call) {
    if ($call['method'] === 'POST' && str_contains($call['url'], '/points/query')) {
        $queryCall2 = $call;
        break;
    }
}

if ($queryCall2 !== null) {
    $must2 = $queryCall2['payload']['filter']['must'] ?? [];
    $hasTenant = false;
    $hasMemoryType = false;
    foreach ($must2 as $c) {
        if (($c['key'] ?? '') === 'tenant_id' && ($c['match']['value'] ?? '') === 'tenant_beta') $hasTenant = true;
        if (($c['key'] ?? '') === 'memory_type') $hasMemoryType = true;
    }
    if (!$hasTenant) {
        $failures[] = 'TEST 2: tenant_id missing from merged filter. must: ' . json_encode($must2);
    }
    if (!$hasMemoryType) {
        $failures[] = 'TEST 2: original memory_type condition lost during merge.';
    }
}

// ── TEST 3: system tenant bypasses tenant filter (global queries allowed) ─────
$capturedPayloads = [];
$store->queryForTenant('system', $vector, [], 3, true);

$queryCall3 = null;
foreach ($capturedPayloads as $call) {
    if ($call['method'] === 'POST' && str_contains($call['url'], '/points/query')) {
        $queryCall3 = $call;
        break;
    }
}

if ($queryCall3 !== null) {
    $must3 = $queryCall3['payload']['filter']['must'] ?? null;
    if ($must3 !== null) {
        foreach ((array)$must3 as $c) {
            if (($c['key'] ?? '') === 'tenant_id') {
                $failures[] = 'TEST 3: system tenant should NOT inject tenant filter (global access). Got: ' . json_encode($c);
            }
        }
    }
}

// ── TEST 4: empty tenantId bypasses filter (same as system) ──────────────────
$capturedPayloads = [];
$store->queryForTenant('', $vector, [], 3, true);

$queryCall4 = null;
foreach ($capturedPayloads as $call) {
    if ($call['method'] === 'POST' && str_contains($call['url'], '/points/query')) {
        $queryCall4 = $call;
        break;
    }
}

if ($queryCall4 !== null) {
    $must4 = $queryCall4['payload']['filter']['must'] ?? null;
    if ($must4 !== null) {
        foreach ((array)$must4 as $c) {
            if (($c['key'] ?? '') === 'tenant_id') {
                $failures[] = 'TEST 4: empty tenantId should not inject tenant filter. Got: ' . json_encode($c);
            }
        }
    }
}

// ── OUTPUT ────────────────────────────────────────────────────────────────────
$total = 4;
$passed = $total - count($failures);

if (empty($failures)) {
    echo json_encode([
        'ok' => true,
        'tests' => $total,
        'passed' => $passed,
        'failed' => 0,
        'summary' => 'queryForTenant() enforces tenant_id isolation correctly.',
    ], JSON_PRETTY_PRINT) . PHP_EOL;
    exit(0);
} else {
    echo json_encode([
        'ok' => false,
        'tests' => $total,
        'passed' => $passed,
        'failed' => count($failures),
        'failures' => $failures,
    ], JSON_PRETTY_PRINT) . PHP_EOL;
    exit(1);
}
