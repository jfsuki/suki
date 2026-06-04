<?php

declare(strict_types=1);

namespace App\Core\Agents\Tools;

/**
 * Skill para actualizar la memoria interna (Aprendizajes, Contexto, Preferencias).
 * Almacena en tabla DB `agent_memory` con aislamiento por tenant_id + agent_id.
 */
final class UpdateInternalMemorySkill
{
    /** DynamicSkillRegistry adapter — context carries tenant_id and agent_id */
    public function handle(array $input, array $context = []): array
    {
        return $this->execute($input, $context);
    }

    /**
     * @param array<string, mixed> $args
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function execute(array $args, array $context = []): array
    {
        $tenantId   = trim((string) ($context['tenant_id']  ?? $args['tenant_id']  ?? ''));
        $agentId    = trim((string) ($context['agent_id']   ?? $args['agent_id']   ?? 'default'));
        $memoryType = trim((string) ($args['memory_type']   ?? 'learned'));
        $content    = trim((string) ($args['content']       ?? ''));
        $updateMode = trim((string) ($args['update_mode']   ?? 'append'));

        if ($content === '') {
            return ['status' => 'error', 'message' => 'Content cannot be empty.'];
        }
        if ($tenantId === '') {
            return ['status' => 'error', 'message' => 'tenant_id requerido para actualizar memoria.'];
        }

        try {
            $pdo = \App\Core\Database::connection();
            $this->ensureSchema($pdo);

            $existing = $this->fetchExisting($pdo, $tenantId, $agentId, $memoryType);
            $newContent = $updateMode === 'append' && $existing !== null
                ? $existing . "\n\n---\n### Update: " . date('Y-m-d H:i:s') . "\n" . $content
                : $content;

            $upsert = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite'
                ? 'INSERT OR REPLACE INTO agent_memory (tenant_id, agent_id, memory_type, content, updated_at)
                   VALUES (:t, :a, :m, :c, :u)'
                : 'INSERT INTO agent_memory (tenant_id, agent_id, memory_type, content, updated_at)
                   VALUES (:t, :a, :m, :c, :u)
                   ON DUPLICATE KEY UPDATE content = VALUES(content), updated_at = VALUES(updated_at)';

            $pdo->prepare($upsert)->execute([
                ':t' => $tenantId,
                ':a' => $agentId,
                ':m' => $memoryType,
                ':c' => $newContent,
                ':u' => date('Y-m-d H:i:s'),
            ]);

            return [
                'status'  => 'success',
                'message' => "Memory [{$memoryType}] updated for tenant [{$tenantId}].",
                'tenant'  => $tenantId,
                'agent'   => $agentId,
            ];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'message' => 'Failed to update memory: ' . $e->getMessage()];
        }
    }

    private function ensureSchema(\PDO $pdo): void
    {
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $pk     = $driver === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT NOT NULL AUTO_INCREMENT PRIMARY KEY';
        $pdo->exec("CREATE TABLE IF NOT EXISTS agent_memory (
            id          {$pk},
            tenant_id   VARCHAR(120) NOT NULL,
            agent_id    VARCHAR(120) NOT NULL DEFAULT 'default',
            memory_type VARCHAR(60)  NOT NULL,
            content     TEXT         NOT NULL,
            updated_at  DATETIME     NULL,
            UNIQUE(tenant_id, agent_id, memory_type)
        )");
    }

    private function fetchExisting(\PDO $pdo, string $tenantId, string $agentId, string $memoryType): ?string
    {
        $stmt = $pdo->prepare(
            'SELECT content FROM agent_memory WHERE tenant_id = :t AND agent_id = :a AND memory_type = :m LIMIT 1'
        );
        $stmt->execute([':t' => $tenantId, ':a' => $agentId, ':m' => $memoryType]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return is_array($row) ? (string) ($row['content'] ?? '') : null;
    }
}
