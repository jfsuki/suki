<?php
// framework/tests/chat_real_20.php
// Suite de 20 conversaciones reales (builder + app) para validar flujo P0.

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\ChatAgent;
use App\Core\Database;

$agent = new ChatAgent();
$runId = (string) time();
$ctx = [
    '{entity_a}' => 'real20_clientes_' . $runId,
    '{entity_b}' => 'real20_tabla_b_' . $runId,
    '{ghost_entity}' => 'real20_ghost_' . $runId,
    '{auth_user}' => 'real20_user_' . $runId,
];

$conversations = [
    [
        'name' => 'builder_greeting',
        'steps' => [
            ['mode' => 'builder', 'message' => 'hola', 'contains' => 'SUKI'],
        ],
    ],
    [
        'name' => 'builder_capabilities',
        'steps' => [
            ['mode' => 'builder', 'message' => 'que puedo hacer aca', 'contains' => 'Puedo ayudarte con:'],
        ],
    ],
    [
        'name' => 'builder_out_of_scope_guard',
        'steps' => [
            ['mode' => 'builder', 'message' => 'sabes sobre presidente petro?', 'contains' => 'Google, ChatGPT o Gemini'],
        ],
    ],
    [
        'name' => 'app_redirect_to_builder',
        'steps' => [
            ['mode' => 'app', 'message' => 'quiero crear una tabla productos', 'contains' => 'Creador de apps'],
        ],
    ],
    [
        'name' => 'builder_redirect_to_app',
        'steps' => [
            ['mode' => 'builder', 'message' => 'crear cliente nombre=Ana', 'contains' => 'chat de la app'],
        ],
    ],
    [
        'name' => 'builder_intake_flow_services',
        'steps' => [
            ['mode' => 'builder', 'message' => 'quiero crear una app', 'contains' => 'Paso 1'],
            ['mode' => 'builder', 'message' => 'servicios', 'contains' => 'Paso 2'],
            ['mode' => 'builder', 'message' => 'mixto', 'contains' => 'Paso 4'],
            ['mode' => 'builder', 'message' => 'citas', 'contains' => 'confirmemos tu necesidad'],
            ['mode' => 'builder', 'message' => 'factura', 'contains' => 'confirmemos tu necesidad'],
        ],
    ],
    [
        'name' => 'builder_intake_flow_both',
        'steps' => [
            ['mode' => 'builder', 'message' => 'quiero crear una app', 'contains' => 'Paso 1'],
            ['mode' => 'builder', 'message' => 'ambos', 'contains' => 'Paso 2'],
            ['mode' => 'builder', 'message' => 'contado', 'contains_any' => ['Paso 3', 'Paso 4']],
            ['mode' => 'builder', 'message' => 'inventario', 'contains_any' => ['Paso 4', 'confirmemos tu necesidad']],
            ['mode' => 'builder', 'message' => 'factura', 'contains' => 'confirmemos tu necesidad'],
        ],
    ],
    [
        'name' => 'builder_create_table_confirm',
        'steps' => [
            ['mode' => 'builder', 'message' => 'quiero crear una tabla {entity_a}', 'contains' => 'Quieres que la cree'],
            ['mode' => 'builder', 'message' => 'si', 'contains' => 'Tabla creada: {entity_a}'],
            ['mode' => 'builder', 'message' => 'no', 'contains' => 'sin campos calculados'],
        ],
    ],
    [
        'name' => 'builder_formula_flow_after_table',
        'steps' => [
            ['mode' => 'builder', 'message' => 'crear tabla {entity_b} nombre:texto', 'contains_any' => ['Quieres que la cree', 'Tabla creada: {entity_b}']],
            ['mode' => 'builder', 'message' => 'si', 'contains_any' => ['Tabla creada: {entity_b}', 'Escribe la formula', 'campos calculados']],
            ['mode' => 'builder', 'message' => 'no', 'contains_any' => ['sin campos calculados', 'seguimos', 'Estado del proyecto']],
            ['mode' => 'builder', 'message' => 'estado del proyecto', 'contains_any' => ['Estado del proyecto', 'Paso 1']],
        ],
    ],
    [
        'name' => 'app_guard_missing_entity_create',
        'steps' => [
            ['mode' => 'app', 'message' => 'crear {ghost_entity} nombre=Ana', 'contains' => 'no existe'],
        ],
    ],
    [
        'name' => 'app_guard_missing_entity_list',
        'steps' => [
            ['mode' => 'app', 'message' => 'listar {ghost_entity}', 'contains' => 'no existe'],
        ],
    ],
    [
        'name' => 'app_help_options',
        'steps' => [
            ['mode' => 'app', 'message' => 'que opciones puedo usar ahora', 'contains' => 'Puedo ayudarte con:'],
        ],
    ],
    [
        'name' => 'app_status_reply',
        'steps' => [
            ['mode' => 'app', 'message' => 'dame el estado del proyecto', 'contains' => 'En esta app puedes trabajar'],
        ],
    ],
    [
        'name' => 'builder_entities_list',
        'steps' => [
            ['mode' => 'builder', 'message' => 'que tablas?', 'contains_any' => ['Tablas', 'tipo de negocio']],
        ],
    ],
    [
        'name' => 'builder_forms_list',
        'steps' => [
            ['mode' => 'builder', 'message' => 'que formularios?', 'contains' => 'Formularios'],
        ],
    ],
    [
        'name' => 'builder_unspsc_help',
        'steps' => [
            ['mode' => 'builder', 'message' => 'ayuda con codigo unspsc para medicamento', 'contains_any' => ['UNSPSC', 'Coincidencias sugeridas']],
        ],
    ],
    [
        'name' => 'builder_next_step_checklist',
        'steps' => [
            ['mode' => 'builder', 'message' => 'siguiente paso', 'contains_any' => ['Checklist BUILD', 'Paso actual', 'tipo de negocio']],
        ],
    ],
    [
        'name' => 'app_next_step_checklist',
        'steps' => [
            ['mode' => 'app', 'message' => 'siguiente paso', 'contains_any' => ['Checklist USE', 'Paso actual', 'accion concreta']],
        ],
    ],
    [
        'name' => 'auth_create_user',
        'steps' => [
            ['mode' => 'app', 'message' => 'crear usuario usuario={auth_user} rol=vendedor clave=1234', 'contains' => 'Usuario creado'],
        ],
    ],
    [
        'name' => 'auth_login_user',
        'steps' => [
            ['mode' => 'app', 'message' => 'iniciar sesion usuario={auth_user} clave=1234', 'contains' => 'Login listo'],
        ],
    ],
];

$results = [];
$conversationsPassed = 0;
$stepPass = 0;
$stepFail = 0;

foreach ($conversations as $idx => $conversation) {
    $conversationName = (string) ($conversation['name'] ?? ('conversation_' . ($idx + 1)));
    $convUser = 'real20_user_' . ($idx + 1) . '_' . $runId;
    $base = [
        'channel' => 'local',
        'tenant_id' => 'default',
        'project_id' => 'suki_erp',
        'user_id' => $convUser,
        'session_id' => 'real20_session_' . ($idx + 1) . '_' . $runId,
    ];

    $conversationOk = true;
    $conversationTrace = [];
    foreach ((array) ($conversation['steps'] ?? []) as $stepIndex => $step) {
        $mode = (string) ($step['mode'] ?? 'app');
        $rawMessage = (string) ($step['message'] ?? '');
        $message = strtr($rawMessage, $ctx);
        $payload = array_merge($base, [
            'mode' => $mode,
            'message' => $message,
        ]);
        $out = $agent->handle($payload);
        $reply = (string) ($out['data']['reply'] ?? '');
        $status = (string) ($out['status'] ?? 'error');
        $ok = $status === 'success';

        if ($ok && isset($step['contains'])) {
            $expected = strtr((string) $step['contains'], $ctx);
            $ok = mb_stripos($reply, $expected, 0, 'UTF-8') !== false;
        }
        if ($ok && isset($step['contains_any']) && is_array($step['contains_any'])) {
            $matched = false;
            foreach ($step['contains_any'] as $candidate) {
                $expected = strtr((string) $candidate, $ctx);
                if ($expected !== '' && mb_stripos($reply, $expected, 0, 'UTF-8') !== false) {
                    $matched = true;
                    break;
                }
            }
            $ok = $matched;
        }

        $conversationTrace[] = [
            'step' => $stepIndex + 1,
            'mode' => $mode,
            'message' => $message,
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
        'steps' => $conversationTrace,
    ];
}

cleanupReal20Artifacts($runId);

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

$report = [
    'summary' => $summary,
    'results' => $results,
];

$json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($json !== false) {
    $tmpDir = __DIR__ . '/tmp';
    if (!is_dir($tmpDir)) {
        @mkdir($tmpDir, 0775, true);
    }
    file_put_contents($tmpDir . '/chat_real_20_result.json', $json);
}

echo $json . PHP_EOL;
exit(($summary['ok'] ?? false) ? 0 : 1);

function cleanupReal20Artifacts(string $runId): void
{
    $projectRoot = realpath(__DIR__ . '/../..' . '/project') ?: dirname(__DIR__, 2) . '/project';
    $patterns = [
        $projectRoot . '/contracts/entities/real20_*.entity.json',
        $projectRoot . '/contracts/forms/real20_*.form.json',
        $projectRoot . '/storage/chat/profiles/default__real20_*.json',
        $projectRoot . '/storage/tenants/default/agent_state/suki_erp__app__real20_*.json',
        $projectRoot . '/storage/tenants/default/agent_state/suki_erp__builder__real20_*.json',
        $projectRoot . '/storage/tenants/default/agent_state/default__app__real20_*.json',
        $projectRoot . '/storage/tenants/default/agent_state/default__builder__real20_*.json',
    ];
    foreach ($patterns as $pattern) {
        foreach (glob($pattern) ?: [] as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    try {
        $pdo = Database::connection();
        $stmt = $pdo->query("SHOW TABLES LIKE '%real20_%'");
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_NUM) : [];
        foreach ($rows as $row) {
            $table = (string) ($row[0] ?? '');
            if ($table === '') {
                continue;
            }
            if (preg_match('/^[a-zA-Z0-9_]+$/', $table) !== 1) {
                continue;
            }
            $pdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
        }
        $cleanup = $pdo->prepare('DELETE FROM schema_migrations WHERE id LIKE :id');
        $cleanup->bindValue(':id', '%real20_%');
        $cleanup->execute();
    } catch (\Throwable $e) {
        // no-op: cleanup is best-effort for QA suite
    }
}
