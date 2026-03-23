<?php
// framework/scripts/suki_metrics.php
// Live Metrics for SUKI Agent.

require_once __DIR__ . '/../app/autoload.php';

use App\Core\TelemetryService;
use App\Core\SqlMetricsRepository;

$tenantId = $argv[1] ?? 'demo';
$projectId = $argv[2] ?? 'demo';
$days = (int) ($argv[3] ?? 1);

$service = new TelemetryService(new SqlMetricsRepository());
$summary = $service->summary($tenantId, $projectId, $days);

echo "\n========================================\n";
echo "   SUKI LIVE METRICS (Last {$days} days)\n";
echo "   Tenant: {$tenantId} | Project: {$projectId}\n";
echo "========================================\n\n";

$intent = $summary['intent_metrics'];
echo "[INTENTS / CHAT]\n";
echo "- Total Messages: " . $intent['count'] . "\n";
echo "- Active Sessions: " . $intent['sessions'] . "\n";
echo "- Avg P50 Latency: " . $intent['p50_latency_ms'] . "ms\n";
echo "- Local First Rate: " . (round($intent['local_first_rate'] * 100, 2)) . "%\n";
echo "- LLM Fallback Rate: " . (round($intent['fallback_rate'] * 100, 2)) . "%\n\n";

$tokens = $summary['token_usage'];
echo "[LLM USAGE & COST]\n";
echo "- Total Tokens: " . $tokens['total_tokens'] . "\n";
echo "- Estimated Cost: $" . number_format($tokens['estimated_cost_usd'], 6) . " USD\n";
echo "- Avg Tokens/Session: " . round($tokens['avg_tokens_per_session'], 2) . "\n\n";

$guard = $summary['guardrail_events'];
echo "[SECURITY & GUARDRAILS]\n";
echo "- Guardrail Events: " . $guard['count'] . "\n\n";

echo "----------------------------------------\n";
echo "Generated at: " . date('Y-m-d H:i:s') . "\n";
echo "========================================\n";
