<?php
// framework/tests/intent_router_test.php

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

// 1) Deterministic routes stay before RAG/LLM.
putenv('ENFORCEMENT_MODE=warn');
putenv('SEMANTIC_MEMORY_ENABLED=0');
$router = new IntentRouter();

$local = $router->route(['action' => 'respond_local', 'reply' => 'hola']);
if (!$local->isLocalResponse() || $local->reply() !== 'hola') {
    $failures[] = 'respond_local route failed';
}
$localTelemetry = $local->telemetry();
if ((string) ($localTelemetry['route_reason'] ?? '') !== 'deterministic_route_resolved') {
    $failures[] = 'Ruta local debe quedar marcada como deterministica.';
}

$command = $router->route([
    'action' => 'execute_command',
    'command' => ['command' => 'CreateForm', 'entity' => 'clientes'],
], [
    'tenant_id' => 'default',
    'project_id' => 'default',
    'session_id' => 'intent_router_test',
    'mode' => 'builder',
    'role' => 'admin',
    'is_authenticated' => true,
    'auth_tenant_id' => 'default',
]);
if (!$command->isCommand() || (string) (($command->command()['command'] ?? '')) !== 'CreateForm') {
    $failures[] = 'execute_command route failed';
}
$commandTelemetry = $command->telemetry();
if ((string) ($commandTelemetry['route_path'] ?? '') !== 'cache>rules>action_contract') {
    $failures[] = 'Ruta ejecutable debe permanecer en cache>rules>action_contract.';
}

// 2) Disabled semantic memory must be explicit and controlled.
$disabled = $router->route([
    'action' => 'send_to_llm',
    'llm_request' => [
        'messages' => [
            ['role' => 'user', 'content' => 'Como configuro Qdrant en produccion?'],
        ],
        'user_message' => 'Como configuro Qdrant en produccion?',
    ],
], [
    'tenant_id' => 'default',
    'project_id' => 'default',
    'session_id' => 'intent_router_disabled',
    'mode' => 'app',
    'role' => 'admin',
]);
if (!$disabled->isLocalResponse()) {
    $failures[] = 'Con semantic memory deshabilitada en warn debe degradar a respuesta local controlada.';
}
$disabledTelemetry = $disabled->telemetry();
if ((string) ($disabledTelemetry['semantic_memory_status'] ?? '') !== 'disabled') {
    $failures[] = 'Cuando semantic memory esta deshabilitada debe quedar trazado en telemetry.';
}
if ((string) ($disabledTelemetry['request_mode'] ?? '') !== 'operation') {
    $failures[] = 'Sin request_mode explicito debe usar operation por defecto.';
}
if ((string) ($disabledTelemetry['evidence_gate_status'] ?? '') !== 'disabled_by_config') {
    $failures[] = 'Evidence gate debe reportar disabled_by_config cuando semantic memory esta apagada.';
}
if ((string) ($disabledTelemetry['fallback_reason'] ?? '') !== 'semantic_memory_unavailable') {
    $failures[] = 'Fallback reason debe indicar semantic_memory_unavailable.';
}
if ((string) ($disabledTelemetry['memory_type'] ?? '') !== 'sector_knowledge') {
    $failures[] = 'IntentRouter debe fijar memory_type explicito para retrieval.';
}
if ((int) (($disabledTelemetry['metrics_delta']['semantic_disabled_requests'] ?? 0)) !== 1) {
    $failures[] = 'Semantic disabled debe quedar contado en metrics_delta.';
}

// 3) Trivial query skips RAG by rule and can continue to LLM.
putenv('SEMANTIC_MEMORY_ENABLED=1');
$trivialSemantic = buildSemanticService([], false);
$trivialRouter = new IntentRouter(null, 'warn', null, $trivialSemantic);
$trivial = $trivialRouter->route([
    'action' => 'send_to_llm',
    'llm_request' => [
        'messages' => [
            ['role' => 'user', 'content' => 'gracias'],
        ],
        'user_message' => 'gracias',
    ],
], [
    'tenant_id' => 'default',
    'project_id' => 'default',
    'session_id' => 'intent_router_trivial',
    'mode' => 'app',
    'role' => 'admin',
]);
if (!$trivial->isLlmRequest()) {
    $failures[] = 'Consulta trivial no debe activar evidence gate tecnico ni degradar innecesariamente.';
}
$trivialTelemetry = $trivial->telemetry();
if ((bool) ($trivialTelemetry['rag_attempted'] ?? true)) {
    $failures[] = 'Consulta trivial no debe intentar RAG.';
}
if ((string) ($trivialTelemetry['evidence_gate_status'] ?? '') !== 'skipped_by_rule') {
    $failures[] = 'Consulta trivial debe marcar evidence_gate_status=skipped_by_rule.';
}
if ((string) ($trivialTelemetry['skill_result_status'] ?? '') !== 'no_skill_match') {
    $failures[] = 'Consulta trivial sin skill debe dejar skill_result_status=no_skill_match.';
}

// 4) Technical query without matching skill still uses RAG, dedupes context, then falls back to LLM.
$ragChunks = [
    [
        'memory_type' => 'sector_knowledge',
        'tenant_id' => 'tenant_demo',
        'app_id' => 'app_demo',
        'sector' => 'retail',
        'source_type' => 'training_dataset',
        'source_id' => 'qdrant_guide',
        'source' => 'qdrant_guide',
        'chunk_id' => 'dup_1',
        'type' => 'knowledge',
        'tags' => ['sector:retail'],
        'version' => '1.0.0',
        'quality_score' => 0.95,
        'created_at' => '2026-03-09T00:00:00+00:00',
        'updated_at' => '2026-03-09T00:00:00+00:00',
        'metadata' => [],
        'content' => 'Qdrant debe operar con colecciones separadas por memory_type.',
    ],
    [
        'memory_type' => 'sector_knowledge',
        'tenant_id' => 'tenant_demo',
        'app_id' => 'app_demo',
        'sector' => 'retail',
        'source_type' => 'training_dataset',
        'source_id' => 'qdrant_guide',
        'source' => 'qdrant_guide',
        'chunk_id' => 'dup_1',
        'type' => 'knowledge',
        'tags' => ['sector:retail'],
        'version' => '1.0.0',
        'quality_score' => 0.95,
        'created_at' => '2026-03-09T00:00:00+00:00',
        'updated_at' => '2026-03-09T00:00:00+00:00',
        'metadata' => [],
        'content' => 'Qdrant debe operar con colecciones separadas por memory_type.',
    ],
    [
        'memory_type' => 'sector_knowledge',
        'tenant_id' => 'tenant_demo',
        'app_id' => 'app_demo',
        'sector' => 'retail',
        'source_type' => 'training_dataset',
        'source_id' => 'tenant_guide',
        'source' => 'tenant_guide',
        'chunk_id' => 'uniq_2',
        'type' => 'knowledge',
        'tags' => ['sector:retail'],
        'version' => '1.0.0',
        'quality_score' => 0.94,
        'created_at' => '2026-03-09T00:00:00+00:00',
        'updated_at' => '2026-03-09T00:00:00+00:00',
        'metadata' => [],
        'content' => 'tenant_id y app_id deben filtrarse en retrieval.',
    ],
    [
        'memory_type' => 'sector_knowledge',
        'tenant_id' => 'tenant_demo',
        'app_id' => 'app_demo',
        'sector' => 'retail',
        'source_type' => 'training_dataset',
        'source_id' => 'evidence_gate',
        'source' => 'evidence_gate',
        'chunk_id' => 'uniq_3',
        'type' => 'knowledge',
        'tags' => ['sector:retail'],
        'version' => '1.0.0',
        'quality_score' => 0.93,
        'created_at' => '2026-03-09T00:00:00+00:00',
        'updated_at' => '2026-03-09T00:00:00+00:00',
        'metadata' => [],
        'content' => 'El evidence gate debe bloquear respuestas tecnicas sin corpus util.',
    ],
    [
        'memory_type' => 'sector_knowledge',
        'tenant_id' => 'tenant_demo',
        'app_id' => 'app_demo',
        'sector' => 'retail',
        'source_type' => 'training_dataset',
        'source_id' => 'extra_context',
        'source' => 'extra_context',
        'chunk_id' => 'uniq_4',
        'type' => 'knowledge',
        'tags' => ['sector:retail'],
        'version' => '1.0.0',
        'quality_score' => 0.92,
        'created_at' => '2026-03-09T00:00:00+00:00',
        'updated_at' => '2026-03-09T00:00:00+00:00',
        'metadata' => [],
        'content' => 'Este chunk no debe entrar si se supera el limite de contexto.',
    ],
];
$ragSemantic = buildSemanticService($ragChunks, false);
$ragRouter = new IntentRouter(null, 'warn', null, $ragSemantic);
$rag = $ragRouter->route([
    'action' => 'send_to_llm',
    'llm_request' => [
        'messages' => [
            ['role' => 'user', 'content' => 'Como configuro Qdrant para tenant y app?'],
        ],
        'user_message' => 'Como configuro Qdrant para tenant y app?',
    ],
], [
    'tenant_id' => 'tenant_demo',
    'app_id' => 'app_demo',
    'sector' => 'retail',
    'session_id' => 'intent_router_rag',
    'mode' => 'app',
    'role' => 'admin',
]);
if (!$rag->isLlmRequest()) {
    $failures[] = 'Consulta tecnica con evidencia valida debe llegar a LLM como ultimo recurso.';
}
$ragTelemetry = $rag->telemetry();
if (!(bool) ($ragTelemetry['rag_attempted'] ?? false) || !(bool) ($ragTelemetry['rag_used'] ?? false)) {
    $failures[] = 'Consulta tecnica debe dejar rag_attempted=true y rag_used=true.';
}
if ((string) ($ragTelemetry['request_mode'] ?? '') !== 'operation') {
    $failures[] = 'Consulta tecnica normal debe quedar en request_mode=operation.';
}
if ((string) ($ragTelemetry['evidence_gate_status'] ?? '') !== 'passed') {
    $failures[] = 'Consulta tecnica con retrieval util debe marcar evidence_gate_status=passed.';
}
if ((string) ($ragTelemetry['route_reason'] ?? '') !== 'llm_after_verified_rag') {
    $failures[] = 'Con retrieval exitoso route_reason debe indicar llm_after_verified_rag.';
}
if ((string) ($ragTelemetry['route_path'] ?? '') !== 'cache>rules>skills>rag>llm') {
    $failures[] = 'Ruta tecnica debe dejar route_path completo cache>rules>skills>rag>llm.';
}
if ((bool) ($ragTelemetry['skill_detected'] ?? true)) {
    $failures[] = 'Consulta tecnica generica no debe detectar skill si no hay match explicito.';
}
$semanticContext = is_array($rag->llmRequest()['semantic_context'] ?? null) ? (array) $rag->llmRequest()['semantic_context'] : [];
$semanticChunks = is_array($semanticContext['chunks'] ?? null) ? (array) $semanticContext['chunks'] : [];
if (count($semanticChunks) !== 2) {
    $failures[] = 'Contexto semantico debe deduplicarse y limitarse al budget de operation.';
}
$chunkIds = [];
foreach ($semanticChunks as $chunk) {
    if (is_array($chunk)) {
        $chunkIds[] = (string) ($chunk['chunk_id'] ?? '');
    }
}
if (count(array_unique(array_filter($chunkIds))) !== 2) {
    $failures[] = 'Contexto semantico no debe repetir chunks duplicados.';
}
if ((string) ($ragTelemetry['tenant_id'] ?? '') !== 'tenant_demo' || (string) ($ragTelemetry['app_id'] ?? '') !== 'app_demo') {
    $failures[] = 'Telemetry debe conservar el scope tenant/app aplicado.';
}
if ((int) (($ragTelemetry['metrics_delta']['routed_by_rag'] ?? 0)) !== 1) {
    $failures[] = 'Consulta tecnica con contexto util debe contar routed_by_rag=1.';
}

// 5) Explicit dataset lookup should resolve through skills before RAG/LLM.
$skillRag = $ragRouter->route([
    'action' => 'send_to_llm',
    'llm_request' => [
        'messages' => [
            ['role' => 'user', 'content' => 'Busca en el dataset la politica de tenant_id y app_id.'],
        ],
        'user_message' => 'Busca en el dataset la politica de tenant_id y app_id.',
    ],
], [
    'tenant_id' => 'tenant_demo',
    'app_id' => 'app_demo',
    'sector' => 'retail',
    'session_id' => 'intent_router_skill_rag',
    'mode' => 'app',
    'role' => 'admin',
]);
if (!$skillRag->isLlmRequest()) {
    $failures[] = 'dataset_lookup debe continuar a RAG/LLM como ruta skill controlada.';
}
$skillRagTelemetry = $skillRag->telemetry();
if (!(bool) ($skillRagTelemetry['skill_detected'] ?? false) || (string) ($skillRagTelemetry['skill_selected'] ?? '') !== 'dataset_lookup') {
    $failures[] = 'dataset_lookup debe detectarse sin LLM.';
}
if (!(bool) ($skillRagTelemetry['skill_executed'] ?? false) || (string) ($skillRagTelemetry['skill_result_status'] ?? '') !== 'continued_to_rag') {
    $failures[] = 'dataset_lookup debe ejecutarse y continuar hacia RAG.';
}
if ((string) ($skillRagTelemetry['route_path'] ?? '') !== 'cache>rules>skills>rag>llm') {
    $failures[] = 'Skill RAG debe dejar route_path=cache>rules>skills>rag>llm.';
}
if ((string) ($skillRagTelemetry['route_reason'] ?? '') !== 'llm_after_skill_and_verified_rag') {
    $failures[] = 'Skill RAG con evidencia valida debe dejar route_reason=llm_after_skill_and_verified_rag.';
}
$skillContext = is_array($skillRag->llmRequest()['skill_context'] ?? null) ? (array) $skillRag->llmRequest()['skill_context'] : [];
if ((string) ($skillContext['name'] ?? '') !== 'dataset_lookup') {
    $failures[] = 'Skill context debe propagarse al llm_request.';
}

// 6) Tool skill without attachment must stop before RAG/LLM with controlled fallback.
$toolSkillRoute = $ragRouter->route([
    'action' => 'send_to_llm',
    'llm_request' => [
        'messages' => [
            ['role' => 'user', 'content' => 'Analiza esta imagen del producto.'],
        ],
        'user_message' => 'Analiza esta imagen del producto.',
    ],
], [
    'tenant_id' => 'tenant_demo',
    'app_id' => 'app_demo',
    'session_id' => 'intent_router_skill_tool',
    'mode' => 'app',
    'role' => 'admin',
    'attachments_count' => 0,
]);
if (!$toolSkillRoute->isLocalResponse()) {
    $failures[] = 'image_analysis sin adjunto debe responder local antes de RAG/LLM.';
}
$toolSkillTelemetry = $toolSkillRoute->telemetry();
if ((string) ($toolSkillTelemetry['skill_selected'] ?? '') !== 'image_analysis') {
    $failures[] = 'image_analysis debe detectarse como skill.';
}
if ((string) ($toolSkillTelemetry['route_path'] ?? '') !== 'cache>rules>skills') {
    $failures[] = 'Skill tool bloqueado por falta de adjunto debe dejar route_path=cache>rules>skills.';
}
if ((string) ($toolSkillTelemetry['skill_result_status'] ?? '') !== 'needs_input') {
    $failures[] = 'Skill tool sin adjunto debe pedir input faltante.';
}
if ((string) ($toolSkillTelemetry['skill_fallback_reason'] ?? '') !== 'attachment_required') {
    $failures[] = 'Skill tool sin adjunto debe dejar attachment_required.';
}
if ((bool) ($toolSkillTelemetry['rag_attempted'] ?? true)) {
    $failures[] = 'Skill tool bloqueado no debe intentar RAG.';
}

// 7) Retrieval errors are explicit and degrade in a controlled way.
$errorSemantic = buildSemanticService($ragChunks, true);
$errorRouter = new IntentRouter(null, 'warn', null, $errorSemantic);
$errorRoute = $errorRouter->route([
    'action' => 'send_to_llm',
    'llm_request' => [
        'messages' => [
            ['role' => 'user', 'content' => 'Como diagnostico un error de retrieval?'],
        ],
        'user_message' => 'Como diagnostico un error de retrieval?',
    ],
], [
    'tenant_id' => 'tenant_demo',
    'app_id' => 'app_demo',
    'session_id' => 'intent_router_rag_error',
    'mode' => 'app',
    'role' => 'admin',
]);
if (!$errorRoute->isLocalResponse()) {
    $failures[] = 'Ante error de retrieval en warn debe haber fallback controlado.';
}
$errorTelemetry = $errorRoute->telemetry();
if ((string) ($errorTelemetry['fallback_reason'] ?? '') !== 'rag_error') {
    $failures[] = 'Fallback por error de retrieval debe dejar fallback_reason=rag_error.';
}
if ((string) ($errorTelemetry['evidence_gate_status'] ?? '') !== 'insufficient_evidence') {
    $failures[] = 'Error de retrieval debe dejar evidence_gate_status=insufficient_evidence.';
}
if ((string) ($errorTelemetry['reason'] ?? '') !== 'rag_error') {
    $failures[] = 'Telemetry debe exponer causa rag_error.';
}
if ((int) (($errorTelemetry['metrics_delta']['rag_errors'] ?? 0)) !== 1) {
    $failures[] = 'RAG error debe reflejarse en metrics_delta.';
}

// 8) Research mode must allow a wider budget than operation.
$operationRoute = $ragRouter->route([
    'action' => 'send_to_llm',
    'llm_request' => [
        'messages' => [
            ['role' => 'user', 'content' => 'Como configuro Qdrant para tenant y app?'],
        ],
        'user_message' => 'Como configuro Qdrant para tenant y app?',
    ],
], [
    'tenant_id' => 'tenant_demo',
    'app_id' => 'app_demo',
    'sector' => 'retail',
    'session_id' => 'intent_router_operation_budget',
    'mode' => 'app',
    'role' => 'admin',
    'request_mode' => 'operation',
]);
$researchRoute = $ragRouter->route([
    'action' => 'send_to_llm',
    'llm_request' => [
        'messages' => [
            ['role' => 'user', 'content' => 'Como configuro Qdrant para tenant y app?'],
        ],
        'user_message' => 'Como configuro Qdrant para tenant y app?',
    ],
], [
    'tenant_id' => 'tenant_demo',
    'app_id' => 'app_demo',
    'sector' => 'retail',
    'session_id' => 'intent_router_research_budget',
    'mode' => 'app',
    'role' => 'admin',
    'request_mode' => 'research',
]);
$operationTelemetry = $operationRoute->telemetry();
$researchTelemetry = $researchRoute->telemetry();
$operationChunks = is_array($operationRoute->llmRequest()['semantic_context']['chunks'] ?? null)
    ? (array) $operationRoute->llmRequest()['semantic_context']['chunks']
    : [];
$researchChunks = is_array($researchRoute->llmRequest()['semantic_context']['chunks'] ?? null)
    ? (array) $researchRoute->llmRequest()['semantic_context']['chunks']
    : [];
if ((string) ($operationTelemetry['request_mode'] ?? '') !== 'operation') {
    $failures[] = 'operation debe persistir request_mode=operation.';
}
if ((string) ($researchTelemetry['request_mode'] ?? '') !== 'research') {
    $failures[] = 'research debe persistir request_mode=research.';
}
if ((int) (($operationTelemetry['runtime_budget']['max_context_chunks'] ?? 0)) >= (int) (($researchTelemetry['runtime_budget']['max_context_chunks'] ?? 0))) {
    $failures[] = 'research debe tener budget de contexto mayor que operation.';
}
if (count($operationChunks) !== 2) {
    $failures[] = 'operation debe recortar semantic_context al budget conservador.';
}
if (count($researchChunks) !== 4) {
    $failures[] = 'research debe permitir un semantic_context mas amplio.';
}

// 9) Budget overflow must stop execution in a controlled way.
$budgetRouter = new IntentRouter(null, 'warn', null, $trivialSemantic);
$budgetBlocked = $budgetRouter->route([
    'action' => 'execute_command',
    'command' => ['command' => 'CreateForm', 'entity' => 'clientes'],
], [
    'tenant_id' => 'default',
    'project_id' => 'default',
    'session_id' => 'intent_router_budget_guard',
    'mode' => 'builder',
    'role' => 'admin',
    'is_authenticated' => true,
    'auth_tenant_id' => 'default',
    'tool_calls_count' => 3,
    'request_mode' => 'operation',
]);
$budgetTelemetry = $budgetBlocked->telemetry();
if (!$budgetBlocked->isLocalResponse()) {
    $failures[] = 'Exceso de tool_calls debe cortar la ruta con respuesta local segura.';
}
if (!(bool) ($budgetTelemetry['loop_guard_triggered'] ?? false)) {
    $failures[] = 'Budget overflow debe activar loop_guard_triggered.';
}
if ((string) ($budgetTelemetry['loop_guard_reason'] ?? '') !== 'tool_calls_budget_exceeded') {
    $failures[] = 'Exceso de tool_calls debe dejar razon explicita.';
}

// 10) Repeating the same route without progress must trigger anti-loop.
$loopRouter = new IntentRouter(null, 'warn', null, $ragSemantic);
$loopQuery = 'Como configuro Qdrant para tenant y app?';
$loopHash = queryHash($loopQuery);
$loopRoute = $loopRouter->route([
    'action' => 'send_to_llm',
    'llm_request' => [
        'messages' => [
            ['role' => 'user', 'content' => $loopQuery],
        ],
        'user_message' => $loopQuery,
    ],
    'state' => [
        'agentops_trace_history' => [
            [
                'route_path' => 'cache>rules>skills>rag>llm',
                'route_reason' => 'llm_after_verified_rag',
                'request_mode' => 'operation',
                'query_hash' => $loopHash,
            ],
            [
                'route_path' => 'cache>rules>skills>rag>llm',
                'route_reason' => 'llm_after_verified_rag',
                'request_mode' => 'operation',
                'query_hash' => $loopHash,
            ],
        ],
    ],
], [
    'tenant_id' => 'tenant_demo',
    'app_id' => 'app_demo',
    'sector' => 'retail',
    'session_id' => 'intent_router_loop_guard',
    'mode' => 'app',
    'role' => 'admin',
    'request_mode' => 'operation',
]);
$loopTelemetry = $loopRoute->telemetry();
if (!$loopRoute->isLocalResponse()) {
    $failures[] = 'Anti-loop debe cortar la repeticion sin progreso con respuesta local.';
}
if ((string) ($loopTelemetry['loop_guard_reason'] ?? '') !== 'repeated_route_without_progress') {
    $failures[] = 'Anti-loop debe dejar razon repeated_route_without_progress.';
}
if ((int) ($loopTelemetry['same_route_repeat_count'] ?? 0) < 2) {
    $failures[] = 'Anti-loop debe contar las repeticiones previas de la misma ruta.';
}
if ((int) (($loopTelemetry['metrics_delta']['loop_guard_hits'] ?? 0)) !== 1) {
    $failures[] = 'Anti-loop debe reflejarse en metrics_delta.';
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

/**
 * @param array<int,array<string,mixed>> $chunks
 */
function buildSemanticService(array $chunks, bool $failOnQuery): SemanticMemoryService
{
    $storedPoints = [];
    $collections = [];

    $embeddingTransport = static function (string $method, string $url, array $headers, array $payload, int $timeoutSec): array {
        $text = trim((string) ($payload['content']['parts'][0]['text'] ?? ''));
        $value = min(1.0, max(0.1, strlen($text) / 100.0));
        return [
            'status' => 200,
            'data' => [
                'embedding' => [
                    'values' => array_fill(0, 768, $value),
                ],
            ],
        ];
    };

    $qdrantTransport = static function (string $method, string $url, array $headers, array $payload, int $timeoutSec) use (&$storedPoints, &$collections, $failOnQuery): array {
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
            $storedPoints = is_array($payload['points'] ?? null) ? (array) $payload['points'] : [];
            return ['status' => 200, 'data' => ['result' => ['status' => 'acknowledged']]];
        }

        if ($method === 'POST' && str_contains($url, '/points/query')) {
            if ($failOnQuery) {
                return ['status' => 500, 'data' => ['status' => ['error' => 'forced_query_failure']]];
            }
            $points = [];
            foreach ($storedPoints as $point) {
                if (!is_array($point)) {
                    continue;
                }
                $pointPayload = is_array($point['payload'] ?? null) ? (array) $point['payload'] : [];
                if (!matchesFilter($pointPayload, is_array($payload['filter'] ?? null) ? (array) $payload['filter'] : [])) {
                    continue;
                }
                $points[] = [
                    'id' => $point['id'] ?? null,
                    'score' => 0.92,
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
    if ($chunks !== []) {
        $service->ingestSectorKnowledge($chunks);
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

function queryHash(string $query): string
{
    $query = strtolower(trim(preg_replace('/\s+/', ' ', $query) ?? $query));
    return substr(sha1($query), 0, 16);
}
