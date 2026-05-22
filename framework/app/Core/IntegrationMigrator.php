<?php
// app/Core/IntegrationMigrator.php

namespace App\Core;

use PDO;

final class IntegrationMigrator
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
    }

    public function bootstrapSchemaPolicy(): void
    {
        RuntimeSchemaPolicy::bootstrap(
            $this->db,
            'IntegrationMigrator',
            fn() => $this->ensureTables(),
            $this->requiredTables(),
            [],
            [],
            'db/migrations/' . $this->driver() . '/20260303_004_runtime_infra_schema.sql'
        );
    }

    public function ensureTables(): void
    {
        $driver = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $jsonType = $driver === 'sqlite' ? 'TEXT' : 'JSON';
        $idType = $driver === 'sqlite' ? 'INTEGER' : 'INT';
        $auto = $driver === 'sqlite' ? 'AUTOINCREMENT' : 'AUTO_INCREMENT';

        $this->db->exec("CREATE TABLE IF NOT EXISTS integration_connections (
            id {$idType} PRIMARY KEY {$auto},
            tenant_id VARCHAR(64) NOT NULL DEFAULT '',
            integration_id VARCHAR(64) NOT NULL,
            provider VARCHAR(64) NOT NULL,
            type VARCHAR(32) NOT NULL,
            country VARCHAR(8) NOT NULL,
            environment VARCHAR(16) NOT NULL,
            base_url VARCHAR(255) NOT NULL,
            token_env VARCHAR(128) NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL
        )");

        $this->db->exec("CREATE TABLE IF NOT EXISTS integration_tokens (
            id {$idType} PRIMARY KEY {$auto},
            tenant_id VARCHAR(64) NOT NULL DEFAULT '',
            integration_id VARCHAR(64) NOT NULL,
            token_env VARCHAR(128) NULL,
            expires_at DATETIME NULL,
            created_at DATETIME NOT NULL
        )");

        $this->db->exec("CREATE TABLE IF NOT EXISTS integration_documents (
            id {$idType} PRIMARY KEY {$auto},
            tenant_id VARCHAR(64) NOT NULL DEFAULT '',
            integration_id VARCHAR(64) NOT NULL,
            entity VARCHAR(64) NULL,
            record_id VARCHAR(64) NULL,
            external_id VARCHAR(64) NULL,
            status VARCHAR(32) NULL,
            request_payload {$jsonType} NULL,
            response_payload {$jsonType} NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL
        )");

        $this->db->exec("CREATE TABLE IF NOT EXISTS integration_outbox (
            id {$idType} PRIMARY KEY {$auto},
            integration_id VARCHAR(64) NOT NULL,
            action VARCHAR(64) NOT NULL,
            payload {$jsonType} NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'pending',
            attempts INT NOT NULL DEFAULT 0,
            next_run_at DATETIME NULL,
            last_error TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL
        )");

        $this->db->exec("CREATE TABLE IF NOT EXISTS integration_webhooks (
            id {$idType} PRIMARY KEY {$auto},
            tenant_id VARCHAR(64) NOT NULL DEFAULT '',
            integration_id VARCHAR(64) NOT NULL,
            event VARCHAR(64) NULL,
            external_id VARCHAR(64) NULL,
            payload {$jsonType} NULL,
            created_at DATETIME NOT NULL
        )");

        // Forward-compat: añadir tenant_id en instalaciones existentes sin la columna
        $this->ensureColumns();
    }

    /**
     * Añade tenant_id en tablas de integración existentes que aún no lo tienen.
     * Idempotente — silencioso si la columna ya existe.
     */
    private function ensureColumns(): void
    {
        $addCols = [
            'integration_connections' => "tenant_id VARCHAR(64) NOT NULL DEFAULT ''",
            'integration_documents'   => "tenant_id VARCHAR(64) NOT NULL DEFAULT ''",
            'integration_webhooks'    => "tenant_id VARCHAR(64) NOT NULL DEFAULT ''",
            'integration_tokens'      => "tenant_id VARCHAR(64) NOT NULL DEFAULT ''",
        ];
        foreach ($addCols as $table => $colDef) {
            try {
                $this->db->exec("ALTER TABLE {$table} ADD COLUMN IF NOT EXISTS {$colDef}");
            } catch (\Throwable $ignored) {
                // La columna ya existe o el driver no soporta IF NOT EXISTS — silencioso es correcto aquí
            }
        }
    }

    /**
     * @return array<int, string>
     */
    private function requiredTables(): array
    {
        return [
            'integration_connections',
            'integration_tokens',
            'integration_documents',
            'integration_outbox',
            'integration_webhooks',
        ];
    }

    private function driver(): string
    {
        return (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
    }
}
