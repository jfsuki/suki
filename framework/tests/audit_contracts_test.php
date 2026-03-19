<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\AuditValidator;
use App\Core\Contracts\ContractRepository;

$failures = [];

$catalogReport = AuditValidator::validateCatalog();
if (($catalogReport['ok'] ?? false) !== true) {
    $failures[] = 'El catalogo audit base debe validar.';
}

$stats = is_array($catalogReport['stats'] ?? null) ? $catalogReport['stats'] : [];
if ((int) ($stats['rules'] ?? 0) < 6) {
    $failures[] = 'Audit debe incluir al menos 6 reglas base.';
}
if ((int) ($stats['patterns'] ?? 0) < 8) {
    $failures[] = 'Audit debe incluir al menos 8 patterns base.';
}

$validConfig = [
    'agent_id' => 'business_integrity_auditor',
    'version' => '1.0.0',
    'enabled' => true,
    'thresholds' => [
        'min_confidence' => 0.7,
        'anomaly_score_threshold' => 0.65,
    ],
    'allowed_anomaly_types' => [
        'payment_without_invoice',
        'cross_tenant_link',
        'lead_stagnation_without_followup',
    ],
    'evaluation_modes' => [
        'rule_based',
        'beg_based',
        'hybrid',
    ],
    'schedule' => [
        'mode' => 'cron',
        'cron' => '0 */4 * * *',
        'timezone' => 'America/Bogota',
    ],
];

$configReport = AuditValidator::validateAgentConfig($validConfig);
if (($configReport['ok'] ?? false) !== true) {
    $failures[] = 'Audit agent config valido debe pasar.';
}

$validAlert = [
    'alert_id' => 'audit_alert_001',
    'tenant_id' => 'tenant_audit',
    'app_id' => 'app_audit',
    'severity' => 'critical',
    'anomaly_type' => 'payment_without_invoice',
    'description' => 'Potential payment event without invoice evidence.',
    'evidence' => [
        'beg_trace' => [
            [
                'event_id' => 'evt_payment_001',
                'event_type' => 'payment_event',
                'tenant_id' => 'tenant_audit',
                'app_id' => 'app_audit',
            ],
        ],
        'sql_refs' => [
            [
                'query_id' => 'audit_query_001',
                'table_name' => 'payments',
                'reference_type' => 'row_snapshot',
                'row_ref' => 'payment_id=pay_001',
            ],
        ],
        'metrics_refs' => [
            [
                'metric_name' => 'receivable_invoice_match_rate',
                'metric_scope' => 'tenant_app',
                'metric_ref' => 'kpi.receivable_invoice_match_rate',
            ],
        ],
    ],
    'related_events' => [
        [
            'event_id' => 'evt_payment_001',
            'event_type' => 'payment_event',
            'tenant_id' => 'tenant_audit',
            'app_id' => 'app_audit',
        ],
    ],
    'related_entities' => [
        [
            'entity_type' => 'customer',
            'entity_id' => 'cust_001',
            'tenant_id' => 'tenant_audit',
            'app_id' => 'app_audit',
            'role' => 'payer',
        ],
    ],
    'confidence_score' => 0.92,
    'suggested_actions' => [
        [
            'action_id' => 'ops.task.create',
            'skill_id' => 'create_task',
            'priority' => 'high',
            'reason_code' => 'investigate_evidence',
            'proposal_mode' => 'proposed_only',
        ],
        [
            'action_id' => 'report.generate',
            'skill_id' => 'generate_report',
            'priority' => 'medium',
            'reason_code' => 'generate_management_report',
            'proposal_mode' => 'proposed_only',
        ],
    ],
    'created_at' => date('c'),
];

$alertReport = AuditValidator::validateAlert($validAlert);
if (($alertReport['ok'] ?? false) !== true) {
    $failures[] = 'Audit alert valido debe pasar.';
}

$invalidRules = loadJson(FRAMEWORK_ROOT . '/audit/audit_rules.json');
$invalidRules['beg_rules'][0]['anomaly_type'] = 'ghost_anomaly';
$invalidRulesReport = AuditValidator::validateCatalog([
    'audit_rules' => $invalidRules,
]);
if (($invalidRulesReport['ok'] ?? false) === true) {
    $failures[] = 'Audit debe bloquear reglas con anomaly_type invalido.';
}

$invalidPatterns = loadJson(FRAMEWORK_ROOT . '/audit/anomaly_patterns_extended.json');
$invalidPatterns['beg_patterns'][0]['related_event_types'][] = 'ghost_event';
$invalidPatternsReport = AuditValidator::validateCatalog([
    'anomaly_patterns_extended' => $invalidPatterns,
]);
if (($invalidPatternsReport['ok'] ?? false) === true) {
    $failures[] = 'Audit debe bloquear referencias invalidas a event_type BEG.';
}

$invalidAlertType = $validAlert;
$invalidAlertType['anomaly_type'] = 'ghost_anomaly';
$invalidAlertTypeReport = AuditValidator::validateAlert($invalidAlertType);
if (($invalidAlertTypeReport['ok'] ?? false) === true) {
    $failures[] = 'Audit debe bloquear anomaly_type invalido en alertas.';
}

$invalidSuggestedAction = $validAlert;
$invalidSuggestedAction['suggested_actions'][0]['action_id'] = 'ghost.action';
$invalidSuggestedActionReport = AuditValidator::validateAlert($invalidSuggestedAction);
if (($invalidSuggestedActionReport['ok'] ?? false) === true) {
    $failures[] = 'Audit debe bloquear suggested_action invalida.';
}

$crossTenantAlert = $validAlert;
$crossTenantAlert['related_events'][0]['tenant_id'] = 'tenant_other';
$crossTenantReport = AuditValidator::validateAlert($crossTenantAlert);
if (($crossTenantReport['ok'] ?? false) === true) {
    $failures[] = 'Audit debe bloquear referencias cross-tenant.';
}

try {
    $repo = new ContractRepository();
    $agentSchema = $repo->getSchema('audit_agent.schema');
    $alertSchema = $repo->getSchema('audit_alert.schema');
    if (($agentSchema['contract_id'] ?? '') !== 'business_audit_agent_config') {
        $failures[] = 'ContractRepository debe resolver audit_agent.schema.json.';
    }
    if (($alertSchema['contract_id'] ?? '') !== 'business_audit_alert') {
        $failures[] = 'ContractRepository debe resolver audit_alert.schema.json.';
    }
} catch (Throwable $e) {
    $failures[] = 'Schema repository Audit fallo: ' . $e->getMessage();
}

$result = [
    'ok' => empty($failures),
    'failures' => $failures,
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($result['ok'] ? 0 : 1);

/**
 * @return array<string, mixed>
 */
function loadJson(string $path): array
{
    $raw = file_get_contents($path);
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    return is_array($decoded) ? $decoded : [];
}
