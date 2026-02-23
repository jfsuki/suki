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

$prefixes = ['demo_', 'tmp_', 'test_', 'real20_'];
foreach ($prefixes as $prefix) {
    $deleteByGlob($projectRoot . '/contracts/entities/' . $prefix . '*.entity.json');
    $deleteByGlob($projectRoot . '/contracts/forms/' . $prefix . '*.form.json');
    $deleteByGlob($projectRoot . '/views/' . $prefix . '*.php');
}

$storagePatterns = [
    $projectRoot . '/storage/chat/profiles/default__demo_*',
    $projectRoot . '/storage/chat/profiles/default__tmp_*',
    $projectRoot . '/storage/chat/profiles/default__test_*',
    $projectRoot . '/storage/chat/profiles/default__real20_*',
    $projectRoot . '/storage/tenants/default/agent_state/default__builder__tmp_*',
    $projectRoot . '/storage/tenants/default/agent_state/default__app__tmp_*',
    $projectRoot . '/storage/tenants/default/agent_state/default__builder__demo_*',
    $projectRoot . '/storage/tenants/default/agent_state/default__app__demo_*',
    $projectRoot . '/storage/tenants/default/agent_state/default__builder__test_*',
    $projectRoot . '/storage/tenants/default/agent_state/default__app__test_*',
    $projectRoot . '/storage/tenants/default/agent_state/default__builder__real20_*',
    $projectRoot . '/storage/tenants/default/agent_state/default__app__real20_*',
    $projectRoot . '/storage/reports/chat_real_20_*.json',
];

foreach ($storagePatterns as $pattern) {
    $deleteByGlob($pattern);
}

$deleteByGlob(__DIR__ . '/tmp/*.json');

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

    foreach (['demo_%', 'tmp_%', 'test_%', 'real20_%'] as $like) {
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
            if (preg_match('/^p_[a-f0-9]{10}__(demo_|tmp_|test_|real20_)/', $table)) {
                $dropTable($pdo, $table);
            }
        }
    }

    foreach (['demo_%', 'tmp_%', 'test_%', 'real20_%'] as $like) {
        $stmt = $pdo->prepare('DELETE FROM schema_migrations WHERE id LIKE :id');
        $stmt->bindValue(':id', 'default::' . str_replace('%', '', $like) . '%');
        $stmt->execute();
    }
} catch (Throwable $e) {
    $summary['db_cleanup_error'] = $e->getMessage();
}

try {
    $registry = new ProjectRegistry();
    $registryDbPath = $projectRoot . '/storage/meta/project_registry.sqlite';
    if (is_file($registryDbPath)) {
        try {
            $rdb = new PDO('sqlite:' . $registryDbPath);
            $rdb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $patterns = ['demo_%', 'tmp_%', 'test_%', 'real20_%'];
            foreach ($patterns as $pattern) {
                $rdb->prepare('DELETE FROM chat_sessions WHERE session_id LIKE :pattern')->execute([':pattern' => $pattern]);
                $rdb->prepare('DELETE FROM users WHERE id LIKE :pattern')->execute([':pattern' => $pattern]);
                $rdb->prepare('DELETE FROM project_users WHERE user_id LIKE :pattern')->execute([':pattern' => $pattern]);
                $rdb->prepare('DELETE FROM entities WHERE entity_name LIKE :pattern')->execute([':pattern' => $pattern]);
                $rdb->prepare('DELETE FROM auth_users WHERE id LIKE :pattern')->execute([':pattern' => $pattern]);
                $rdb->prepare('DELETE FROM deploys WHERE project_id LIKE :pattern')->execute([':pattern' => $pattern]);
                $rdb->prepare('DELETE FROM projects WHERE id LIKE :pattern')->execute([':pattern' => $pattern]);
            }
        } catch (Throwable $cleanupError) {
            $summary['registry_db_cleanup_error'] = $cleanupError->getMessage();
        }
    }

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
