<?php
// app/Core/IntegrationStore.php

namespace App\Core;

use PDO;

final class IntegrationStore
{
    private PDO $db;
    private IntegrationMigrator $migrator;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
        $this->migrator = new IntegrationMigrator($this->db);
        $this->migrator->ensureTables();
    }

    public function saveConnection(array $integration): void
    {
        $id = (string) ($integration['id'] ?? '');
        $provider = (string) ($integration['provider'] ?? '');
        $type = (string) ($integration['type'] ?? '');
        $country = (string) ($integration['country'] ?? '');
        $environment = (string) ($integration['environment'] ?? '');
        $baseUrl = (string) ($integration['base_url'] ?? '');
        $tokenEnv = (string) ($integration['auth']['token_env'] ?? '');
        $enabled = !empty($integration['enabled']) ? 1 : 0;

        $now = date('Y-m-d H:i:s');

        $sql = "SELECT id FROM integration_connections WHERE integration_id = :integration_id LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':integration_id', $id);
        $stmt->execute();
        $existing = $stmt->fetchColumn();

        if ($existing) {
            $upd = $this->db->prepare("UPDATE integration_connections SET provider = :provider, type = :type, country = :country, environment = :environment, base_url = :base_url, token_env = :token_env, enabled = :enabled, updated_at = :updated_at WHERE integration_id = :integration_id");
            $upd->bindValue(':provider', $provider);
            $upd->bindValue(':type', $type);
            $upd->bindValue(':country', $country);
            $upd->bindValue(':environment', $environment);
            $upd->bindValue(':base_url', $baseUrl);
            $upd->bindValue(':token_env', $tokenEnv);
            $upd->bindValue(':enabled', $enabled);
            $upd->bindValue(':updated_at', $now);
            $upd->bindValue(':integration_id', $id);
            $upd->execute();
            return;
        }

        $ins = $this->db->prepare("INSERT INTO integration_connections (integration_id, provider, type, country, environment, base_url, token_env, enabled, created_at, updated_at) VALUES (:integration_id, :provider, :type, :country, :environment, :base_url, :token_env, :enabled, :created_at, :updated_at)");
        $ins->bindValue(':integration_id', $id);
        $ins->bindValue(':provider', $provider);
        $ins->bindValue(':type', $type);
        $ins->bindValue(':country', $country);
        $ins->bindValue(':environment', $environment);
        $ins->bindValue(':base_url', $baseUrl);
        $ins->bindValue(':token_env', $tokenEnv);
        $ins->bindValue(':enabled', $enabled);
        $ins->bindValue(':created_at', $now);
        $ins->bindValue(':updated_at', $now);
        $ins->execute();
    }

    public function saveDocument(string $integrationId, ?string $entity, ?string $recordId, ?string $externalId, ?string $status, array $request, array $response): void
    {
        $now = date('Y-m-d H:i:s');
        $sql = "INSERT INTO integration_documents (integration_id, entity, record_id, external_id, status, request_payload, response_payload, created_at, updated_at) VALUES (:integration_id, :entity, :record_id, :external_id, :status, :request_payload, :response_payload, :created_at, :updated_at)";
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':integration_id', $integrationId);
        $stmt->bindValue(':entity', $entity);
        $stmt->bindValue(':record_id', $recordId);
        $stmt->bindValue(':external_id', $externalId);
        $stmt->bindValue(':status', $status);
        $stmt->bindValue(':request_payload', json_encode($request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $stmt->bindValue(':response_payload', json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $stmt->bindValue(':created_at', $now);
        $stmt->bindValue(':updated_at', $now);
        $stmt->execute();
    }

    public function updateDocumentStatus(string $integrationId, string $externalId, string $status, array $response = []): void
    {
        $sql = "UPDATE integration_documents SET status = :status, response_payload = :response_payload, updated_at = :updated_at WHERE integration_id = :integration_id AND external_id = :external_id";
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':status', $status);
        $stmt->bindValue(':response_payload', json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $stmt->bindValue(':updated_at', date('Y-m-d H:i:s'));
        $stmt->bindValue(':integration_id', $integrationId);
        $stmt->bindValue(':external_id', $externalId);
        $stmt->execute();
    }

    public function logWebhook(string $integrationId, ?string $event, ?string $externalId, array $payload): void
    {
        $sql = "INSERT INTO integration_webhooks (integration_id, event, external_id, payload, created_at) VALUES (:integration_id, :event, :external_id, :payload, :created_at)";
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':integration_id', $integrationId);
        $stmt->bindValue(':event', $event);
        $stmt->bindValue(':external_id', $externalId);
        $stmt->bindValue(':payload', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $stmt->bindValue(':created_at', date('Y-m-d H:i:s'));
        $stmt->execute();
    }
}
