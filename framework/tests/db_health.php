<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/autoload.php';

use App\Core\Database;
use App\Core\TableNamespace;

function timer(callable $fn): array
{
    $start = microtime(true);
    $result = $fn();
    $elapsedMs = (microtime(true) - $start) * 1000;
    return [$result, round($elapsedMs, 2)];
}

try {
    [$db, $connectMs] = timer(static fn() => Database::connection());
    $driver = (string) $db->getAttribute(PDO::ATTR_DRIVER_NAME);
    $projectId = TableNamespace::normalizedProjectId();
    $prefix = TableNamespace::projectPrefix();

    $out = [
        'ok' => true,
        'driver' => $driver,
        'project_id' => $projectId,
        'namespace_prefix' => $prefix,
        'connect_ms' => $connectMs,
        'checks' => [],
        'warnings' => [],
        'errors' => [],
        'generated_at' => date('c'),
    ];

    [$pong, $pingMs] = timer(static fn() => $db->query('SELECT 1')->fetchColumn());
    $out['checks']['ping'] = [
        'ok' => ((int) $pong) === 1,
        'latency_ms' => $pingMs,
    ];

    if ($driver !== 'mysql') {
        $out['warnings'][] = 'db_health completo disponible solo para MySQL.';
        echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit(0);
    }

    $totalTables = (int) $db->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchColumn();
    $nsStmt = $db->prepare(
        'SELECT table_name
         FROM information_schema.tables
         WHERE table_schema = DATABASE()
           AND table_name LIKE :prefix
         ORDER BY table_name'
    );
    $nsStmt->bindValue(':prefix', $prefix . '%');
    $nsStmt->execute();
    $namespacedTables = $nsStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

    $out['checks']['tables'] = [
        'total' => $totalTables,
        'namespaced_total' => count($namespacedTables),
    ];

    $idxStmt = $db->prepare(
        'SELECT column_name
         FROM information_schema.statistics
         WHERE table_schema = DATABASE()
           AND table_name = :table
         GROUP BY column_name'
    );

    $missingTenantIndex = [];
    $missingCreatedAtIndex = [];

    foreach ($namespacedTables as $tableName) {
        $idxStmt->bindValue(':table', $tableName);
        $idxStmt->execute();
        $indexedCols = $idxStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $indexedMap = array_fill_keys(array_map('strtolower', $indexedCols), true);

        $colStmt = $db->prepare(
            'SELECT column_name
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = :table'
        );
        $colStmt->bindValue(':table', $tableName);
        $colStmt->execute();
        $columns = array_map('strtolower', $colStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
        $columnMap = array_fill_keys($columns, true);

        if (isset($columnMap['tenant_id']) && !isset($indexedMap['tenant_id'])) {
            $missingTenantIndex[] = $tableName;
        }
        if (isset($columnMap['created_at']) && !isset($indexedMap['created_at'])) {
            $missingCreatedAtIndex[] = $tableName;
        }
    }

    $out['checks']['index_health'] = [
        'missing_tenant_id_index' => $missingTenantIndex,
        'missing_created_at_index' => $missingCreatedAtIndex,
    ];

    if (!empty($missingTenantIndex)) {
        $out['warnings'][] = 'Hay tablas sin indice tenant_id.';
    }
    if (!empty($missingCreatedAtIndex)) {
        $out['warnings'][] = 'Hay tablas sin indice created_at.';
    }

    $tableLimit = (int) (getenv('DB_MAX_TABLES_PER_PROJECT') ?: 0);
    if ($tableLimit > 0 && count($namespacedTables) >= $tableLimit) {
        $out['warnings'][] = "Proyecto en limite de tablas ({$tableLimit}).";
    }

    $auditLatency = null;
    try {
        [, $auditLatency] = timer(static fn() => $db->query('SELECT COUNT(*) FROM audit_log')->fetchColumn());
    } catch (Throwable $e) {
        $out['warnings'][] = 'No se pudo medir audit_log: ' . $e->getMessage();
    }
    $out['checks']['audit_log_count_latency_ms'] = $auditLatency;

    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $e) {
    $out = [
        'ok' => false,
        'error' => $e->getMessage(),
        'generated_at' => date('c'),
    ];
    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(1);
}
