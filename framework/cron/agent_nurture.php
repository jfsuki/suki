<?php
// framework/cron/agent_nurture.php
// Ejecuta auto-nutricion de agentes (1 vez/dia).

require_once __DIR__ . '/../app/autoload.php';

$tenantId = $argv[1] ?? (getenv('TENANT_KEY') ?: 'default');

$job = new \App\Jobs\AgentNurtureJob();
$result = $job->run((string) $tenantId);

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
