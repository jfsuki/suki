<?php
declare(strict_types=1);

namespace App\Core;

/**
 * AppVersionManager
 *
 * Tracks installed app versions per tenant and enforces safe migration rules.
 *
 * Rule: only ADDITIVE schema changes are allowed (ADD TABLE, ADD COLUMN).
 * MODIFY/DROP COLUMN are rejected to protect live data.
 *
 * Versions stored in project/storage/meta/app_versions/{tenantId}_{appId}.json
 */
final class AppVersionManager
{
    private string $storageDir;

    public function __construct(?string $storageDir = null)
    {
        $this->storageDir = $storageDir ?? $this->resolveStorageDir();
        if (!is_dir($this->storageDir)) {
            @mkdir($this->storageDir, 0750, true);
        }
    }

    /**
     * Register a new app installation and store its initial schema.
     * Returns the version string (e.g. "1.0.0").
     */
    public function registerApp(string $tenantId, string $appId, array $schema, array $config): string
    {
        $version = '1.0.0';
        $record  = [
            'app_id'         => $appId,
            'tenant_id'      => $tenantId,
            'version'        => $version,
            'schema_hash'    => $this->schemaHash($schema),
            'schema_snapshot'=> $schema,
            'config'         => $config,
            'installed_at'   => date('Y-m-d H:i:s'),
            'updated_at'     => date('Y-m-d H:i:s'),
            'migrations'     => [],
        ];
        $this->save($tenantId, $appId, $record);
        return $version;
    }

    public function getVersion(string $tenantId, string $appId): ?string
    {
        $record = $this->load($tenantId, $appId);
        return is_string($record['version'] ?? null) ? $record['version'] : null;
    }

    public function isInstalled(string $tenantId, string $appId): bool
    {
        return $this->getVersion($tenantId, $appId) !== null;
    }

    /**
     * Record a migration step.
     */
    public function recordMigration(string $tenantId, string $appId, string $migration, string $status): void
    {
        $record = $this->load($tenantId, $appId);
        $record['migrations'][] = [
            'migration' => $migration,
            'status'    => $status,
            'applied_at'=> date('Y-m-d H:i:s'),
        ];
        $record['updated_at'] = date('Y-m-d H:i:s');
        $this->save($tenantId, $appId, $record);
    }

    /**
     * Check if a schema update is safe (additive only).
     * Returns ['safe' => true] or ['safe' => false, 'reason' => '...', 'blocked' => [...]]
     */
    public function validateUpdate(string $tenantId, string $appId, array $newSchema): array
    {
        $current = $this->load($tenantId, $appId);
        if (empty($current)) {
            return ['safe' => true, 'reason' => 'fresh install'];
        }

        $oldEntities = $this->indexEntities($current['schema_snapshot'] ?? []);
        $newEntities = $this->indexEntities($newSchema);
        $blocked     = [];

        foreach ($oldEntities as $table => $oldCols) {
            if (!isset($newEntities[$table])) {
                $blocked[] = "DROP TABLE `{$table}` — prohibido: tabla con datos existentes";
                continue;
            }
            $oldColMap = array_column($oldCols, null, 'name');
            foreach ($oldColMap as $colName => $oldCol) {
                if (!isset(array_column($newEntities[$table], null, 'name')[$colName])) {
                    $blocked[] = "DROP COLUMN `{$table}.{$colName}` — prohibido: puede haber datos";
                }
            }
        }

        if ($blocked !== []) {
            return [
                'safe'    => false,
                'reason'  => 'Cambios destructivos detectados. Solo se permiten adiciones.',
                'blocked' => $blocked,
            ];
        }

        return ['safe' => true, 'new_tables' => array_diff(array_keys($newEntities), array_keys($oldEntities))];
    }

    /**
     * Apply a safe schema update — only ADD new tables and columns.
     */
    public function applyUpdate(string $tenantId, string $appId, array $newSchema, string $reason): string
    {
        $record          = $this->load($tenantId, $appId);
        $parts           = explode('.', $record['version'] ?? '1.0.0');
        $minor           = (int) ($parts[1] ?? 0) + 1;
        $newVersion      = "{$parts[0]}.{$minor}.0";

        $record['version']          = $newVersion;
        $record['schema_hash']      = $this->schemaHash($newSchema);
        $record['schema_snapshot']  = $newSchema;
        $record['updated_at']       = date('Y-m-d H:i:s');
        $record['migrations'][]     = [
            'migration'  => "v{$newVersion}: {$reason}",
            'status'     => 'applied',
            'applied_at' => date('Y-m-d H:i:s'),
        ];

        $this->save($tenantId, $appId, $record);
        return $newVersion;
    }

    public function getRecord(string $tenantId, string $appId): array
    {
        return $this->load($tenantId, $appId);
    }

    // ─── Private ─────────────────────────────────────────────────────────────

    private function load(string $tenantId, string $appId): array
    {
        $path = $this->path($tenantId, $appId);
        if (!file_exists($path)) {
            return [];
        }
        $raw  = file_get_contents($path);
        $data = $raw !== false ? json_decode($raw, true) : null;
        return is_array($data) ? $data : [];
    }

    private function save(string $tenantId, string $appId, array $record): void
    {
        file_put_contents(
            $this->path($tenantId, $appId),
            json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }

    private function path(string $tenantId, string $appId): string
    {
        $t = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $tenantId);
        $a = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $appId);
        return "{$this->storageDir}/{$t}_{$a}.json";
    }

    private function schemaHash(array $schema): string
    {
        return substr(md5(json_encode($schema) ?: ''), 0, 12);
    }

    private function indexEntities(array $schema): array
    {
        $result = [];
        foreach ($schema['entities'] ?? $schema as $entity) {
            if (is_array($entity) && isset($entity['table'])) {
                $result[$entity['table']] = $entity['columns'] ?? [];
            }
        }
        return $result;
    }

    private function resolveStorageDir(): string
    {
        // __DIR__ = .../framework/app/Core → dirname(,3) = repo root
        return dirname(__DIR__, 3) . '/project/storage/meta/app_versions';
    }
}
