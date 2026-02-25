<?php
// framework/tests/unknown_business_quality_report_test.php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\SqlMemoryRepository;

$tenantId = 'quality_report_test_' . time();
$projectId = 'staging_llm_smoke';
$memory = new SqlMemoryRepository();

$now = date('c');
$memory->saveTenantMemory($tenantId, 'unknown_business_llm_samples', [
    'updated_at' => $now,
    'items' => [
        [
            'at' => $now,
            'tenant_id' => $tenantId,
            'user_id' => 'u1',
            'candidate' => 'panaderia',
            'status' => 'MATCHED',
            'confidence' => 0.92,
            'quality_ok' => true,
            'quality_score' => 0.95,
            'provider_used' => 'gemini',
            'quality_issues' => [],
            'needs_normalized' => ['inventario', 'ventas'],
            'documents_normalized' => ['factura', 'ticket'],
        ],
        [
            'at' => $now,
            'tenant_id' => $tenantId,
            'user_id' => 'u2',
            'candidate' => 'velas aromaticas',
            'status' => 'NEW_BUSINESS',
            'confidence' => 0.89,
            'quality_ok' => false,
            'quality_score' => 0.62,
            'provider_used' => 'openrouter',
            'quality_issues' => ['new_business_sin_documents'],
            'needs_normalized' => ['produccion', 'inventario'],
            'documents_normalized' => [],
        ],
    ],
]);
$memory->saveTenantMemory($tenantId, 'research_queue', [
    'topics' => [
        [
            'topic' => 'velas aromaticas:llm_quality',
            'count' => 2,
            'status' => 'pending_research',
            'last_seen' => $now,
        ],
    ],
]);

$script = FRAMEWORK_ROOT . '/scripts/unknown_business_quality_report.php';
$cmd = escapeshellarg(PHP_BINARY)
    . ' '
    . escapeshellarg($script)
    . ' --tenant=' . $tenantId
    . ' --project=' . $projectId
    . ' --days=1'
    . ' --min_samples=2'
    . ' --min_quality_ok_rate=0.5'
    . ' --min_avg_score=0.5'
    . ' --max_missing_scope_rate=0.8';

$output = [];
$exit = 0;
exec($cmd . ' 2>&1', $output, $exit);
$raw = trim(implode("\n", $output));
$json = json_decode($raw, true);
$failures = [];

if (!is_array($json)) {
    $failures[] = 'El reporte no devolvio JSON valido.';
} else {
    if (($json['ok'] ?? false) !== true) {
        $failures[] = 'El reporte deberia quedar en verde con umbrales de prueba.';
    }
    $kpis = is_array($json['kpis'] ?? null) ? (array) $json['kpis'] : [];
    if ((int) ($kpis['samples_total'] ?? 0) !== 2) {
        $failures[] = 'samples_total esperado = 2.';
    }
    if (!isset($kpis['status_distribution']['MATCHED']) || !isset($kpis['status_distribution']['NEW_BUSINESS'])) {
        $failures[] = 'status_distribution incompleto.';
    }
    $training = is_array($json['training_backlog'] ?? null) ? (array) $json['training_backlog'] : [];
    if ((int) ($training['quality_topics_total'] ?? 0) < 1) {
        $failures[] = 'training_backlog no detecto topic de llm_quality.';
    }
}
if ($exit !== 0) {
    $failures[] = 'Script salio con codigo no esperado: ' . $exit;
}

$ok = empty($failures);
echo json_encode([
    'ok' => $ok,
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($ok ? 0 : 1);
