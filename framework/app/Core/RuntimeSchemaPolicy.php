<?php
// app/Core/RuntimeSchemaPolicy.php

namespace App\Core;

use PDO;
use RuntimeException;

final class RuntimeSchemaPolicy
{
    /** @var array<string, bool> */
    private static array $warnedModules = [];

    /**
     * @param callable():void $ensureSchema
     * @param array<int, string> $requiredTables
     * @param array<string, array<int, string>> $requiredIndexes
     * @param array<string, array<int, string>> $requiredColumns
     */
    public static function bootstrap(
        PDO $db,
        string $module,
        callable $ensureSchema,
        array $requiredTables,
        array $requiredIndexes = [],
        array $requiredColumns = [],
        ?string $migrationHint = null
    ): void {
        if (self::runtimeSchemaEnabled()) {
            self::warnRuntimeEnabled($module);
            $ensureSchema();
            return;
        }

        $issues = self::collectIssues($db, $requiredTables, $requiredIndexes, $requiredColumns);
        $missingTables = is_array($issues['missing_tables'] ?? null) ? (array) $issues['missing_tables'] : [];
        $missingIndexes = is_array($issues['missing_indexes'] ?? null) ? (array) $issues['missing_indexes'] : [];
        $missingColumns = is_array($issues['missing_columns'] ?? null) ? (array) $issues['missing_columns'] : [];

        if (empty($missingTables) && empty($missingIndexes) && empty($missingColumns)) {
            return;
        }

        $details = [];
        if (!empty($missingTables)) {
            $details[] = 'missing_tables=' . implode(',', $missingTables);
        }
        if (!empty($missingIndexes)) {
            $details[] = 'missing_indexes=' . implode(',', $missingIndexes);
        }
        if (!empty($missingColumns)) {
            $details[] = 'missing_columns=' . implode(',', $missingColumns);
        }

        $driver = (string) $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $hint = trim((string) $migrationHint);
        if ($hint === '') {
            $hint = 'db/migrations/' . $driver . '/';
        }

        throw new RuntimeException(
            $module
            . ': runtime schema changes are disabled. '
            . implode(' | ', $details)
            . '. Aplica migraciones formales en '
            . $hint
            . '. Habilita ALLOW_RUNTIME_SCHEMA=1 solo en local dev.'
        );
    }

    public static function runtimeSchemaEnabled(): bool
    {
        if ((string) (getenv('ALLOW_RUNTIME_SCHEMA') ?: '0') !== '1') {
            return false;
        }
        if (self::isProductionEnvironment()) {
            return false;
        }
        return self::isLocalEnvironment();
    }

    /**
     * @param array<int, string> $requiredTables
     * @param array<string, array<int, string>> $requiredIndexes
     * @param array<string, array<int, string>> $requiredColumns
     * @return array{missing_tables: array<int, string>, missing_indexes: array<int, string>, missing_columns: array<int, string>}
     */
    public static function collectIssues(PDO $db, array $requiredTables, array $requiredIndexes = [], array $requiredColumns = []): array
    {
        $missingTables = [];
        foreach ($requiredTables as $table) {
            if (!self::tableExists($db, $table)) {
                $missingTables[] = $table;
            }
        }

        $missingIndexes = [];
        foreach ($requiredIndexes as $table => $indexes) {
            if (!self::tableExists($db, (string) $table)) {
                continue;
            }
            foreach ($indexes as $indexName) {
                if (!self::indexExists($db, (string) $table, (string) $indexName)) {
                    $missingIndexes[] = (string) $table . '.' . (string) $indexName;
                }
            }
        }

        $missingColumns = [];
        foreach ($requiredColumns as $table => $columns) {
            if (!self::tableExists($db, (string) $table)) {
                continue;
            }
            foreach ($columns as $columnName) {
                if (!self::columnExists($db, (string) $table, (string) $columnName)) {
                    $missingColumns[] = (string) $table . '.' . (string) $columnName;
                }
            }
        }

        return [
            'missing_tables' => $missingTables,
            'missing_indexes' => $missingIndexes,
            'missing_columns' => $missingColumns,
        ];
    }

    private static function warnRuntimeEnabled(string $module): void
    {
        if (isset(self::$warnedModules[$module])) {
            return;
        }
        self::$warnedModules[$module] = true;
        error_log(
            '[RuntimeSchemaPolicy] '
            . $module
            . ' runtime schema enabled (APP_ENV='
            . (getenv('APP_ENV') ?: '')
            . ', ALLOW_RUNTIME_SCHEMA=1).'
        );
    }

    private static function isProductionEnvironment(): bool
    {
        $appEnv = strtolower(trim((string) (getenv('APP_ENV') ?: getenv('SUKI_ENV') ?: '')));
        return in_array($appEnv, ['production', 'prod'], true);
    }

    private static function isLocalEnvironment(): bool
    {
        $appEnv = strtolower(trim((string) (getenv('APP_ENV') ?: getenv('SUKI_ENV') ?: '')));
        if (in_array($appEnv, ['local', 'development', 'dev', 'testing', 'test'], true)) {
            return true;
        }

        $appUrl = trim((string) (getenv('APP_URL') ?: ''));
        if ($appUrl !== '') {
            $host = strtolower((string) parse_url($appUrl, PHP_URL_HOST));
            if (in_array($host, ['localhost', '127.0.0.1', '::1'], true) || str_ends_with($host, '.local')) {
                return true;
            }
        }

        return false;
    }

    private static function tableExists(PDO $db, string $table): bool
    {
        $table = trim($table);
        if ($table === '') {
            return false;
        }
        $driver = (string) $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'mysql') {
            $stmt = $db->prepare(
                'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table_name'
            );
            $stmt->execute([':table_name' => $table]);
            return (int) $stmt->fetchColumn() > 0;
        }

        $stmt = $db->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name = :table_name LIMIT 1");
        $stmt->execute([':table_name' => $table]);
        $value = $stmt->fetchColumn();
        return is_string($value) && $value !== '';
    }

    private static function indexExists(PDO $db, string $table, string $index): bool
    {
        $table = trim($table);
        $index = trim($index);
        if ($table === '' || $index === '') {
            return false;
        }
        $driver = (string) $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'mysql') {
            $stmt = $db->prepare(
                'SELECT COUNT(*) FROM information_schema.statistics
                 WHERE table_schema = DATABASE() AND table_name = :table_name AND index_name = :index_name'
            );
            $stmt->execute([
                ':table_name' => $table,
                ':index_name' => $index,
            ]);
            return (int) $stmt->fetchColumn() > 0;
        }

        $safeTable = preg_replace('/[^a-zA-Z0-9_]/', '', $table) ?? '';
        if ($safeTable === '') {
            return false;
        }
        $stmt = $db->query("PRAGMA index_list({$safeTable})");
        if (!$stmt) {
            return false;
        }
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if ((string) ($row['name'] ?? '') === $index) {
                return true;
            }
        }
        return false;
    }

    private static function columnExists(PDO $db, string $table, string $column): bool
    {
        $table = trim($table);
        $column = trim($column);
        if ($table === '' || $column === '') {
            return false;
        }
        $driver = (string) $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'mysql') {
            $stmt = $db->prepare(
                'SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = :table_name AND column_name = :column_name'
            );
            $stmt->execute([
                ':table_name' => $table,
                ':column_name' => $column,
            ]);
            return (int) $stmt->fetchColumn() > 0;
        }

        $safeTable = preg_replace('/[^a-zA-Z0-9_]/', '', $table) ?? '';
        if ($safeTable === '') {
            return false;
        }
        $stmt = $db->query('PRAGMA table_info(' . $safeTable . ')');
        $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        foreach ($rows as $row) {
            if ((string) ($row['name'] ?? '') === $column) {
                return true;
            }
        }
        return false;
    }
}
