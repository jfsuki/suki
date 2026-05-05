<?php
// framework/tests/semantic_intent_router_chat_test.php
// TEST: IntentRouter con SemanticMemoryService real (Qdrant + Gemini).
//
// PRINCIPIO: Cero frases exactas del training data. Variantes semánticas,
// modismos colombianos, errores tipográficos. El router debe entender,
// no hacer match por keyword.
//
// Exit 0 = PASS. Exit 1 = FAIL con evidencia.

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\ChatAgent;
use App\Core\IntentRouter;

$failures = [];
$previousMode = getenv('ENFORCEMENT_MODE');
putenv('ENFORCEMENT_MODE=warn');

$geminiKey = (string) (getenv('GEMINI_API_KEY') ?: '');
$qdrantUrl = (string) (getenv('QDRANT_URL') ?: 'http://localhost:6333');

if ($geminiKey === '') {
    // Sin Gemini, el SemanticMemoryService no puede embeber — test incompleto pero no bloqueante
    echo json_encode([
        'ok'       => true,
        'skipped'  => true,
        'reason'   => 'GEMINI_API_KEY no configurada — SemanticMemoryService requiere embeddings reales.',
        'failures' => [],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
}

// ── ROUTER con Qdrant/Gemini reales ──────────────────────────────────────────
// El SemanticMemoryService se construye desde el entorno (no mocks).
// Los chunks de sector se ingestan en Qdrant real y se consultan con embeddings reales.

$router = new IntentRouter(null, 'warn');

// ── CASOS: frases variadas, NO literales del training data ───────────────────
// Cada caso tiene la frase del usuario y una descripción del comportamiento esperado.
// No se comparan intents exactos — se verifica que:
//   1. No caiga a loop_guard_blocked_before_llm
//   2. Tenga un route_reason definido (no vacío)
//   3. No retorne el fallback genérico "Solo puedo responder con datos reales"

$routerCases = [
    [
        'message'     => 'se acabó el producto ese, cuánto hay en la bodega',
        'mode'        => 'app',
        'description' => 'consulta de inventario con variante regional',
    ],
    [
        'message'     => 'necesito cobrarle a ese cliente ya',
        'mode'        => 'app',
        'description' => 'facturación informal colombiana',
    ],
    [
        'message'     => 'cuánto me cuesta ese artículo pues',
        'mode'        => 'app',
        'description' => 'consulta de precio con modismo paisa',
    ],
    [
        'message'     => 'quiero digitalizar mi negocio de abarrotes',
        'mode'        => 'builder',
        'description' => 'onboarding con sector específico colombiano',
    ],
    [
        'message'     => 'epa cómo están? necesito ayuda con mis proveedores',
        'mode'        => 'app',
        'description' => 'saludo + solicitud combinada',
    ],
];

foreach ($routerCases as $idx => $case) {
    $context = [
        'tenant_id'      => 'test_semantic_router',
        'app_id'         => 'test_app',
        'project_id'     => 'test_app',
        'session_id'     => 'semantic_router_' . $idx,
        'mode'           => $case['mode'],
        'role'           => 'admin',
        'is_authenticated' => true,
        'message_text'   => $case['message'],
    ];

    try {
        $route = $router->route([
            'action'      => 'send_to_llm',
            'llm_request' => [
                'messages'     => [['role' => 'user', 'content' => $case['message']]],
                'user_message' => $case['message'],
            ],
        ], $context);

        $telemetry = $route->telemetry();
        $reason    = (string) ($telemetry['route_reason'] ?? '');

        if ($reason === 'loop_guard_blocked_before_llm') {
            $failures[] = sprintf(
                '[loop_guard] "%s" — Router bloqueó antes del LLM sin motivo válido. Descripción: %s',
                $case['message'], $case['description']
            );
        }
        if ($reason === '') {
            $failures[] = sprintf(
                '[sin_route_reason] "%s" — Router no registró route_reason. Descripción: %s',
                $case['message'], $case['description']
            );
        }
    } catch (\Throwable $e) {
        $failures[] = sprintf(
            '[excepcion] "%s" → %s. Descripción: %s',
            $case['message'], $e->getMessage(), $case['description']
        );
    }
}

// ── CHAT AGENT: variantes de expresión colombiana ────────────────────────────
$chatAgent = new ChatAgent();

$chatCases = [
    [
        'message'       => 'uy parcero me quedé sin cemento en bodega',
        'not_contains'  => 'Solo puedo responder con datos reales de esta app.',
        'mode'          => 'app',
        'description'   => 'frase costeña para inventario vacío',
    ],
    [
        'message'       => 'mirá, hay que facturarle al de la ferretería esa',
        'not_contains'  => 'Solo puedo responder con datos reales de esta app.',
        'mode'          => 'app',
        'description'   => 'facturación con pronombre colombiano',
    ],
    [
        'message'       => 'buenas tardes, con que me podés ayudar?',
        'not_contains'  => 'Solo puedo responder con datos reales de esta app.',
        'mode'          => 'app',
        'description'   => 'saludo formal con voseo rioplatense/colombiano',
    ],
];

foreach ($chatCases as $idx => $case) {
    try {
        $reply = $chatAgent->handle([
            'tenant_id'        => 'test_semantic_chat',
            'project_id'       => 'test_app',
            'session_id'       => 'semantic_chat_' . $idx,
            'user_id'          => 'test_user',
            'role'             => 'admin',
            'is_authenticated' => true,
            'mode'             => $case['mode'],
            'channel'          => 'test',
            'message_id'       => 'chat_msg_' . $idx,
            'message'          => $case['message'],
        ]);
        $replyText = (string) ($reply['data']['reply'] ?? $reply['reply'] ?? '');

        if (str_contains($replyText, $case['not_contains'])) {
            $failures[] = sprintf(
                '[fallback_generico] "%s" — ChatAgent retornó fallback genérico. Descripción: %s',
                $case['message'], $case['description']
            );
        }
        if (trim($replyText) === '') {
            $failures[] = sprintf(
                '[respuesta_vacia] "%s" — ChatAgent retornó string vacío. Descripción: %s',
                $case['message'], $case['description']
            );
        }
    } catch (\Throwable $e) {
        $failures[] = sprintf(
            '[excepcion_chat] "%s" → %s. Descripción: %s',
            $case['message'], $e->getMessage(), $case['description']
        );
    }
}

if ($previousMode === false) {
    putenv('ENFORCEMENT_MODE');
} else {
    putenv('ENFORCEMENT_MODE=' . $previousMode);
}

$ok = empty($failures);
echo json_encode([
    'ok'       => $ok,
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 1);
