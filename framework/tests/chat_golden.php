<?php
// framework/tests/chat_golden.php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\ChatAgent;
use App\Core\Database;

$agent = new ChatAgent();
$runId = (string) time();
$entity = 'golden_clientes_' . $runId;
$base = [
    'channel' => 'local',
    'tenant_id' => 'default',
    'project_id' => 'suki_erp',
    'user_id' => 'golden_user_' . $runId,
    'session_id' => 'golden_session_' . $runId,
];

$steps = [
    ['mode' => 'builder', 'message' => 'hola', 'contains' => 'Cami'],
    [
        'mode' => 'builder',
        'message' => 'quiero crear una app para inventario',
        'contains_any' => ['tipo de negocio', 'tipo de inventario', 'Paso 2', 'No tengo plantilla exacta', 'productos, servicios o ambos', 'servicios, productos o ambos'],
    ],
    ['mode' => 'builder', 'message' => 'tengo una ferreteria y pierdo plata con los cables', 'contains' => 'plantilla experta para FERRETERIA'],
    ['mode' => 'builder', 'message' => 'si', 'contains' => 'Playbook FERRETERIA validado en simulacion'],
    ['mode' => 'builder', 'message' => 'quiero crear una tabla ' . $entity, 'contains' => 'Quieres que la cree'],
    ['mode' => 'builder', 'message' => 'si', 'contains' => 'Tabla creada'],
    ['mode' => 'builder', 'message' => 'no', 'contains' => 'seguimos sin campos calculados'],
    ['mode' => 'builder', 'message' => 'crear formulario ' . $entity, 'contains' => 'Formulario creado'],
    ['mode' => 'app', 'message' => 'tengo ferreteria, compro por rollo y vendo por metro, pierdo plata con cables', 'contains' => 'factor_conversion'],
    ['mode' => 'app', 'message' => 'crear ' . $entity . ' nombre=Ana', 'contains' => 'Registro creado'],
    ['mode' => 'app', 'message' => 'quiero crear una tabla productos', 'contains' => 'Creador de apps'],
    ['mode' => 'builder', 'message' => 'crear cliente nombre=Ana', 'contains' => 'chat de la app'],
];
$correctionUser = 'golden_correction_' . $runId;
$correctionSession = 'golden_correction_sess_' . $runId;
$correctionSteps = [
    ['mode' => 'builder', 'message' => 'mi negocio es una ferreteria', 'contains' => 'Paso 2'],
    ['mode' => 'builder', 'message' => 'mixto', 'contains' => 'Paso 3'],
    ['mode' => 'builder', 'message' => 'inventario, facturacion y pagos', 'contains' => 'Paso 4'],
    ['mode' => 'builder', 'message' => 'factura, cotizacion', 'contains' => 'Negocio: Ferreteria'],
    [
        'mode' => 'builder',
        'message' => 'no soy una ferreteria, fabrico bolsos, mas parecido a modisteria',
        'contains_any' => ['Entendi tu negocio', 'No tengo plantilla exacta', 'dime en una frase que vendes o fabricas', 'He investigado tu negocio', 'Paso 4: que documentos necesitas usar?'],
        'not_contains' => 'Negocio: Ferreteria',
    ],
];
$integrationUser = 'golden_integration_' . $runId;
$integrationSession = 'golden_integration_sess_' . $runId;
$integrationSteps = [
    [
        'mode' => 'builder',
        'message' => 'integrar api de pagos',
        'contains_any' => ['OpenAPI/Swagger', 'documentacion'],
    ],
    [
        'mode' => 'builder',
        'message' => 'api=pagosx https://docs.example.com/openapi.json',
        'contains_any' => ['crear el contrato de integracion', 'la importo', 'importarla'],
    ],
];
$unknownUser = 'golden_unknown_' . $runId;
$unknownSession = 'golden_unknown_sess_' . $runId;
$unknownSteps = [
    [
        'mode' => 'builder',
        'message' => 'quiero crear una app',
        'contains_any' => ['Paso 1', 'productos, servicios o ambos'],
    ],
    [
        'mode' => 'builder',
        'message' => 'laboratorio de velas aromaticas',
        'contains_any' => ['Pregunta 1/', 'No tengo plantilla exacta'],
    ],
    [
        'mode' => 'builder',
        'message' => 'quiero controlar produccion, inventario y facturacion',
        'contains_any' => ['Pregunta 2/', 'Pregunta 3/'],
    ],
];
$workflowUser = 'golden_workflow_' . $runId;
$workflowSession = 'golden_workflow_sess_' . $runId;
$workflowSteps = [
    [
        'mode' => 'builder',
        'message' => 'crear workflow para cotizacion de ventas ' . $runId,
        'contains_any' => ['compilar este flujo', 'workflow y guardarlo'],
    ],
    [
        'mode' => 'builder',
        'message' => 'si',
        'contains_any' => ['Workflow compilado y guardado', 'rev'],
    ],
];

$results = [];
$ok = 0;

foreach ($steps as $idx => $step) {
    $payload = array_merge($base, [
        'mode' => $step['mode'],
        'message' => $step['message'],
    ]);
    $out = $agent->handle($payload);
    $reply = (string) ($out['data']['reply'] ?? '');
    $pass = evaluateGoldenStep($reply, $step);
    if ($pass) {
        $ok++;
    }
    $results[] = [
        'step' => $idx + 1,
        'mode' => $step['mode'],
        'message' => $step['message'],
        'reply' => $reply,
        'expected_contains' => $step['contains'] ?? null,
        'expected_contains_any' => $step['contains_any'] ?? null,
        'expected_not_contains' => $step['not_contains'] ?? null,
        'ok' => $pass,
    ];
}

$baseCorrection = [
    'channel' => 'local',
    'tenant_id' => 'default',
    'project_id' => 'suki_erp',
    'user_id' => $correctionUser,
    'session_id' => $correctionSession,
];
foreach ($correctionSteps as $idx => $step) {
    $payload = array_merge($baseCorrection, [
        'mode' => $step['mode'],
        'message' => $step['message'],
    ]);
    $out = $agent->handle($payload);
    $reply = (string) ($out['data']['reply'] ?? '');
    $pass = evaluateGoldenStep($reply, $step);
    if ($pass) {
        $ok++;
    }
    $results[] = [
        'step' => count($steps) + $idx + 1,
        'mode' => $step['mode'],
        'message' => $step['message'],
        'reply' => $reply,
        'expected_contains' => $step['contains'] ?? null,
        'expected_contains_any' => $step['contains_any'] ?? null,
        'expected_not_contains' => $step['not_contains'] ?? null,
        'ok' => $pass,
    ];
}

$baseIntegration = [
    'channel' => 'local',
    'tenant_id' => 'default',
    'project_id' => 'suki_erp',
    'user_id' => $integrationUser,
    'session_id' => $integrationSession,
];
foreach ($integrationSteps as $idx => $step) {
    $payload = array_merge($baseIntegration, [
        'mode' => $step['mode'],
        'message' => $step['message'],
    ]);
    $out = $agent->handle($payload);
    $reply = (string) ($out['data']['reply'] ?? '');
    $pass = evaluateGoldenStep($reply, $step);
    if ($pass) {
        $ok++;
    }
    $results[] = [
        'step' => count($steps) + count($correctionSteps) + $idx + 1,
        'mode' => $step['mode'],
        'message' => $step['message'],
        'reply' => $reply,
        'expected_contains' => $step['contains'] ?? null,
        'expected_contains_any' => $step['contains_any'] ?? null,
        'expected_not_contains' => $step['not_contains'] ?? null,
        'ok' => $pass,
    ];
}

$baseUnknown = [
    'channel' => 'local',
    'tenant_id' => 'default',
    'project_id' => 'suki_erp',
    'user_id' => $unknownUser,
    'session_id' => $unknownSession,
];
foreach ($unknownSteps as $idx => $step) {
    $payload = array_merge($baseUnknown, [
        'mode' => $step['mode'],
        'message' => $step['message'],
    ]);
    $out = $agent->handle($payload);
    $reply = (string) ($out['data']['reply'] ?? '');
    $pass = evaluateGoldenStep($reply, $step);
    if ($pass) {
        $ok++;
    }
    $results[] = [
        'step' => count($steps) + count($correctionSteps) + count($integrationSteps) + $idx + 1,
        'mode' => $step['mode'],
        'message' => $step['message'],
        'reply' => $reply,
        'expected_contains' => $step['contains'] ?? null,
        'expected_contains_any' => $step['contains_any'] ?? null,
        'expected_not_contains' => $step['not_contains'] ?? null,
        'ok' => $pass,
    ];
}

$baseWorkflow = [
    'channel' => 'local',
    'tenant_id' => 'default',
    'project_id' => 'suki_erp',
    'user_id' => $workflowUser,
    'session_id' => $workflowSession,
];
foreach ($workflowSteps as $idx => $step) {
    $payload = array_merge($baseWorkflow, [
        'mode' => $step['mode'],
        'message' => $step['message'],
    ]);
    $out = $agent->handle($payload);
    $reply = (string) ($out['data']['reply'] ?? '');
    $pass = evaluateGoldenStep($reply, $step);
    if ($pass) {
        $ok++;
    }
    $results[] = [
        'step' => count($steps) + count($correctionSteps) + count($integrationSteps) + count($unknownSteps) + $idx + 1,
        'mode' => $step['mode'],
        'message' => $step['message'],
        'reply' => $reply,
        'expected_contains' => $step['contains'] ?? null,
        'expected_contains_any' => $step['contains_any'] ?? null,
        'expected_not_contains' => $step['not_contains'] ?? null,
        'ok' => $pass,
    ];
}

$report = [
    'summary' => [
        'ok' => $ok === (count($steps) + count($correctionSteps) + count($integrationSteps) + count($unknownSteps) + count($workflowSteps)),
        'passed' => $ok,
        'failed' => (count($steps) + count($correctionSteps) + count($integrationSteps) + count($unknownSteps) + count($workflowSteps)) - $ok,
        'total' => count($steps) + count($correctionSteps) + count($integrationSteps) + count($unknownSteps) + count($workflowSteps),
        'entity' => $entity,
        'run_id' => $runId,
        'ran_at' => date('Y-m-d H:i:s'),
    ],
    'results' => $results,
];

$json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($json !== false) {
    $tmpDir = __DIR__ . '/tmp';
    if (!is_dir($tmpDir)) {
        @mkdir($tmpDir, 0777, true);
    }
    file_put_contents($tmpDir . '/chat_golden_result.json', $json);
}

cleanupGoldenArtifacts($entity);

echo $json . PHP_EOL;
exit(($report['summary']['ok'] ?? false) ? 0 : 1);

function cleanupGoldenArtifacts(string $entity): void
{
    $projectRoot = realpath(__DIR__ . '/../..' . '/project') ?: dirname(__DIR__, 2) . '/project';
    @unlink($projectRoot . '/contracts/entities/' . $entity . '.entity.json');
    @unlink($projectRoot . '/contracts/forms/' . $entity . '.form.json');
    if (preg_match('/_(\d+)$/', $entity, $m) === 1) {
        $runId = (string) ($m[1] ?? '');
        if ($runId !== '') {
            @unlink($projectRoot . '/storage/tenants/default/agent_state/golden_proj__app__golden_' . $runId . '.json');
            @unlink($projectRoot . '/storage/tenants/default/agent_state/golden_proj__builder__golden_' . $runId . '.json');
            @unlink($projectRoot . '/storage/chat/profiles/default__golden_proj__app__golden_' . $runId . '.json');
            @unlink($projectRoot . '/storage/chat/profiles/default__golden_proj__builder__golden_' . $runId . '.json');
            $workflowContracts = glob($projectRoot . '/contracts/workflows/*' . $runId . '*.workflow.contract.json') ?: [];
            foreach ($workflowContracts as $wfPath) {
                @unlink($wfPath);
            }
            $workflowHistory = glob($projectRoot . '/storage/workflows/history/*' . $runId . '*') ?: [];
            foreach ($workflowHistory as $historyDir) {
                if (!is_dir($historyDir)) {
                    @unlink($historyDir);
                    continue;
                }
                $revFiles = glob($historyDir . '/*.json') ?: [];
                foreach ($revFiles as $rev) {
                    @unlink($rev);
                }
                @rmdir($historyDir);
            }
        }
    }

    try {
        $pdo = Database::connection();
        $stmt = $pdo->query("SHOW TABLES LIKE '%" . $entity . "%'");
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_NUM) : [];
        foreach ($rows as $row) {
            $table = (string) ($row[0] ?? '');
            if ($table === '' || preg_match('/^[a-zA-Z0-9_]+$/', $table) !== 1) {
                continue;
            }
            $pdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
        }

        $cleanup = $pdo->prepare('DELETE FROM schema_migrations WHERE id LIKE :id');
        $cleanup->bindValue(':id', '%' . $entity . '%');
        $cleanup->execute();
    } catch (\Throwable $e) {
        // no-op: cleanup is best-effort to keep QA gate deterministic.
    }
}

function evaluateGoldenStep(string $reply, array $step): bool
{
    if (!empty($step['contains']) && stripos($reply, (string) $step['contains']) === false) {
        return false;
    }
    if (!empty($step['contains_any']) && is_array($step['contains_any'])) {
        $hit = false;
        foreach ($step['contains_any'] as $needle) {
            if (stripos($reply, (string) $needle) !== false) {
                $hit = true;
                break;
            }
        }
        if (!$hit) {
            return false;
        }
    }
    if (!empty($step['not_contains']) && stripos($reply, (string) $step['not_contains']) !== false) {
        return false;
    }
    return true;
}

