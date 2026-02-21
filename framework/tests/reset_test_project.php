<?php
// framework/tests/reset_test_project.php
// Limpia artefactos de pruebas sin tocar contratos base del framework.

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/app/autoload.php';

use App\Core\ContractsCatalog;
use App\Core\Database;
use App\Core\ProjectRegistry;

$projectRoot = realpath(dirname(__DIR__, 2) . '/project') ?: dirname(__DIR__, 2) . '/project';

$summary = [
    'project_root' => $projectRoot,
    'deleted_files' => [],
    'dropped_tables' => [],
    'synced_project_id' => null,
    'remaining_entities' => [],
];

$deleteByGlob = static function (string $pattern) use (&$summary): void {
    foreach (glob($pattern) ?: [] as $path) {
        if (is_file($path) && @unlink($path)) {
            $summary['deleted_files'][] = $path;
        }
    }
};

$prefixes = ['demo_', 'golden_', 'acid_', 'tmp_', 'test_'];
foreach ($prefixes as $prefix) {
    $deleteByGlob($projectRoot . '/contracts/entities/' . $prefix . '*.entity.json');
    $deleteByGlob($projectRoot . '/contracts/forms/' . $prefix . '*.form.json');
    $deleteByGlob($projectRoot . '/views/' . $prefix . '*.php');
}

$storagePatterns = [
    $projectRoot . '/storage/chat/profiles/default__acid_*',
    $projectRoot . '/storage/chat/profiles/default__demo_*',
    $projectRoot . '/storage/chat/profiles/default__golden_*',
    $projectRoot . '/storage/chat/profiles/default__u_test_*',
    $projectRoot . '/storage/chat/profiles/default__u_manual_*',
    $projectRoot . '/storage/chat/profiles/default__u_builder_*',
    $projectRoot . '/storage/chat/profiles/default__u_reg*',
    $projectRoot . '/storage/chat/profiles/default__default__*',
    $projectRoot . '/storage/chat/profiles/default__suki_erp__*',
    $projectRoot . '/storage/chat/profiles/default__builder_demo.json',
    $projectRoot . '/storage/tenants/default/agent_state/default__app__acid_*',
    $projectRoot . '/storage/tenants/default/agent_state/default__builder__acid_*',
    $projectRoot . '/storage/tenants/default/agent_state/default__builder__demo_*',
    $projectRoot . '/storage/tenants/default/agent_state/default__app__demo_*',
    $projectRoot . '/storage/tenants/default/agent_state/golden_proj__*',
    $projectRoot . '/storage/tenants/default/agent_state/suki_erp__app__golden_*',
    $projectRoot . '/storage/tenants/default/agent_state/suki_erp__builder__golden_*',
    $projectRoot . '/storage/tenants/default/agent_state/suki_erp__app__u_*',
    $projectRoot . '/storage/tenants/default/agent_state/suki_erp__builder__u_*',
    $projectRoot . '/storage/tenants/default/agent_state/suki_erp__app__user_demo.json',
    $projectRoot . '/storage/tenants/default/agent_state/suki_erp__builder__builder_demo.json',
    $projectRoot . '/storage/reports/chat_acid_*.json',
];

foreach ($storagePatterns as $pattern) {
    $deleteByGlob($pattern);
}

try {
    $pdo = Database::connection();
    $dropTable = static function (PDO $pdo, string $table) use (&$summary): void {
        $safe = preg_replace('/[^a-zA-Z0-9_]/', '', $table) ?: '';
        if ($safe === '') {
            return;
        }
        $pdo->exec('DROP TABLE IF EXISTS `' . $safe . '`');
        $summary['dropped_tables'][] = $safe;
    };

    foreach (['demo_%', 'golden_%', 'acid_%', 'tmp_%', 'test_%'] as $like) {
        $stmt = $pdo->query("SHOW TABLES LIKE '{$like}'");
        if (!$stmt) {
            continue;
        }
        $tables = $stmt->fetchAll(PDO::FETCH_NUM);
        foreach ($tables as $row) {
            $table = (string) ($row[0] ?? '');
            if ($table === '') {
                continue;
            }
            $dropTable($pdo, $table);
        }
    }

    $stmt = $pdo->query('SHOW TABLES');
    if ($stmt) {
        $tables = $stmt->fetchAll(PDO::FETCH_NUM);
        foreach ($tables as $row) {
            $table = (string) ($row[0] ?? '');
            if ($table === '') {
                continue;
            }
            if (preg_match('/^p_[a-f0-9]{10}__(demo_|golden_|acid_|tmp_|test_)/', $table)) {
                $dropTable($pdo, $table);
            }
        }
    }

    foreach (['demo_%', 'golden_%', 'acid_%', 'tmp_%', 'test_%'] as $like) {
        $stmt = $pdo->prepare('DELETE FROM schema_migrations WHERE id LIKE :id');
        $stmt->bindValue(':id', 'default::' . str_replace('%', '', $like) . '%');
        $stmt->execute();
    }
} catch (Throwable $e) {
    $summary['db_cleanup_error'] = $e->getMessage();
}

try {
    $registry = new ProjectRegistry();
    $manifest = $registry->resolveProjectFromManifest();
    $projectId = (string) ($manifest['id'] ?? 'default');
    $catalog = new ContractsCatalog($projectRoot);
    $entities = array_map(
        static fn(string $path): string => basename($path, '.entity.json'),
        $catalog->entities()
    );
    $registry->syncEntitiesFromContracts($projectId, $entities, 'contracts');
    $summary['synced_project_id'] = $projectId;
    $summary['remaining_entities'] = $entities;
} catch (Throwable $e) {
    $summary['registry_sync_error'] = $e->getMessage();
}

$summary['deleted_files_count'] = count($summary['deleted_files']);
$summary['dropped_tables_count'] = count($summary['dropped_tables']);

echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
