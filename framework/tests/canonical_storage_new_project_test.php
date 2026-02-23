<?php
// framework/tests/canonical_storage_new_project_test.php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\ProjectRegistry;
use App\Core\StorageModel;
use App\Core\TableNamespace;

$tmpDir = __DIR__ . '/tmp';
if (!is_dir($tmpDir)) {
    mkdir($tmpDir, 0775, true);
}
$registryPath = $tmpDir . '/canonical_storage_registry.sqlite';
if (is_file($registryPath)) {
    unlink($registryPath);
}

putenv('PROJECT_REGISTRY_DB_PATH=' . $registryPath);
putenv('DB_CANONICAL_NEW_PROJECTS=1');
putenv('DB_NAMESPACE_BY_PROJECT=1');

StorageModel::clearCache();
TableNamespace::clearCache();

$registry = new ProjectRegistry($registryPath);
$registry->ensureProject('p2_can_app', 'Proyecto Canonico', 'draft', 'shared', 'owner_can');
$registry->ensureProject('p2_legacy_app', 'Proyecto Legacy', 'draft', 'shared', 'owner_legacy', 'legacy');

$canonical = $registry->getProject('p2_can_app') ?? [];
$legacy = $registry->getProject('p2_legacy_app') ?? [];

StorageModel::clearCache();
TableNamespace::clearCache();
$canonicalTable = TableNamespace::resolve('clientes', 'p2_can_app');
$canonicalKey = TableNamespace::migrationKey('clientes', 'p2_can_app');
$legacyTable = TableNamespace::resolve('clientes', 'p2_legacy_app');
$legacyKey = TableNamespace::migrationKey('clientes', 'p2_legacy_app');

$failures = [];
if ((string) ($canonical['storage_model'] ?? '') !== 'canonical') {
    $failures[] = 'new project should default to canonical when DB_CANONICAL_NEW_PROJECTS=1';
}
if ((string) ($legacy['storage_model'] ?? '') !== 'legacy') {
    $failures[] = 'explicit legacy storage model was not persisted';
}
if ($canonicalTable !== 'clientes') {
    $failures[] = 'canonical project should not use table namespace prefix';
}
if (!str_starts_with($canonicalKey, 'canonical::p2_can_app::')) {
    $failures[] = 'canonical migration key format mismatch';
}
if (!str_starts_with($legacyTable, 'p_') || !str_contains($legacyTable, '__clientes')) {
    $failures[] = 'legacy project should keep namespace table prefix';
}
if ($legacyKey === 'clientes' || str_starts_with($legacyKey, 'canonical::')) {
    $failures[] = 'legacy migration key should remain project namespaced';
}

$ok = empty($failures);
echo json_encode([
    'ok' => $ok,
    'registry_path' => $registryPath,
    'canonical' => $canonical,
    'legacy' => $legacy,
    'resolved' => [
        'canonical_table' => $canonicalTable,
        'canonical_key' => $canonicalKey,
        'legacy_table' => $legacyTable,
        'legacy_key' => $legacyKey,
    ],
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($ok ? 0 : 1);
