<?php
// framework/tests/chat_real_100.php
// Suite extendida de 100 conversaciones para pre-produccion.

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\ChatAgent;

$agent = new ChatAgent();
$runId = (string) time();
$domainPath = dirname(__DIR__) . '/contracts/agents/domain_playbooks.json';
$domainRaw = file_get_contents($domainPath);
$domain = is_string($domainRaw) ? json_decode($domainRaw, true) : null;
if (!is_array($domain)) {
    fwrite(STDERR, "Cannot read domain playbooks.\n");
    exit(1);
}

$solverIntents = is_array($domain['solver_intents'] ?? null) ? (array) $domain['solver_intents'] : [];
$conversations = [];

// Block A: 30 positives by sector (2 utterances x 15 sectors).
foreach ($solverIntents as $solver) {
    $sectorKey = strtoupper(trim((string) ($solver['sector_key'] ?? '')));
    $utterances = is_array($solver['utterances'] ?? null) ? array_values((array) $solver['utterances']) : [];
    foreach ([0, 4] as $i) {
        $msg = trim((string) ($utterances[$i] ?? ''));
        if ($msg === '') {
            continue;
        }
        $conversations[] = [
            'name' => 'sector_positive_' . strtolower($sectorKey) . '_' . ($i + 1),
            'steps' => [[
                'mode' => 'builder',
                'message' => $msg,
                'contains' => 'plantilla experta para ' . $sectorKey,
            ]],
        ];
    }
}

// Block B: 15 hard-negative checks (must not match same sector template).
foreach ($solverIntents as $solver) {
    $sectorKey = strtoupper(trim((string) ($solver['sector_key'] ?? '')));
    $hardNegatives = is_array($solver['hard_negatives'] ?? null) ? array_values((array) $solver['hard_negatives']) : [];
    $msg = trim((string) ($hardNegatives[0] ?? ''));
    if ($msg === '') {
        continue;
    }
    $conversations[] = [
        'name' => 'sector_hard_negative_' . strtolower($sectorKey),
        'steps' => [[
            'mode' => 'builder',
            'message' => $msg,
            'not_contains' => 'plantilla experta para ' . $sectorKey,
        ]],
    ];
}

// Block C: 20 unknown businesses (2 steps each).
$unknowns = [
    'fabricacion de drones', 'laboratorio de velas artesanales', 'servicio de impresion 3d',
    'escuela de baile urbano', 'estudio de podcast', 'cafeteria de especialidad movil',
    'diseno de joyeria personalizada', 'servicio de domotica', 'lavanderia industrial',
    'alquiler de bicicletas electricas', 'centro de entrenamiento canino', 'gestion de reciclaje empresarial',
    'produccion de kombucha', 'estudio de tatuajes', 'plataforma de suscripciones de snacks',
    'fabricacion de mobiliario gamer', 'servicio de fotografia inmobiliaria', 'microtostadora de cafe',
    'academia de programacion kids', 'taller de restauracion de muebles',
];
foreach ($unknowns as $idx => $business) {
    $conversations[] = [
        'name' => 'unknown_business_' . ($idx + 1),
        'steps' => [
            ['mode' => 'builder', 'message' => 'quiero crear una app', 'contains' => 'Paso 1'],
            [
                'mode' => 'builder',
                'message' => $business,
                'contains_any' => ['No tengo plantilla exacta', 'Pregunta 1/', 'Paso 2'],
            ],
        ],
    ];
}

// Block D: 20 guidance triggers.
$guidanceTests = [
    ['message' => 'un campo para el precio', 'contains_any' => ['decimal', 'centavos']],
    ['message' => 'guardar un valor monetario', 'contains_any' => ['decimal', 'centavos']],
    ['message' => 'campo para el telefono', 'contains_any' => ['texto', 'celular']],
    ['message' => 'guardar un celular', 'contains_any' => ['texto', 'celular']],
    ['message' => 'conectar clientes con ventas', 'contains_any' => ['conectar', 'ID']],
    ['message' => 'vincular dos tablas', 'contains_any' => ['conectar', 'ID']],
    ['message' => 'la busqueda es muy lenta', 'contains_any' => ['indice', 'busqueda']],
    ['message' => 'optimizar la tabla', 'contains_any' => ['indice', 'optimizacion']],
    ['message' => 'campo para fecha', 'contains_any' => ['date', 'fecha']],
    ['message' => 'cumpleanos del cliente', 'contains_any' => ['date', 'fecha']],
    ['message' => 'quiero relacion maestro detalle', 'contains_any' => ['relacion', 'ID']],
    ['message' => 'quiero reporte por mes', 'contains_any' => ['reporte', 'filtro', 'detalle']],
    ['message' => 'quiero importar desde excel', 'contains_any' => ['importar', 'excel', 'tipo de negocio']],
    ['message' => 'quiero mejorar seguridad de usuarios', 'contains_any' => ['roles', 'permisos']],
    ['message' => 'quiero documento de cotizacion', 'contains_any' => ['documento', 'cotizacion']],
    ['message' => 'quiero campo para whatsapp', 'contains_any' => ['texto', 'telefono']],
    ['message' => 'relacionar productos con inventario', 'contains_any' => ['conectar', 'ID']],
    ['message' => 'tarda mucho en encontrar', 'contains_any' => ['indice', 'busqueda']],
    ['message' => 'cuando paso', 'contains_any' => ['date', 'fecha']],
    ['message' => 'campo para dinero', 'contains_any' => ['decimal', 'centavos']],
];
foreach ($guidanceTests as $idx => $test) {
    $conversations[] = [
        'name' => 'builder_guidance_' . ($idx + 1),
        'steps' => [[
            'mode' => 'builder',
            'message' => (string) $test['message'],
            'contains_any' => (array) ($test['contains_any'] ?? []),
        ]],
    ];
}

// Block E: 15 safety/mode/use controls.
$safetyTests = [
    ['mode' => 'builder', 'message' => 'hola', 'contains' => 'Cami'],
    ['mode' => 'builder', 'message' => 'sabes sobre presidente petro?', 'contains' => 'Google, ChatGPT o Gemini'],
    ['mode' => 'app', 'message' => 'quiero crear una tabla productos', 'contains' => 'Creador de apps'],
    ['mode' => 'builder', 'message' => 'crear cliente nombre=Ana', 'contains' => 'chat de la app'],
    ['mode' => 'builder', 'message' => 'que puedo hacer aca', 'contains' => 'Puedo ayudarte con:'],
    ['mode' => 'app', 'message' => 'que opciones puedo usar ahora', 'contains' => 'Puedo ayudarte con:'],
    ['mode' => 'builder', 'message' => 'que tablas?', 'contains_any' => ['Tablas', 'tipo de negocio']],
    ['mode' => 'builder', 'message' => 'que formularios?', 'contains_any' => ['formularios', 'Formulario', 'Aun no hay formularios']],
    ['mode' => 'app', 'message' => 'dame el estado del proyecto', 'contains' => 'En esta app puedes trabajar'],
    ['mode' => 'builder', 'message' => 'estado del proyecto', 'contains' => 'Estado del proyecto'],
    ['mode' => 'builder', 'message' => 'cancelar', 'contains_any' => ['cancelado', 'retomamos', 'flujo']],
    ['mode' => 'builder', 'message' => 'reiniciar', 'contains_any' => ['reinicie', 'Paso 1']],
    ['mode' => 'builder', 'message' => 'retomar', 'contains_any' => ['Retomamos', 'Paso 1', 'No tengo un flujo pendiente']],
    ['mode' => 'builder', 'message' => 'atras', 'contains_any' => ['paso anterior', 'Paso', 'Volvemos']],
    ['mode' => 'builder', 'message' => 'integrar api de pagos', 'contains_any' => ['OpenAPI/Swagger', 'documentacion']],
];
foreach ($safetyTests as $idx => $test) {
    $conversations[] = [
        'name' => 'safety_mode_' . ($idx + 1),
        'steps' => [[
            'mode' => (string) $test['mode'],
            'message' => (string) $test['message'],
            'contains' => $test['contains'] ?? null,
            'contains_any' => $test['contains_any'] ?? null,
        ]],
    ];
}

// Keep exact size 100.
$conversations = array_slice($conversations, 0, 100);

$results = [];
$conversationsPassed = 0;
$stepPass = 0;
$stepFail = 0;

foreach ($conversations as $idx => $conversation) {
    $conversationName = (string) ($conversation['name'] ?? ('conversation_' . ($idx + 1)));
    $convUser = 'real100_user_' . ($idx + 1) . '_' . $runId;
    $base = [
        'channel' => 'local',
        'tenant_id' => 'default',
        'project_id' => 'suki_erp',
        'user_id' => $convUser,
        'session_id' => 'real100_session_' . ($idx + 1) . '_' . $runId,
    ];

    $conversationOk = true;
    $trace = [];
    foreach ((array) ($conversation['steps'] ?? []) as $stepIndex => $step) {
        $payload = array_merge($base, [
            'mode' => (string) ($step['mode'] ?? 'app'),
            'message' => (string) ($step['message'] ?? ''),
        ]);
        $out = $agent->handle($payload);
        $reply = (string) ($out['data']['reply'] ?? '');
        $status = (string) ($out['status'] ?? 'error');
        $ok = $status === 'success';

        $contains = isset($step['contains']) ? trim((string) $step['contains']) : '';
        if ($ok && $contains !== '') {
            $ok = mb_stripos($reply, $contains, 0, 'UTF-8') !== false;
        }

        if ($ok && isset($step['contains_any']) && is_array($step['contains_any'])) {
            $matched = false;
            foreach ((array) $step['contains_any'] as $candidate) {
                $needle = trim((string) $candidate);
                if ($needle !== '' && mb_stripos($reply, $needle, 0, 'UTF-8') !== false) {
                    $matched = true;
                    break;
                }
            }
            $ok = $matched;
        }

        $notContains = isset($step['not_contains']) ? trim((string) $step['not_contains']) : '';
        if ($ok && $notContains !== '') {
            $ok = mb_stripos($reply, $notContains, 0, 'UTF-8') === false;
        }

        $trace[] = [
            'step' => $stepIndex + 1,
            'mode' => (string) ($step['mode'] ?? 'app'),
            'message' => (string) ($step['message'] ?? ''),
            'reply' => $reply,
            'status' => $status,
            'ok' => $ok,
        ];

        if ($ok) {
            $stepPass++;
        } else {
            $stepFail++;
            $conversationOk = false;
        }
    }

    if ($conversationOk) {
        $conversationsPassed++;
    }
    $results[] = [
        'name' => $conversationName,
        'ok' => $conversationOk,
        'steps' => $trace,
    ];
}

$summary = [
    'ok' => $conversationsPassed === count($conversations) && $stepFail === 0,
    'conversations_passed' => $conversationsPassed,
    'conversations_failed' => count($conversations) - $conversationsPassed,
    'conversations_total' => count($conversations),
    'steps_passed' => $stepPass,
    'steps_failed' => $stepFail,
    'steps_total' => $stepPass + $stepFail,
    'run_id' => $runId,
    'ran_at' => date('Y-m-d H:i:s'),
];

$report = ['summary' => $summary, 'results' => $results];
$json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($json !== false) {
    $tmpDir = __DIR__ . '/tmp';
    if (!is_dir($tmpDir)) {
        @mkdir($tmpDir, 0775, true);
    }
    file_put_contents($tmpDir . '/chat_real_100_result.json', $json);
}

echo $json . PHP_EOL;
exit(($summary['ok'] ?? false) ? 0 : 1);
