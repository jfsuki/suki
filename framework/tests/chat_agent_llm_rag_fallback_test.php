<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\ChatAgent;
use App\Core\IntentRouteResult;

$failures = [];
$agent = new ChatAgent();
$reflection = new ReflectionClass(ChatAgent::class);

$extractFailure = $reflection->getMethod('extractLlmFailureDetails');
$extractFailure->setAccessible(true);
$buildFailureReply = $reflection->getMethod('buildSemanticLlmFailureReply');
$buildFailureReply->setAccessible(true);
$buildTestInfo = $reflection->getMethod('buildTestInfo');
$buildTestInfo->setAccessible(true);

$details = $extractFailure->invoke(
    $agent,
    new RuntimeException(
        'User not found. | provider_errors={"gemini":"quota exceeded","openrouter":"User not found."} | provider_statuses={"gemini":"quota_exhausted","openrouter":"invalid_config"}'
    )
);

if ((string) ($details['message'] ?? '') !== 'User not found.') {
    $failures[] = 'El parser de error LLM debe conservar el mensaje base.';
}

$providerErrors = is_array($details['provider_errors'] ?? null) ? (array) $details['provider_errors'] : [];
$providerStatuses = is_array($details['provider_statuses'] ?? null) ? (array) $details['provider_statuses'] : [];
if (($providerErrors['gemini'] ?? '') !== 'quota exceeded') {
    $failures[] = 'El parser de error LLM debe extraer provider_errors.gemini.';
}
if (($providerErrors['openrouter'] ?? '') !== 'User not found.') {
    $failures[] = 'El parser de error LLM debe extraer provider_errors.openrouter.';
}
if (($providerStatuses['gemini'] ?? '') !== 'quota_exhausted') {
    $failures[] = 'El parser de error LLM debe extraer provider_statuses.gemini.';
}
if (($providerStatuses['openrouter'] ?? '') !== 'invalid_config') {
    $failures[] = 'El parser de error LLM debe extraer provider_statuses.openrouter.';
}

$route = new IntentRouteResult(
    'send_to_llm',
    '',
    [],
    [
        'semantic_context' => [
            'chunks' => [
                ['content' => 'Respuesta: Primero valida referencia y existencias.'],
                ['content' => 'Respuesta: Luego ofrece una alternativa equivalente si no hay stock.'],
            ],
        ],
    ]
);

$reply = $buildFailureReply->invoke($agent, $route, [
    'rag_used' => true,
    'evidence_gate_status' => 'passed',
    'rag_result_count' => 2,
]);

if ((string) $reply !== 'Primero valida referencia y existencias. Luego ofrece una alternativa equivalente si no hay stock.') {
    $failures[] = 'El fallback semantico por falla LLM debe construirse desde chunks RAG.';
}

$emptyReply = $buildFailureReply->invoke($agent, $route, [
    'rag_used' => false,
    'evidence_gate_status' => 'skipped_by_rule',
    'rag_result_count' => 0,
]);

if ((string) $emptyReply !== '') {
    $failures[] = 'Sin evidencia RAG valida no debe construirse fallback semantico.';
}

$testInfo = $buildTestInfo->invoke($agent, [
    'route_path' => 'cache>rules>skills>rag>llm',
    'classification' => 'llm',
    'retrieval' => [
        'retrieval_attempted' => true,
        'collection' => 'sector_knowledge',
        'retrieval_result_count' => 5,
        'top_k' => 5,
        'memory_type' => 'sector_knowledge',
    ],
    'rag_result_count' => 2,
    'retrieval_top_score' => 0.67,
], [
    'action' => 'respond_local',
    'resolved_locally' => true,
    'llm_called' => true,
    'provider_used' => 'semantic_memory',
    'llm_provider_attempted' => 'llm',
    'llm_error' => 'quota exceeded',
    'provider_errors' => ['gemini' => 'quota exceeded'],
    'provider_statuses' => ['gemini' => 'quota_exhausted'],
    'semantic_fallback_used' => true,
]);

if (($testInfo['llm_error'] ?? '') !== 'quota exceeded') {
    $failures[] = 'test_info debe exponer llm_error.';
}
if (($testInfo['provider_errors']['gemini'] ?? '') !== 'quota exceeded') {
    $failures[] = 'test_info debe exponer provider_errors.';
}
if (($testInfo['provider_statuses']['gemini'] ?? '') !== 'quota_exhausted') {
    $failures[] = 'test_info debe exponer provider_statuses.';
}
if (($testInfo['semantic_fallback_used'] ?? false) !== true) {
    $failures[] = 'test_info debe exponer semantic_fallback_used=true.';
}
if (($testInfo['llm_provider'] ?? '') !== 'llm') {
    $failures[] = 'test_info debe mantener trazabilidad del proveedor LLM intentado.';
}

$ok = $failures === [];
echo json_encode([
    'ok' => $ok,
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($ok ? 0 : 1);
