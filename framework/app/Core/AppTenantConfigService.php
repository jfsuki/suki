<?php
declare(strict_types=1);

// app/Core/AppTenantConfigService.php

namespace App\Core;

/**
 * AppTenantConfigService
 *
 * Persiste y carga la configuración específica de una app por tenant.
 * Cada campo de configuración (NIT, razón social, campos específicos de la app)
 * se guarda individualmente para permitir actualización incremental.
 *
 * Tabla: app_tenant_config (MySQL)
 *   tenant_id + app_id + field_key = unique
 */
final class AppTenantConfigService
{
    private \PDO $pdo;

    public function __construct(?\PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::connection();
    }

    /**
     * Load all configured fields for a tenant+app as a flat key→value map.
     */
    public function loadAll(string $tenantId, string $appId): array
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT field_key, field_value FROM app_tenant_config WHERE tenant_id=? AND app_id=?'
            );
            $stmt->execute([$tenantId, $appId]);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            $result = [];
            foreach ($rows as $row) {
                $result[(string) $row['field_key']] = (string) ($row['field_value'] ?? '');
            }
            return $result;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Save or update a single configuration field.
     */
    public function saveField(string $tenantId, string $appId, string $fieldKey, string $value): void
    {
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO app_tenant_config (tenant_id, app_id, field_key, field_value, configured_at)
                 VALUES (?, ?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE field_value=VALUES(field_value), configured_at=NOW()'
            );
            $stmt->execute([$tenantId, $appId, $fieldKey, $value]);
        } catch (\Throwable) {
            // Non-blocking — config save failure does not block the conversation
        }
    }

    /**
     * Mark the app configuration as complete for this tenant.
     */
    public function markComplete(string $tenantId, string $appId): void
    {
        $this->saveField($tenantId, $appId, '__config_complete__', '1');
    }

    /**
     * Check if config is explicitly marked complete.
     */
    public function isComplete(string $tenantId, string $appId): bool
    {
        $all = $this->loadAll($tenantId, $appId);
        return ($all['__config_complete__'] ?? '') === '1';
    }
}
