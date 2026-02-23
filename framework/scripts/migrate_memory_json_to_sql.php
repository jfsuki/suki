<?php
// framework/scripts/migrate_memory_json_to_sql.php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\SqlMemoryRepository;

function read_json_file(string $path, array $default = []): array
{
    if (!is_file($path)) {
        return $default;
    }
    $raw = file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return $default;
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : $default;
}

function parse_scoped_key(string $filename): array
{
    $base = preg_replace('/\.json$/', '', $filename) ?? '';
    $parts = explode('__', $base, 3);
    if (count($parts) === 3) {
        return [
            'project' => $parts[0] !== '' ? $parts[0] : 'default',
            'mode' => $parts[1] !== '' ? $parts[1] : 'app',
            'user' => $parts[2] !== '' ? $parts[2] : 'anon',
        ];
    }
    return [
        'project' => 'default',
        'mode' => 'app',
        'user' => $base !== '' ? $base : 'anon',
    ];
}

$dryRun = in_array('--dry-run', $argv, true);
$projectRoot = defined('PROJECT_ROOT') ? PROJECT_ROOT : dirname(__DIR__, 2) . '/project';
$repo = new SqlMemoryRepository();

$summary = [
    'dry_run' => $dryRun,
    'profiles' => 0,
    'glossary' => 0,
    'research_queue' => 0,
    'tenant_lexicon' => 0,
    'tenant_policy' => 0,
    'tenant_training_overrides' => 0,
    'tenant_country_overrides' => 0,
    'agent_state' => 0,
    'working_memory' => 0,
];

$chatRoot = $projectRoot . '/storage/chat';
$tenantRoot = $projectRoot . '/storage/tenants';

foreach (glob($chatRoot . '/profiles/*.json') ?: [] as $path) {
    $name = basename($path, '.json');
    $parts = explode('__', $name, 2);
    if (count($parts) !== 2) {
        continue;
    }
    [$tenantId, $userKey] = $parts;
    $payload = read_json_file($path, []);
    if (empty($payload)) {
        continue;
    }
    if (!$dryRun) {
        $repo->saveUserMemory($tenantId, $userKey, 'profile', $payload);
    }
    $summary['profiles']++;
}

foreach (glob($chatRoot . '/glossary/*.json') ?: [] as $path) {
    $tenantId = basename($path, '.json');
    $payload = read_json_file($path, []);
    if (empty($payload)) {
        continue;
    }
    if (!$dryRun) {
        $repo->saveTenantMemory($tenantId, 'glossary', $payload);
    }
    $summary['glossary']++;
}

foreach (glob($chatRoot . '/research/*.json') ?: [] as $path) {
    $tenantId = basename($path, '.json');
    $payload = read_json_file($path, ['topics' => []]);
    if (empty($payload)) {
        continue;
    }
    if (!$dryRun) {
        $repo->saveTenantMemory($tenantId, 'research_queue', $payload);
    }
    $summary['research_queue']++;
}

foreach (glob($tenantRoot . '/*') ?: [] as $tenantPath) {
    if (!is_dir($tenantPath)) {
        continue;
    }
    $tenantId = basename($tenantPath);

    $lexicon = read_json_file($tenantPath . '/lexicon.json', []);
    if (!empty($lexicon)) {
        if (!$dryRun) {
            $repo->saveTenantMemory($tenantId, 'lexicon', $lexicon);
        }
        $summary['tenant_lexicon']++;
    }

    $policy = read_json_file($tenantPath . '/dialog_policy.json', []);
    if (!empty($policy)) {
        if (!$dryRun) {
            $repo->saveTenantMemory($tenantId, 'dialog_policy', $policy);
        }
        $summary['tenant_policy']++;
    }

    $training = read_json_file($tenantPath . '/training_overrides.json', []);
    if (!empty($training)) {
        if (!$dryRun) {
            $repo->saveTenantMemory($tenantId, 'training_overrides', $training);
        }
        $summary['tenant_training_overrides']++;
    }

    $country = read_json_file($tenantPath . '/country_language_overrides.json', []);
    if (!empty($country)) {
        if (!$dryRun) {
            $repo->saveTenantMemory($tenantId, 'country_language_overrides', $country);
        }
        $summary['tenant_country_overrides']++;
    }

    foreach (glob($tenantPath . '/agent_state/*.json') ?: [] as $statePath) {
        $scope = parse_scoped_key(basename($statePath));
        $payload = read_json_file($statePath, []);
        if (empty($payload)) {
            continue;
        }
        $key = 'state::' . $scope['project'] . '::' . $scope['mode'];
        if (!$dryRun) {
            $repo->saveUserMemory($tenantId, $scope['user'], $key, $payload);
        }
        $summary['agent_state']++;
    }

    foreach (glob($tenantPath . '/working_memory/*.json') ?: [] as $wmPath) {
        $scope = parse_scoped_key(basename($wmPath));
        $payload = read_json_file($wmPath, []);
        if (empty($payload)) {
            continue;
        }
        $key = 'working_memory::' . $scope['project'] . '::' . $scope['mode'];
        if (!$dryRun) {
            $repo->saveUserMemory($tenantId, $scope['user'], $key, $payload);
        }
        $summary['working_memory']++;
    }
}

echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

