<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * AppDataService
 *
 * Fetches data from app-creator tables (canonical MySQL, app_{appId}__{entity}).
 * Forces CANONICAL storage model during BaseRepository construction so the
 * resolved table name is correct, then restores original mode.
 *
 * Used by: AppReportSkill, reports/app API route.
 */
final class AppDataService
{
    private AppMemoryService  $memory;
    private AppSchemaDesigner $designer;

    public function __construct(
        ?AppMemoryService  $memory   = null,
        ?AppSchemaDesigner $designer = null
    ) {
        $this->memory   = $memory   ?? new AppMemoryService();
        $this->designer = $designer ?? new AppSchemaDesigner();
    }

    /**
     * Returns rows from an app entity table with optional filters.
     */
    public function listRecords(
        string $tenantId,
        string $appId,
        string $entityName,
        array  $filters = [],
        int    $limit   = 100,
        int    $offset  = 0
    ): array {
        $repo = $this->buildRepo($tenantId, $appId, $entityName);
        $rows = $repo->list($filters, $limit, $offset);

        $formulas = $this->getEntityFormulas($tenantId, $appId, $entityName);
        if (!empty($formulas)) {
            $engine = new FormulaEngine();
            $rows   = array_map(fn($row) => $engine->applyFormulas($formulas, $row), $rows);
        }

        return $rows;
    }

    /**
     * Returns formula definitions for an entity (empty array if none declared).
     */
    public function getEntityFormulas(string $tenantId, string $appId, string $entityName): array
    {
        $mem = $this->memory->load($tenantId, $appId);
        foreach ($mem['schema']['entities'] ?? [] as $entity) {
            if (($entity['table'] ?? '') === $entityName) {
                $formulas = $entity['formulas'] ?? [];
                return is_array($formulas) ? $formulas : [];
            }
        }
        return [];
    }

    /**
     * Returns column definitions (name, label, type) for an entity — skips system columns.
     */
    public function getColumns(string $tenantId, string $appId, string $entityName): array
    {
        $def  = $this->resolveEntityDef($tenantId, $appId, $entityName);
        $skip = ['id', 'tenant_id', 'created_at', 'updated_at', 'deleted_at',
                 'created_by_user_id', 'updated_by_user_id', 'deleted_by_user_id'];
        $cols = [];
        foreach ($def['fields'] ?? [] as $field) {
            $name = (string) ($field['name'] ?? '');
            if ($name === '' || in_array($name, $skip, true)) {
                continue;
            }
            $cols[] = [
                'name'     => $name,
                'label'    => (string) ($field['label'] ?? ucwords(str_replace('_', ' ', $name))),
                'type'     => (string) ($field['type'] ?? 'string'),
                'computed' => false,
            ];
        }

        // Append computed/formula columns so they appear in reports
        foreach ($this->getEntityFormulas($tenantId, $appId, $entityName) as $formula) {
            $name = (string) ($formula['name'] ?? '');
            if ($name === '') { continue; }
            $cols[] = [
                'name'     => $name,
                'label'    => (string) ($formula['label'] ?? ucwords(str_replace('_', ' ', $name))),
                'type'     => (string) ($formula['type'] ?? 'decimal'),
                'computed' => true,
            ];
        }

        return $cols;
    }

    /**
     * Inserts a new record into an app entity table.
     * Returns the new row ID.
     */
    public function insertRecord(
        string $tenantId,
        string $appId,
        string $entityName,
        array  $data
    ): int {
        $repo = $this->buildRepo($tenantId, $appId, $entityName);
        return $repo->create($data);
    }

    /**
     * Updates an existing record. Returns number of affected rows.
     */
    public function updateRecord(
        string $tenantId,
        string $appId,
        string $entityName,
        int    $id,
        array  $data
    ): int {
        $repo = $this->buildRepo($tenantId, $appId, $entityName);
        return $repo->update($id, $data);
    }

    /**
     * Soft-deletes (or hard-deletes if entity has no deleted_at) a record.
     * Returns number of affected rows.
     */
    public function deleteRecord(
        string $tenantId,
        string $appId,
        string $entityName,
        int    $id
    ): int {
        $repo = $this->buildRepo($tenantId, $appId, $entityName);
        return $repo->delete($id);
    }

    /**
     * Finds a single record by primary key. Returns null if not found.
     */
    public function findRecord(
        string $tenantId,
        string $appId,
        string $entityName,
        int    $id
    ): ?array {
        $repo = $this->buildRepo($tenantId, $appId, $entityName);
        return $repo->find($id);
    }

    /**
     * Lists all entity names (table + label) declared in an app's schema.
     */
    public function getEntityNames(string $tenantId, string $appId): array
    {
        $mem   = $this->memory->load($tenantId, $appId);
        $names = [];
        foreach ($mem['schema']['entities'] ?? [] as $entity) {
            $table = (string) ($entity['table'] ?? '');
            if ($table === '' || $table === 'audit_log') {
                continue;
            }
            $names[] = [
                'table' => $table,
                'label' => (string) ($entity['label'] ?? $table),
            ];
        }
        return $names;
    }

    /**
     * Builds a BaseRepository for the given app entity.
     * The repository's $this->table is resolved under CANONICAL mode so it
     * targets app_{appId}__{entity} — shared across all tenants.
     */
    private function buildRepo(string $tenantId, string $appId, string $entityName): BaseRepository
    {
        $def       = $this->resolveEntityDef($tenantId, $appId, $entityName);
        $tenantInt = $this->parseTenantId($tenantId);

        $prev = getenv('PROJECT_STORAGE_MODEL') ?: '';
        try {
            putenv('PROJECT_STORAGE_MODEL=canonical');
            TableNamespace::clearCache();
            return new BaseRepository($def, null, $tenantInt);
        } finally {
            putenv("PROJECT_STORAGE_MODEL={$prev}");
            TableNamespace::clearCache();
        }
    }

    private function resolveEntityDef(string $tenantId, string $appId, string $entityName): array
    {
        $mem = $this->memory->load($tenantId, $appId);
        if (empty($mem)) {
            throw new RuntimeException("App '{$appId}' no encontrada para el tenant '{$tenantId}'.");
        }

        $defs = $this->designer->toEntityMigratorFormat($mem['schema'] ?? [], $appId);

        $prefix   = 'app_' . preg_replace('/[^a-z0-9_]/', '_', strtolower($appId)) . '__';
        $physical = $prefix . preg_replace('/[^a-z0-9_]/', '_', strtolower($entityName));

        foreach ($defs as $def) {
            if (($def['table']['name'] ?? '') === $physical) {
                $def['type'] = 'entity';
                return $def;
            }
        }

        throw new RuntimeException("Entidad '{$entityName}' no encontrada en app '{$appId}'.");
    }

    private function parseTenantId(string $tenantId): ?int
    {
        if ($tenantId === '') {
            return null;
        }
        if (is_numeric($tenantId)) {
            return (int) $tenantId;
        }
        $hash     = crc32($tenantId);
        $unsigned = (int) sprintf('%u', $hash);
        $value    = $unsigned % 2147483647;
        return $value > 0 ? $value : 1;
    }
}
