<?php
declare(strict_types=1);

// framework/scripts/unknown_business_quality_report.php
// Reporte diario de calidad para unknown-business LLM + KPIs de preproduccion.

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\SqlMemoryRepository;
use App\Core\TelemetryService;

$opts = parseArgs($argv);
$tenantId = trim((string) ($opts['tenant'] ?? 'default'));
$projectId = trim((string) ($opts['project'] ?? 'staging_llm_smoke'));
$days = max(1, (int) ($opts['days'] ?? 1));
$minSamples = max(1, (int) ($opts['min_samples'] ?? 10));
$minQualityOkRate = max(0.0, min(1.0, (float) ($opts['min_quality_ok_rate'] ?? 0.9)));
$minAvgScore = max(0.0, min(1.0, (float) ($opts['min_avg_score'] ?? 0.85)));
$maxMissingScopeRate = max(0.0, min(1.0, (float) ($opts['max_missing_scope_rate'] ?? 0.2)));

$defaultOutput = dirname(__DIR__) . '/tests/tmp/unknown_business_quality_daily_report.json';
$outputPath = trim((string) ($opts['output'] ?? $defaultOutput));

$memory = new SqlMemoryRepository();
$telemetry = new TelemetryService();

$samplesBucket = $memory->getTenantMemory($tenantId, 'unknown_business_llm_samples', ['items' => []]);
$samples = is_array($samplesBucket['items'] ?? null) ? array_values((array) $samplesBucket['items']) : [];
$sinceTs = time() - ($days * 86400);

$windowSamples = [];
foreach ($samples as $item) {
    if (!is_array($item)) {
        continue;
    }
    $ts = strtotime((string) ($item['at'] ?? ''));
    if ($ts === false || $ts < $sinceTs) {
        continue;
    }
    $windowSamples[] = $item;
}

$total = count($windowSamples);
$qualityOkCount = 0;
$qualityScoreSum = 0.0;
$statusCounts = [];
$providerCounts = [];
$missingScopeCount = 0;
$topCandidates = [];
$lowQualityIssues = [];

foreach ($windowSamples as $item) {
    $qualityOk = (bool) ($item['quality_ok'] ?? false);
    if ($qualityOk) {
        $qualityOkCount++;
    }
    $qualityScore = (float) ($item['quality_score'] ?? 0.0);
    $qualityScoreSum += $qualityScore;

    $status = strtoupper(trim((string) ($item['status'] ?? 'UNKNOWN')));
    if ($status === '') {
        $status = 'UNKNOWN';
    }
    $statusCounts[$status] = (int) ($statusCounts[$status] ?? 0) + 1;

    $provider = strtolower(trim((string) ($item['provider_used'] ?? 'unknown')));
    if ($provider === '') {
        $provider = 'unknown';
    }
    $providerCounts[$provider] = (int) ($providerCounts[$provider] ?? 0) + 1;

    $needs = is_array($item['needs_normalized'] ?? null) ? (array) $item['needs_normalized'] : [];
    $docs = is_array($item['documents_normalized'] ?? null) ? (array) $item['documents_normalized'] : [];
    if (count($needs) === 0 || count($docs) === 0) {
        $missingScopeCount++;
    }

    $candidate = strtolower(trim((string) ($item['candidate'] ?? '')));
    if ($candidate !== '') {
        $topCandidates[$candidate] = (int) ($topCandidates[$candidate] ?? 0) + 1;
    }

    $issues = is_array($item['quality_issues'] ?? null) ? (array) $item['quality_issues'] : [];
    foreach ($issues as $issue) {
        $key = strtolower(trim((string) $issue));
        if ($key === '') {
            continue;
        }
        $lowQualityIssues[$key] = (int) ($lowQualityIssues[$key] ?? 0) + 1;
    }
}

arsort($statusCounts);
arsort($providerCounts);
arsort($topCandidates);
arsort($lowQualityIssues);

$qualityOkRate = $total > 0 ? round($qualityOkCount / $total, 4) : 0.0;
$avgQualityScore = $total > 0 ? round($qualityScoreSum / $total, 4) : 0.0;
$missingScopeRate = $total > 0 ? round($missingScopeCount / $total, 4) : 0.0;

$queue = $memory->getTenantMemory($tenantId, 'research_queue', ['topics' => []]);
$topics = is_array($queue['topics'] ?? null) ? (array) $queue['topics'] : [];
$qualityTopics = [];
foreach ($topics as $topic) {
    if (!is_array($topic)) {
        continue;
    }
    $name = (string) ($topic['topic'] ?? '');
    if (!str_ends_with(strtolower($name), ':llm_quality')) {
        continue;
    }
    $qualityTopics[] = [
        'topic' => $name,
        'count' => (int) ($topic['count'] ?? 0),
        'status' => (string) ($topic['status'] ?? 'pending_research'),
        'last_seen' => (string) ($topic['last_seen'] ?? ''),
    ];
}
usort(
    $qualityTopics,
    static fn(array $a, array $b): int => ((int) ($b['count'] ?? 0)) <=> ((int) ($a['count'] ?? 0))
);

$tokenSummary = $telemetry->summary($tenantId, $projectId, $days);
$tokenUsage = is_array($tokenSummary['token_usage'] ?? null) ? (array) $tokenSummary['token_usage'] : [];

$gates = [
    'min_samples' => $total >= $minSamples,
    'quality_ok_rate' => $qualityOkRate >= $minQualityOkRate,
    'avg_quality_score' => $avgQualityScore >= $minAvgScore,
    'missing_scope_rate' => $missingScopeRate <= $maxMissingScopeRate,
];
$ok = !in_array(false, $gates, true);

$report = [
    'ok' => $ok,
    'generated_at' => date('c'),
    'scope' => [
        'tenant_id' => $tenantId,
        'project_id' => $projectId,
        'days' => $days,
    ],
    'thresholds' => [
        'min_samples' => $minSamples,
        'min_quality_ok_rate' => $minQualityOkRate,
        'min_avg_score' => $minAvgScore,
        'max_missing_scope_rate' => $maxMissingScopeRate,
    ],
    'kpis' => [
        'samples_total' => $total,
        'quality_ok_count' => $qualityOkCount,
        'quality_ok_rate' => $qualityOkRate,
        'avg_quality_score' => $avgQualityScore,
        'missing_scope_count' => $missingScopeCount,
        'missing_scope_rate' => $missingScopeRate,
        'status_distribution' => $statusCounts,
        'provider_distribution' => $providerCounts,
        'top_candidates' => array_slice($topCandidates, 0, 15, true),
        'top_quality_issues' => array_slice($lowQualityIssues, 0, 15, true),
    ],
    'training_backlog' => [
        'quality_topics_total' => count($qualityTopics),
        'quality_topics_top' => array_slice($qualityTopics, 0, 20),
    ],
    'tokens_cost' => [
        'summary' => $tokenUsage,
    ],
    'gates' => $gates,
];

$dir = dirname($outputPath);
if (!is_dir($dir)) {
    mkdir($dir, 0775, true);
}
file_put_contents($outputPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
$report['report'] = $outputPath;

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 1);

/**
 * @return array<string, string>
 */
function parseArgs(array $argv): array
{
    $parsed = [];
    foreach (array_slice($argv, 1) as $arg) {
        $arg = trim((string) $arg);
        if (!str_starts_with($arg, '--')) {
            continue;
        }
        $arg = substr($arg, 2);
        $eqPos = strpos($arg, '=');
        if ($eqPos === false) {
            $parsed[$arg] = '1';
            continue;
        }
        $key = trim(substr($arg, 0, $eqPos));
        $value = trim(substr($arg, $eqPos + 1));
        if ($key !== '') {
            $parsed[$key] = $value;
        }
    }
    return $parsed;
}
