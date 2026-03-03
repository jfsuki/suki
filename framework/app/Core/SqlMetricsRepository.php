<?php
// app/Core/SqlMetricsRepository.php

namespace App\Core;

use PDO;

final class SqlMetricsRepository implements MetricsRepositoryInterface
{
    private PDO $db;

    public function __construct(?PDO $db = null, ?string $dbPath = null)
    {
        if ($db instanceof PDO) {
            $this->db = $db;
        } else {
            $path = $dbPath ?: $this->defaultPath();
            $this->db = new PDO('sqlite:' . $path);
            $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        }
        RuntimeSchemaPolicy::bootstrap(
            $this->db,
            'SqlMetricsRepository',
            fn() => $this->ensureSchema(),
            $this->requiredTables(),
            $this->requiredIndexes(),
            $this->requiredColumns(),
            'db/migrations/sqlite/20260303_004_runtime_infra_schema.sql'
        );
    }

    public function saveIntentMetric(array $metric): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO ops_intent_metrics (
                tenant_id, project_id, session_id, mode, intent, action, latency_ms, status, created_at
             ) VALUES (
                :tenant_id, :project_id, :session_id, :mode, :intent, :action, :latency_ms, :status, :created_at
             )'
        );
        $stmt->execute([
            ':tenant_id' => $this->normTenant($metric['tenant_id'] ?? ''),
            ':project_id' => $this->normProject($metric['project_id'] ?? ''),
            ':session_id' => $this->normSession($metric['session_id'] ?? ''),
            ':mode' => $this->normMode($metric['mode'] ?? ''),
            ':intent' => $this->normText($metric['intent'] ?? 'unknown'),
            ':action' => $this->normText($metric['action'] ?? 'unknown'),
            ':latency_ms' => $this->intValue($metric['latency_ms'] ?? 0),
            ':status' => $this->normText($metric['status'] ?? 'success'),
            ':created_at' => $this->createdAt($metric['created_at'] ?? null),
        ]);
    }

    public function saveCommandMetric(array $metric): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO ops_command_metrics (
                tenant_id, project_id, session_id, mode, command_name, latency_ms, status, blocked, created_at
             ) VALUES (
                :tenant_id, :project_id, :session_id, :mode, :command_name, :latency_ms, :status, :blocked, :created_at
             )'
        );
        $stmt->execute([
            ':tenant_id' => $this->normTenant($metric['tenant_id'] ?? ''),
            ':project_id' => $this->normProject($metric['project_id'] ?? ''),
            ':session_id' => $this->normSession($metric['session_id'] ?? ''),
            ':mode' => $this->normMode($metric['mode'] ?? ''),
            ':command_name' => $this->normText($metric['command_name'] ?? 'unknown'),
            ':latency_ms' => $this->intValue($metric['latency_ms'] ?? 0),
            ':status' => $this->normText($metric['status'] ?? 'success'),
            ':blocked' => $this->intValue($metric['blocked'] ?? 0),
            ':created_at' => $this->createdAt($metric['created_at'] ?? null),
        ]);
    }

    public function saveGuardrailEvent(array $event): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO ops_guardrail_events (
                tenant_id, project_id, session_id, mode, guardrail, reason, created_at
             ) VALUES (
                :tenant_id, :project_id, :session_id, :mode, :guardrail, :reason, :created_at
             )'
        );
        $stmt->execute([
            ':tenant_id' => $this->normTenant($event['tenant_id'] ?? ''),
            ':project_id' => $this->normProject($event['project_id'] ?? ''),
            ':session_id' => $this->normSession($event['session_id'] ?? ''),
            ':mode' => $this->normMode($event['mode'] ?? ''),
            ':guardrail' => $this->normText($event['guardrail'] ?? 'unknown'),
            ':reason' => $this->normText($event['reason'] ?? ''),
            ':created_at' => $this->createdAt($event['created_at'] ?? null),
        ]);
    }

    public function saveTokenUsage(array $usage): void
    {
        $totalTokens = $this->intValue($usage['total_tokens'] ?? 0);
        if ($totalTokens <= 0) {
            $totalTokens = $this->intValue($usage['prompt_tokens'] ?? 0) + $this->intValue($usage['completion_tokens'] ?? 0);
        }
        $cost = $usage['estimated_cost_usd'] ?? null;
        if ($cost === null || $cost === '') {
            $cost = $this->estimateCostUsd($usage, $totalTokens);
        }

        $stmt = $this->db->prepare(
            'INSERT INTO ops_token_usage (
                tenant_id, project_id, session_id, provider, prompt_tokens, completion_tokens, total_tokens, estimated_cost_usd, created_at
             ) VALUES (
                :tenant_id, :project_id, :session_id, :provider, :prompt_tokens, :completion_tokens, :total_tokens, :estimated_cost_usd, :created_at
             )'
        );
        $stmt->execute([
            ':tenant_id' => $this->normTenant($usage['tenant_id'] ?? ''),
            ':project_id' => $this->normProject($usage['project_id'] ?? ''),
            ':session_id' => $this->normSession($usage['session_id'] ?? ''),
            ':provider' => $this->normText($usage['provider'] ?? 'unknown'),
            ':prompt_tokens' => $this->intValue($usage['prompt_tokens'] ?? 0),
            ':completion_tokens' => $this->intValue($usage['completion_tokens'] ?? 0),
            ':total_tokens' => $totalTokens,
            ':estimated_cost_usd' => round((float) $cost, 8),
            ':created_at' => $this->createdAt($usage['created_at'] ?? null),
        ]);
    }

    public function summary(string $tenantId, string $projectId, int $days = 7): array
    {
        $tenantId = $this->normTenant($tenantId);
        $projectId = $this->normProject($projectId);
        $days = max(1, min(90, $days));
        $since = date('Y-m-d H:i:s', time() - ($days * 86400));

        $intentRows = $this->selectRows(
            'SELECT latency_ms, status, action, session_id FROM ops_intent_metrics WHERE tenant_id = :tenant AND project_id = :project AND created_at >= :since',
            [':tenant' => $tenantId, ':project' => $projectId, ':since' => $since]
        );
        $commandRows = $this->selectRows(
            'SELECT latency_ms, status, blocked, session_id FROM ops_command_metrics WHERE tenant_id = :tenant AND project_id = :project AND created_at >= :since',
            [':tenant' => $tenantId, ':project' => $projectId, ':since' => $since]
        );
        $guardrailRows = $this->selectRows(
            'SELECT guardrail, session_id FROM ops_guardrail_events WHERE tenant_id = :tenant AND project_id = :project AND created_at >= :since',
            [':tenant' => $tenantId, ':project' => $projectId, ':since' => $since]
        );
        $tokenRows = $this->selectRows(
            'SELECT total_tokens, estimated_cost_usd, session_id FROM ops_token_usage WHERE tenant_id = :tenant AND project_id = :project AND created_at >= :since',
            [':tenant' => $tenantId, ':project' => $projectId, ':since' => $since]
        );

        $intentLatency = array_map(static fn(array $r): int => (int) ($r['latency_ms'] ?? 0), $intentRows);
        $commandLatency = array_map(static fn(array $r): int => (int) ($r['latency_ms'] ?? 0), $commandRows);
        $intentFallback = 0;
        $intentSessions = [];
        foreach ($intentRows as $row) {
            if ((string) ($row['action'] ?? '') === 'send_to_llm') {
                $intentFallback++;
            }
            $sessionId = trim((string) ($row['session_id'] ?? ''));
            if ($sessionId !== '') {
                $intentSessions[$sessionId] = true;
            }
        }
        $tokenTotal = 0;
        $tokenCost = 0.0;
        $tokenSessions = [];
        foreach ($tokenRows as $row) {
            $tokenTotal += (int) ($row['total_tokens'] ?? 0);
            $tokenCost += (float) ($row['estimated_cost_usd'] ?? 0.0);
            $sessionId = trim((string) ($row['session_id'] ?? ''));
            if ($sessionId !== '') {
                $tokenSessions[$sessionId] = true;
            }
        }

        $intentCount = count($intentRows);
        $fallbackRate = $intentCount > 0 ? round($intentFallback / $intentCount, 6) : 0.0;
        $localFirstRate = $intentCount > 0 ? round(($intentCount - $intentFallback) / $intentCount, 6) : 0.0;
        $tokenSessionCount = count($tokenSessions);
        $avgTokensPerSession = $tokenSessionCount > 0 ? (float) round($tokenTotal / $tokenSessionCount, 4) : 0.0;
        $avgCostPerSession = $tokenSessionCount > 0 ? (float) round($tokenCost / $tokenSessionCount, 8) : 0.0;

        return [
            'intent_metrics' => [
                'count' => $intentCount,
                'sessions' => count($intentSessions),
                'p50_latency_ms' => $this->percentile($intentLatency, 50),
                'p95_latency_ms' => $this->percentile($intentLatency, 95),
                'p99_latency_ms' => $this->percentile($intentLatency, 99),
                'fallback_llm' => $intentFallback,
                'fallback_rate' => $fallbackRate,
                'local_first_rate' => $localFirstRate,
            ],
            'command_metrics' => [
                'count' => count($commandRows),
                'p50_latency_ms' => $this->percentile($commandLatency, 50),
                'p95_latency_ms' => $this->percentile($commandLatency, 95),
                'p99_latency_ms' => $this->percentile($commandLatency, 99),
                'blocked' => count(array_filter($commandRows, static fn(array $r): bool => (int) ($r['blocked'] ?? 0) === 1)),
            ],
            'guardrail_events' => [
                'count' => count($guardrailRows),
            ],
            'token_usage' => [
                'events' => count($tokenRows),
                'total_tokens' => $tokenTotal,
                'estimated_cost_usd' => round($tokenCost, 8),
                'sessions' => $tokenSessionCount,
                'avg_tokens_per_session' => $avgTokensPerSession,
                'avg_cost_per_session_usd' => $avgCostPerSession,
            ],
        ];
    }

    private function ensureSchema(): void
    {
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS ops_intent_metrics (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id TEXT NOT NULL,
                project_id TEXT NOT NULL,
                session_id TEXT NOT NULL DEFAULT \'\',
                mode TEXT NOT NULL,
                intent TEXT NOT NULL,
                action TEXT NOT NULL,
                latency_ms INTEGER NOT NULL DEFAULT 0,
                status TEXT NOT NULL,
                created_at TEXT NOT NULL
            )'
        );
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_ops_intent_scope ON ops_intent_metrics (tenant_id, project_id, created_at)');

        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS ops_command_metrics (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id TEXT NOT NULL,
                project_id TEXT NOT NULL,
                session_id TEXT NOT NULL DEFAULT \'\',
                mode TEXT NOT NULL,
                command_name TEXT NOT NULL,
                latency_ms INTEGER NOT NULL DEFAULT 0,
                status TEXT NOT NULL,
                blocked INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL
            )'
        );
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_ops_command_scope ON ops_command_metrics (tenant_id, project_id, created_at)');

        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS ops_guardrail_events (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id TEXT NOT NULL,
                project_id TEXT NOT NULL,
                session_id TEXT NOT NULL DEFAULT \'\',
                mode TEXT NOT NULL,
                guardrail TEXT NOT NULL,
                reason TEXT NOT NULL,
                created_at TEXT NOT NULL
            )'
        );
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_ops_guardrail_scope ON ops_guardrail_events (tenant_id, project_id, created_at)');

        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS ops_token_usage (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id TEXT NOT NULL,
                project_id TEXT NOT NULL,
                session_id TEXT NOT NULL DEFAULT \'\',
                provider TEXT NOT NULL,
                prompt_tokens INTEGER NOT NULL DEFAULT 0,
                completion_tokens INTEGER NOT NULL DEFAULT 0,
                total_tokens INTEGER NOT NULL DEFAULT 0,
                estimated_cost_usd REAL NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL
            )'
        );
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_ops_tokens_scope ON ops_token_usage (tenant_id, project_id, created_at)');

        $this->ensureColumn('ops_intent_metrics', 'session_id', "ALTER TABLE ops_intent_metrics ADD COLUMN session_id TEXT NOT NULL DEFAULT ''");
        $this->ensureColumn('ops_command_metrics', 'session_id', "ALTER TABLE ops_command_metrics ADD COLUMN session_id TEXT NOT NULL DEFAULT ''");
        $this->ensureColumn('ops_guardrail_events', 'session_id', "ALTER TABLE ops_guardrail_events ADD COLUMN session_id TEXT NOT NULL DEFAULT ''");
        $this->ensureColumn('ops_token_usage', 'session_id', "ALTER TABLE ops_token_usage ADD COLUMN session_id TEXT NOT NULL DEFAULT ''");

        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_ops_intent_session ON ops_intent_metrics (tenant_id, project_id, session_id, created_at)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_ops_command_session ON ops_command_metrics (tenant_id, project_id, session_id, created_at)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_ops_guardrail_session ON ops_guardrail_events (tenant_id, project_id, session_id, created_at)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_ops_tokens_session ON ops_token_usage (tenant_id, project_id, session_id, created_at)');
    }

    private function defaultPath(): string
    {
        $override = trim((string) (getenv('PROJECT_REGISTRY_DB_PATH') ?: ''));
        if ($override !== '') {
            $dir = dirname($override);
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            return $override;
        }

        $projectRoot = defined('PROJECT_ROOT') ? PROJECT_ROOT : dirname(__DIR__, 3) . '/project';
        $dir = $projectRoot . '/storage/meta';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        return $dir . '/project_registry.sqlite';
    }

    /** @return array<int, array<string, mixed>> */
    private function selectRows(string $sql, array $params): array
    {
        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function createdAt($value): string
    {
        $value = trim((string) $value);
        return $value !== '' ? $value : date('Y-m-d H:i:s');
    }

    private function normTenant($value): string
    {
        $value = trim((string) $value);
        return $value !== '' ? $value : 'default';
    }

    private function normProject($value): string
    {
        $value = trim((string) $value);
        return $value !== '' ? $value : 'default';
    }

    private function normSession($value): string
    {
        return trim((string) $value);
    }

    private function normMode($value): string
    {
        $value = strtolower(trim((string) $value));
        return $value === 'builder' ? 'builder' : 'app';
    }

    private function normText($value): string
    {
        return trim((string) $value);
    }

    private function intValue($value): int
    {
        return (int) max(0, (int) $value);
    }

    private function estimateCostUsd(array $usage, int $totalTokens): float
    {
        $explicitRate = (float) (getenv('LLM_COST_PER_1K_TOKENS_USD') ?: 0.0);
        if ($explicitRate > 0) {
            return ($totalTokens / 1000) * $explicitRate;
        }

        $provider = strtolower(trim((string) ($usage['provider'] ?? '')));
        $defaults = [
            'groq' => 0.0003,
            'gemini' => 0.0004,
            'openrouter' => 0.0005,
            'openai' => 0.0006,
            'llm' => 0.0005,
        ];
        $ratePer1k = $defaults[$provider] ?? 0.0005;
        return ($totalTokens / 1000) * $ratePer1k;
    }

    private function percentile(array $values, int $percentile): int
    {
        if (empty($values)) {
            return 0;
        }
        sort($values, SORT_NUMERIC);
        $index = (int) ceil(($percentile / 100) * count($values)) - 1;
        $index = max(0, min(count($values) - 1, $index));
        return (int) $values[$index];
    }

    private function ensureColumn(string $table, string $column, string $alterSql): void
    {
        if ($this->tableHasColumn($table, $column)) {
            return;
        }
        $this->db->exec($alterSql);
    }

    /**
     * @return array<int, string>
     */
    private function requiredTables(): array
    {
        return [
            'ops_intent_metrics',
            'ops_command_metrics',
            'ops_guardrail_events',
            'ops_token_usage',
        ];
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function requiredIndexes(): array
    {
        return [
            'ops_intent_metrics' => ['idx_ops_intent_scope', 'idx_ops_intent_session'],
            'ops_command_metrics' => ['idx_ops_command_scope', 'idx_ops_command_session'],
            'ops_guardrail_events' => ['idx_ops_guardrail_scope', 'idx_ops_guardrail_session'],
            'ops_token_usage' => ['idx_ops_tokens_scope', 'idx_ops_tokens_session'],
        ];
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function requiredColumns(): array
    {
        return [
            'ops_intent_metrics' => ['session_id'],
            'ops_command_metrics' => ['session_id'],
            'ops_guardrail_events' => ['session_id'],
            'ops_token_usage' => ['session_id'],
        ];
    }

    private function tableHasColumn(string $table, string $column): bool
    {
        $stmt = $this->db->query('PRAGMA table_info(' . $table . ')');
        $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        foreach ($rows as $row) {
            if ((string) ($row['name'] ?? '') === $column) {
                return true;
            }
        }
        return false;
    }
}
