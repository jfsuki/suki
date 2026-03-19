<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

$php = PHP_BINARY ?: 'php';
$auditScript = FRAMEWORK_ROOT . '/scripts/validate_audit_contracts.php';
$failures = [];

$tmpDir = FRAMEWORK_ROOT . '/tests/tmp/audit_cli_' . time() . '_' . random_int(1000, 9999);
if (!@mkdir($tmpDir, 0777, true) && !is_dir($tmpDir)) {
    $failures[] = 'No se pudo crear directorio temporal para pruebas CLI audit.';
}

if (!is_file($auditScript)) {
    $failures[] = 'Falta archivo requerido: ' . $auditScript;
}

if ($failures === []) {
    $help = runCommand($php, $auditScript, ['--help']);
    if ($help['code'] !== 0) {
        $failures[] = 'validate_audit_contracts.php --help debe salir con code 0.';
    }
    if (!str_contains($help['output'], 'framework/audit/audit_rules.json')) {
        $failures[] = 'validate_audit_contracts.php --help debe exponer los artefactos base.';
    }

    $catalogRun = runCommand($php, $auditScript, ['--strict']);
    if ($catalogRun['code'] !== 0 || !is_array($catalogRun['json']) || (($catalogRun['json']['ok'] ?? false) !== true)) {
        $failures[] = 'validate_audit_contracts.php --strict debe validar el bundle base.';
    }

    $invalidAlert = [
        'alert_id' => 'audit_alert_cli_001',
        'tenant_id' => 'tenant_cli',
        'app_id' => 'app_cli',
        'severity' => 'warning',
        'anomaly_type' => 'payment_without_invoice',
        'description' => 'CLI validation probe.',
        'evidence' => [
            'beg_trace' => [
                [
                    'event_id' => 'evt_payment_cli_001',
                    'event_type' => 'payment_event',
                    'tenant_id' => 'tenant_cli',
                    'app_id' => 'app_cli',
                ],
            ],
            'sql_refs' => [],
            'metrics_refs' => [],
        ],
        'related_events' => [
            [
                'event_id' => 'evt_payment_cli_001',
                'event_type' => 'payment_event',
                'tenant_id' => 'tenant_cli',
                'app_id' => 'app_cli',
            ],
        ],
        'related_entities' => [
            [
                'entity_type' => 'customer',
                'entity_id' => 'cust_cli_001',
                'tenant_id' => 'tenant_cli',
                'app_id' => 'app_cli',
            ],
        ],
        'confidence_score' => 0.82,
        'suggested_actions' => [
            [
                'action_id' => 'ghost.action',
                'skill_id' => 'create_task',
                'priority' => 'high',
                'reason_code' => 'investigate_evidence',
                'proposal_mode' => 'proposed_only',
            ],
        ],
        'created_at' => date('c'),
    ];

    $invalidAlertPath = $tmpDir . '/invalid_alert.json';
    file_put_contents($invalidAlertPath, json_encode($invalidAlert, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    $invalidAlertRun = runCommand($php, $auditScript, [$invalidAlertPath, '--strict']);
    if ($invalidAlertRun['code'] === 0) {
        $failures[] = 'validate_audit_contracts.php debe fallar ante suggested_action invalida.';
    } elseif (!is_array($invalidAlertRun['json']) || (($invalidAlertRun['json']['mode'] ?? '') !== 'alert')) {
        $failures[] = 'validate_audit_contracts.php debe detectar modo alert.';
    }

    $invalidConfig = [
        'agent_id' => 'business_integrity_auditor',
        'version' => '1.0.0',
        'enabled' => true,
        'thresholds' => [
            'min_confidence' => 0.7,
            'anomaly_score_threshold' => 0.65,
        ],
        'allowed_anomaly_types' => [
            'ghost_anomaly',
        ],
        'evaluation_modes' => [
            'rule_based',
        ],
        'schedule' => [
            'mode' => 'interval',
            'interval_seconds' => 600,
        ],
    ];

    $invalidConfigPath = $tmpDir . '/invalid_config.json';
    file_put_contents($invalidConfigPath, json_encode($invalidConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    $invalidConfigRun = runCommand($php, $auditScript, [$invalidConfigPath, '--strict']);
    if ($invalidConfigRun['code'] === 0) {
        $failures[] = 'validate_audit_contracts.php debe fallar ante anomaly_type invalido en config.';
    } elseif (!is_array($invalidConfigRun['json']) || (($invalidConfigRun['json']['mode'] ?? '') !== 'agent_config')) {
        $failures[] = 'validate_audit_contracts.php debe detectar modo agent_config.';
    }
}

rrmdir($tmpDir);

$result = [
    'ok' => empty($failures),
    'failures' => $failures,
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($result['ok'] ? 0 : 1);

/**
 * @param array<int, string> $args
 * @return array{code:int,output:string,json:array<string,mixed>|null}
 */
function runCommand(string $php, string $script, array $args): array
{
    $parts = [escapeshellarg($php), escapeshellarg($script)];
    foreach ($args as $arg) {
        $parts[] = escapeshellarg($arg);
    }
    $command = implode(' ', $parts);
    $output = [];
    $code = 0;
    exec($command . ' 2>&1', $output, $code);
    $raw = trim(implode("\n", $output));
    $json = json_decode($raw, true);

    return [
        'code' => $code,
        'output' => $raw,
        'json' => is_array($json) ? $json : null,
    ];
}

function rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = scandir($dir);
    if (!is_array($items)) {
        @rmdir($dir);
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            rrmdir($path);
            continue;
        }
        @unlink($path);
    }
    @rmdir($dir);
}
