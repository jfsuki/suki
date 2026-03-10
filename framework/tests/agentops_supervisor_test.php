<?php
// framework/tests/agentops_supervisor_test.php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\AgentOpsSupervisor;

$failures = [];
$supervisor = new AgentOpsSupervisor();

$cases = [
    'insufficient_evidence' => [
        'runtime' => baseRuntime([
            'route_path' => 'cache>rules>rag>llm',
            'action_contract' => 'business_explain',
            'rag_attempted' => true,
            'rag_used' => false,
            'rag_result_count' => 0,
            'evidence_gate_status' => 'insufficient_evidence',
            'fallback_reason' => 'insufficient_evidence',
            'llm_used' => true,
        ]),
        'route_telemetry' => [],
        'context' => [
            'response_kind' => 'respond_local',
            'response_text' => 'El IVA aplicable es 19% y debes registrarlo asi.',
        ],
        'expected_flags' => ['insufficient_evidence'],
        'expected_status' => 'flagged',
        'expected_regression' => true,
    ],
    'rag_weak_result' => [
        'runtime' => baseRuntime([
            'route_path' => 'cache>rules>rag',
            'rag_attempted' => true,
            'rag_used' => false,
            'rag_result_count' => 0,
            'fallback_reason' => 'awaiting_rag_result',
        ]),
        'route_telemetry' => [],
        'context' => [
            'response_kind' => 'ask_user',
            'response_text' => 'Comparte un dato verificable para continuar.',
        ],
        'expected_flags' => ['rag_weak_result'],
    ],
    'skill_failure' => [
        'runtime' => baseRuntime([
            'route_path' => 'cache>rules>skills',
            'skill_selected' => 'create_invoice',
            'skill_executed' => true,
            'skill_failed' => true,
            'skill_result_status' => 'safe_fallback',
            'skill_fallback_reason' => 'tool_runtime_unavailable',
            'fallback_reason' => 'tool_runtime_unavailable',
        ]),
        'route_telemetry' => [],
        'context' => [
            'response_kind' => 'respond_local',
            'response_text' => 'La capacidad create_invoice fue clasificada, pero el runtime actual aun no expone el tool.',
        ],
        'expected_flags' => ['skill_execution_failed'],
    ],
    'fallback_overuse' => [
        'runtime' => baseRuntime([
            'route_path' => 'cache>rules>rag',
            'fallback_reason' => 'repeated_route_without_progress',
            'loop_guard_triggered' => true,
            'loop_guard_reason' => 'repeated_route_without_progress',
        ]),
        'route_telemetry' => [
            'same_route_repeat_count' => 3,
        ],
        'context' => [
            'response_kind' => 'respond_local',
            'response_text' => 'Detuve esta ruta para evitar repetir el mismo fallback sin progreso. Cambia el dato clave o concreta el siguiente paso.',
        ],
        'expected_flags' => ['fallback_overuse'],
        'expected_hygiene' => true,
    ],
    'policy_route_mismatch' => [
        'runtime' => baseRuntime([
            'route_path' => 'cache>rules',
            'llm_used' => true,
        ]),
        'route_telemetry' => [],
        'context' => [
            'response_kind' => 'send_to_llm',
            'response_text' => 'Respuesta libre sin ruta LLM declarada.',
        ],
        'expected_flags' => ['policy_route_mismatch'],
    ],
    'healthy_path' => [
        'runtime' => baseRuntime([
            'route_path' => 'cache>rules>skills>rag>llm',
            'action_contract' => 'business_explain',
            'rag_attempted' => true,
            'rag_used' => true,
            'rag_result_count' => 2,
            'evidence_gate_status' => 'passed',
            'fallback_reason' => 'llm_last_resort_after_rag',
            'llm_used' => true,
            'source_ids' => ['src_1'],
            'evidence_ids' => ['ev_1'],
            'skill_selected' => 'business_explain',
            'skill_executed' => true,
            'skill_result_status' => 'continued_to_rag',
        ]),
        'route_telemetry' => [],
        'context' => [
            'response_kind' => 'send_to_llm',
            'response_text' => 'Segun la evidencia recuperada, primero emites factura y luego posteas el asiento.',
        ],
        'expected_flags' => [],
        'expected_status' => 'healthy',
        'expected_regression' => false,
        'expected_hygiene' => false,
    ],
];

foreach ($cases as $name => $case) {
    $result = $supervisor->evaluate(
        (array) $case['runtime'],
        (array) ($case['route_telemetry'] ?? []),
        (array) ($case['context'] ?? [])
    );

    try {
        AgentOpsSupervisor::validateResult($result);
    } catch (Throwable $e) {
        $failures[] = $name . ': resultado invalido por schema: ' . $e->getMessage();
        continue;
    }

    $expectedFlags = (array) ($case['expected_flags'] ?? []);
    foreach ($expectedFlags as $flag) {
        if (!in_array($flag, (array) ($result['flags'] ?? []), true)) {
            $failures[] = $name . ': falta flag esperada ' . $flag;
        }
    }

    if (array_key_exists('expected_status', $case) && (string) ($result['status'] ?? '') !== (string) $case['expected_status']) {
        $failures[] = $name . ': status inesperado.';
    }
    if (array_key_exists('expected_regression', $case) && (bool) ($result['needs_regression_case'] ?? false) !== (bool) $case['expected_regression']) {
        $failures[] = $name . ': needs_regression_case inesperado.';
    }
    if (array_key_exists('expected_hygiene', $case) && (bool) ($result['needs_memory_hygiene'] ?? false) !== (bool) $case['expected_hygiene']) {
        $failures[] = $name . ': needs_memory_hygiene inesperado.';
    }
}

$ok = empty($failures);
echo json_encode([
    'ok' => $ok,
    'cases' => count($cases),
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($ok ? 0 : 1);

/**
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function baseRuntime(array $overrides = []): array
{
    return array_merge([
        'route_path' => 'cache>rules',
        'gate_decision' => 'allow',
        'action_contract' => 'none',
        'route_reason' => 'deterministic_route_resolved',
        'rag_hit' => false,
        'source_ids' => [],
        'evidence_ids' => [],
        'semantic_enabled' => true,
        'semantic_memory_status' => 'enabled',
        'rag_attempted' => false,
        'rag_used' => false,
        'rag_result_count' => 0,
        'evidence_gate_status' => 'skipped_by_rule',
        'fallback_reason' => 'none',
        'skill_detected' => false,
        'skill_selected' => 'none',
        'skill_executed' => false,
        'skill_failed' => false,
        'skill_execution_ms' => 0,
        'skill_result_status' => 'unknown',
        'skill_fallback_reason' => 'none',
        'llm_called' => false,
        'llm_used' => false,
        'tool_calls_count' => 0,
        'retry_count' => 0,
        'llm_fallback_count' => 0,
        'loop_guard_triggered' => false,
        'loop_guard_reason' => 'none',
        'loop_guard_stage' => 'none',
        'same_route_repeat_count' => 0,
        'request_mode' => 'operation',
        'memory_type' => 'sector_knowledge',
        'tenant_id' => 'default',
        'app_id' => 'app_test',
        'user_id' => 'user_test',
        'query_hash' => 'hash_test',
        'runtime_budget' => [],
        'stage_latency_ms' => ['router_ms' => 10, 'skill_ms' => 0, 'rag_ms' => 0],
        'latency_ms' => 20,
        'token_usage' => null,
        'cost_estimate' => null,
        'metrics_delta' => [],
        'tenant_scope_violation_detected' => false,
        'route_path_coherent' => true,
        'rag_error' => '',
        'error_flag' => false,
        'error_type' => 'none',
    ], $overrides);
}
