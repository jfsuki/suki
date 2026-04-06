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

    public function saveDecisionTrace(array $trace): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO agent_decision_traces (
                tenant_id, project_id, session_id, route_path, selected_module, selected_action, evidence_source,
                ambiguity_detected, fallback_llm, latency_ms, result_status, metadata_json, created_at
             ) VALUES (
                :tenant_id, :project_id, :session_id, :route_path, :selected_module, :selected_action, :evidence_source,
                :ambiguity_detected, :fallback_llm, :latency_ms, :result_status, :metadata_json, :created_at
             )'
        );
        $stmt->execute([
            ':tenant_id' => $this->normTenant($trace['tenant_id'] ?? ''),
            ':project_id' => $this->normProject($trace['project_id'] ?? ''),
            ':session_id' => $this->normSession($trace['session_id'] ?? ''),
            ':route_path' => $this->normText($trace['route_path'] ?? 'unknown'),
            ':selected_module' => $this->normText($trace['selected_module'] ?? 'none'),
            ':selected_action' => $this->normText($trace['selected_action'] ?? 'none'),
            ':evidence_source' => $this->normText($trace['evidence_source'] ?? 'none'),
            ':ambiguity_detected' => (($trace['ambiguity_detected'] ?? false) === true) ? 1 : 0,
            ':fallback_llm' => (($trace['fallback_llm'] ?? false) === true) ? 1 : 0,
            ':latency_ms' => $this->intValue($trace['latency_ms'] ?? 0),
            ':result_status' => $this->normText($trace['result_status'] ?? 'unknown'),
            ':metadata_json' => $this->encodeJson($trace['metadata_json'] ?? $trace['metadata'] ?? []),
            ':created_at' => $this->createdAt($trace['created_at'] ?? null),
        ]);
    }

    public function saveToolExecutionTrace(array $trace): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO tool_execution_traces (
                tenant_id, project_id, module_key, action_key, input_schema_valid, permission_check, plan_check,
                execution_latency, success, error_code, metadata_json, created_at
             ) VALUES (
                :tenant_id, :project_id, :module_key, :action_key, :input_schema_valid, :permission_check, :plan_check,
                :execution_latency, :success, :error_code, :metadata_json, :created_at
             )'
        );
        $stmt->execute([
            ':tenant_id' => $this->normTenant($trace['tenant_id'] ?? ''),
            ':project_id' => $this->normProject($trace['project_id'] ?? ''),
            ':module_key' => $this->normText($trace['module_key'] ?? 'none'),
            ':action_key' => $this->normText($trace['action_key'] ?? 'none'),
            ':input_schema_valid' => (($trace['input_schema_valid'] ?? false) === true) ? 1 : 0,
            ':permission_check' => $this->normText($trace['permission_check'] ?? 'not_checked'),
            ':plan_check' => $this->normText($trace['plan_check'] ?? 'not_checked'),
            ':execution_latency' => $this->intValue($trace['execution_latency'] ?? 0),
            ':success' => (($trace['success'] ?? false) === true) ? 1 : 0,
            ':error_code' => $this->nullableText($trace['error_code'] ?? null),
            ':metadata_json' => $this->encodeJson($trace['metadata_json'] ?? $trace['metadata'] ?? []),
            ':created_at' => $this->createdAt($trace['created_at'] ?? null),
        ]);
    }

    public function saveSupportTicket(array $ticket): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO support_tickets (
                id, tenant_id, project_id, session_id, user_id, subject, message, sentiment, status, created_at, metadata_json
             ) VALUES (
                :id, :tenant_id, :project_id, :session_id, :user_id, :subject, :message, :sentiment, :status, :created_at, :metadata_json
             )'
        );
        $stmt->execute([
            ':id' => $this->normText($ticket['id'] ?? uniqid('tk_', true)),
            ':tenant_id' => $this->normTenant($ticket['tenant_id'] ?? ''),
            ':project_id' => $this->normProject($ticket['project_id'] ?? ''),
            ':session_id' => $this->normSession($ticket['session_id'] ?? ''),
            ':user_id' => $this->normText($ticket['user_id'] ?? ''),
            ':subject' => $this->normText($ticket['subject'] ?? ''),
            ':message' => $this->normText($ticket['message'] ?? ''),
            ':sentiment' => $this->normText($ticket['sentiment'] ?? 'neutral'),
            ':status' => $this->normText($ticket['status'] ?? 'open'),
            ':created_at' => $this->createdAt($ticket['created_at'] ?? null),
            ':metadata_json' => $this->encodeJson($ticket['metadata'] ?? []),
        ]);
    }

    public function getControlTowerStats(string $tenantId, string $projectId, int $days = 7): array
    {
        $tenantId = $this->normTenant($tenantId);
        $projectId = $this->normProject($projectId);
        $since = date('Y-m-d H:i:s', time() - ($days * 86400));

        // 1. Health Core (Dummy for now, will be updated by Tower Agent/Runner)
        $health = [
            'kernel' => 'ok',
            'db' => 'ok',
            'qdrant' => 'ok',
            'updated_at' => date('c')
        ];

        // 2. Top Intents
        $intents = $this->selectRows(
            'SELECT intent, COUNT(*) as count FROM ops_intent_metrics 
             WHERE tenant_id = :tenant AND project_id = :project AND created_at >= :since 
             GROUP BY intent ORDER BY count DESC LIMIT 5',
            [':tenant' => $tenantId, ':project' => $projectId, ':since' => $since]
        );

        // 3. Agent Ops
        $ops = $this->selectRows(
            'SELECT AVG(latency_ms) as avg_latency, SUM(total_tokens) as total_tokens 
             FROM ops_intent_metrics i 
             LEFT JOIN ops_token_usage t ON i.session_id = t.session_id 
             WHERE i.tenant_id = :tenant AND i.project_id = :project AND i.created_at >= :since',
            [':tenant' => $tenantId, ':project' => $projectId, ':since' => $since]
        )[0] ?? ['avg_latency' => 0, 'total_tokens' => 0];

        // 4. Learning Loop (Tickets)
        $tickets = $this->selectRows(
            'SELECT status, COUNT(*) as count FROM support_tickets 
             WHERE tenant_id = :tenant AND project_id = :project AND created_at >= :since 
             GROUP BY status',
            [':tenant' => $tenantId, ':project' => $projectId, ':since' => $since]
        );

        // 5. Security Hub
        $security = $this->selectRows(
            'SELECT COUNT(*) as count FROM ops_guardrail_events 
             WHERE tenant_id = :tenant AND project_id = :project AND created_at >= :since',
            [':tenant' => $tenantId, ':project' => $projectId, ':since' => $since]
        )[0]['count'] ?? 0;

        return [
            'health' => $health,
            'top_intents' => $intents,
            'agent_ops' => [
                'avg_latency_ms' => (int) $ops['avg_latency'],
                'total_tokens' => (int) $ops['total_tokens']
            ],
            'tickets' => $tickets,
            'security_alerts' => (int) $security,
            'timestamp' => date('c')
        ];
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

    public function listDecisionTraces(string $tenantId, string $projectId, array $filters = [], int $limit = 25): array
    {
        $tenantId = $this->normTenant($tenantId);
        $projectId = $this->normProject($projectId);
        $limit = max(1, min(200, $limit));
        $sql = 'SELECT id, tenant_id, project_id, session_id, route_path, selected_module, selected_action, evidence_source,
                ambiguity_detected, fallback_llm, latency_ms, result_status, metadata_json, created_at
            FROM agent_decision_traces
            WHERE tenant_id = :tenant AND project_id = :project';
        $params = [
            ':tenant' => $tenantId,
            ':project' => $projectId,
        ];

        $resultStatus = trim((string) ($filters['result_status'] ?? ''));
        if ($resultStatus !== '') {
            $sql .= ' AND result_status = :result_status';
            $params[':result_status'] = $resultStatus;
        }

        $selectedModule = trim((string) ($filters['selected_module'] ?? ''));
        if ($selectedModule !== '') {
            $sql .= ' AND selected_module = :selected_module';
            $params[':selected_module'] = $selectedModule;
        }

        $since = trim((string) ($filters['since'] ?? ''));
        if ($since !== '') {
            $sql .= ' AND created_at >= :since';
            $params[':since'] = $since;
        }

        $sql .= ' ORDER BY created_at DESC, id DESC LIMIT ' . $limit;

        return array_map([$this, 'normalizeDecisionTraceRow'], $this->selectRows($sql, $params));
    }

    public function listToolExecutionTraces(string $tenantId, string $projectId, array $filters = [], int $limit = 25): array
    {
        $tenantId = $this->normTenant($tenantId);
        $projectId = $this->normProject($projectId);
        $limit = max(1, min(200, $limit));
        $sql = 'SELECT id, tenant_id, project_id, module_key, action_key, input_schema_valid, permission_check, plan_check,
                execution_latency, success, error_code, metadata_json, created_at
            FROM tool_execution_traces
            WHERE tenant_id = :tenant AND project_id = :project';
        $params = [
            ':tenant' => $tenantId,
            ':project' => $projectId,
        ];

        $moduleKey = trim((string) ($filters['module_key'] ?? ''));
        if ($moduleKey !== '') {
            $sql .= ' AND module_key = :module_key';
            $params[':module_key'] = $moduleKey;
        }

        $success = $filters['success'] ?? null;
        if ($success !== null && $success !== '') {
            $sql .= ' AND success = :success';
            $params[':success'] = (($success ?? false) === true || (string) $success === '1') ? 1 : 0;
        }

        $since = trim((string) ($filters['since'] ?? ''));
        if ($since !== '') {
            $sql .= ' AND created_at >= :since';
            $params[':since'] = $since;
        }

        $sql .= ' ORDER BY created_at DESC, id DESC LIMIT ' . $limit;

        return array_map([$this, 'normalizeToolExecutionTraceRow'], $this->selectRows($sql, $params));
    }

    public function observabilitySummary(string $tenantId, string $projectId, int $days = 7): array
    {
        $tenantId = $this->normTenant($tenantId);
        $projectId = $this->normProject($projectId);
        $days = max(1, min(90, $days));
        $since = date('Y-m-d H:i:s', time() - ($days * 86400));

        $decisionRows = $this->listDecisionTraces($tenantId, $projectId, ['since' => $since], 1000);
        $toolRows = $this->listToolExecutionTraces($tenantId, $projectId, ['since' => $since], 1000);

        $moduleUsage = [];
        $decisionLatency = [];
        $fallbackCount = 0;
        $ambiguityCount = 0;
        $decisionErrors = 0;
        foreach ($decisionRows as $row) {
            $moduleKey = trim((string) ($row['selected_module'] ?? '')) ?: 'none';
            $moduleUsage[$moduleKey] = ($moduleUsage[$moduleKey] ?? 0) + 1;
            $decisionLatency[] = (int) ($row['latency_ms'] ?? 0);
            if (($row['fallback_llm'] ?? false) === true) {
                $fallbackCount++;
            }
            if (($row['ambiguity_detected'] ?? false) === true) {
                $ambiguityCount++;
            }
            if ($this->isDecisionErrorStatus((string) ($row['result_status'] ?? ''))) {
                $decisionErrors++;
            }
        }

        $toolLatency = [];
        $toolFailures = 0;
        $permissionDenials = 0;
        $planLimitWarnings = 0;
        $errorsByModule = [];
        foreach ($toolRows as $row) {
            $toolLatency[] = (int) ($row['execution_latency'] ?? 0);
            $moduleKey = trim((string) ($row['module_key'] ?? '')) ?: 'none';
            $permissionCheck = strtolower(trim((string) ($row['permission_check'] ?? '')));
            $planCheck = strtolower(trim((string) ($row['plan_check'] ?? '')));
            $success = (($row['success'] ?? false) === true);
            if (!$success) {
                $toolFailures++;
                $errorsByModule[$moduleKey] = ($errorsByModule[$moduleKey] ?? 0) + 1;
            }
            if (str_starts_with($permissionCheck, 'deny')) {
                $permissionDenials++;
            }
            if (str_contains($planCheck, 'warn') || str_contains($planCheck, 'over_limit') || str_contains($planCheck, 'disabled')) {
                $planLimitWarnings++;
            }
        }

        arsort($moduleUsage);
        arsort($errorsByModule);
        $decisionCount = count($decisionRows);
        $toolCount = count($toolRows);
        $avgLatency = $decisionCount > 0 ? (float) round(array_sum($decisionLatency) / $decisionCount, 4) : 0.0;

        return [
            'decision_traces' => [
                'count' => $decisionCount,
                'average_latency_ms' => $avgLatency,
                'fallback_llm_rate' => $decisionCount > 0 ? round($fallbackCount / $decisionCount, 6) : 0.0,
                'ambiguity_rate' => $decisionCount > 0 ? round($ambiguityCount / $decisionCount, 6) : 0.0,
                'error_rate' => $decisionCount > 0 ? round($decisionErrors / $decisionCount, 6) : 0.0,
                'module_usage' => $this->exportCounts($moduleUsage, 'module_key', 'usage_count'),
                'p95_latency_ms' => $this->percentile($decisionLatency, 95),
            ],
            'tool_execution' => [
                'count' => $toolCount,
                'average_latency_ms' => $toolCount > 0 ? (float) round(array_sum($toolLatency) / $toolCount, 4) : 0.0,
                'error_rate' => $toolCount > 0 ? round($toolFailures / $toolCount, 6) : 0.0,
                'permission_denials' => $permissionDenials,
                'plan_limit_warnings' => $planLimitWarnings,
                'errors_by_module' => $this->exportCounts($errorsByModule, 'module_key', 'error_count'),
                'p95_latency_ms' => $this->percentile($toolLatency, 95),
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

        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS agent_decision_traces (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id TEXT NOT NULL,
                project_id TEXT NOT NULL,
                session_id TEXT NOT NULL DEFAULT \'\',
                route_path TEXT NOT NULL,
                selected_module TEXT NOT NULL,
                selected_action TEXT NOT NULL,
                evidence_source TEXT NOT NULL,
                ambiguity_detected INTEGER NOT NULL DEFAULT 0,
                fallback_llm INTEGER NOT NULL DEFAULT 0,
                latency_ms INTEGER NOT NULL DEFAULT 0,
                result_status TEXT NOT NULL,
                metadata_json TEXT NOT NULL DEFAULT \'{}\',
                created_at TEXT NOT NULL
            )'
        );
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_agent_decision_scope ON agent_decision_traces (tenant_id, project_id, created_at)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_agent_decision_session ON agent_decision_traces (tenant_id, project_id, session_id, created_at)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_agent_decision_module ON agent_decision_traces (tenant_id, project_id, selected_module, created_at)');

        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS tool_execution_traces (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id TEXT NOT NULL,
                project_id TEXT NOT NULL,
                module_key TEXT NOT NULL,
                action_key TEXT NOT NULL,
                input_schema_valid INTEGER NOT NULL DEFAULT 0,
                permission_check TEXT NOT NULL,
                plan_check TEXT NOT NULL,
                execution_latency INTEGER NOT NULL DEFAULT 0,
                success INTEGER NOT NULL DEFAULT 0,
                error_code TEXT NULL,
                metadata_json TEXT NOT NULL DEFAULT \'{}\',
                created_at TEXT NOT NULL
            )'
        );
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_tool_execution_scope ON tool_execution_traces (tenant_id, project_id, created_at)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_tool_execution_module ON tool_execution_traces (tenant_id, project_id, module_key, created_at)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_tool_execution_status ON tool_execution_traces (tenant_id, project_id, success, created_at)');
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

    private function nullableText($value): ?string
    {
        $value = trim((string) $value);
        return $value !== '' ? $value : null;
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
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeDecisionTraceRow(array $row): array
    {
        return [
            'id' => (string) ($row['id'] ?? ''),
            'tenant_id' => $this->normTenant($row['tenant_id'] ?? ''),
            'project_id' => $this->normProject($row['project_id'] ?? ''),
            'session_id' => $this->normSession($row['session_id'] ?? ''),
            'route_path' => $this->normText($row['route_path'] ?? 'unknown'),
            'selected_module' => $this->normText($row['selected_module'] ?? 'none'),
            'selected_action' => $this->normText($row['selected_action'] ?? 'none'),
            'evidence_source' => $this->normText($row['evidence_source'] ?? 'none'),
            'ambiguity_detected' => ((int) ($row['ambiguity_detected'] ?? 0)) === 1,
            'fallback_llm' => ((int) ($row['fallback_llm'] ?? 0)) === 1,
            'latency_ms' => (int) ($row['latency_ms'] ?? 0),
            'result_status' => $this->normText($row['result_status'] ?? 'unknown'),
            'metadata' => $this->decodeJson($row['metadata_json'] ?? null),
            'created_at' => $this->createdAt($row['created_at'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeToolExecutionTraceRow(array $row): array
    {
        return [
            'id' => (string) ($row['id'] ?? ''),
            'tenant_id' => $this->normTenant($row['tenant_id'] ?? ''),
            'project_id' => $this->normProject($row['project_id'] ?? ''),
            'module_key' => $this->normText($row['module_key'] ?? 'none'),
            'action_key' => $this->normText($row['action_key'] ?? 'none'),
            'input_schema_valid' => ((int) ($row['input_schema_valid'] ?? 0)) === 1,
            'permission_check' => $this->normText($row['permission_check'] ?? 'not_checked'),
            'plan_check' => $this->normText($row['plan_check'] ?? 'not_checked'),
            'execution_latency' => (int) ($row['execution_latency'] ?? 0),
            'success' => ((int) ($row['success'] ?? 0)) === 1,
            'error_code' => $this->nullableText($row['error_code'] ?? null),
            'metadata' => $this->decodeJson($row['metadata_json'] ?? null),
            'created_at' => $this->createdAt($row['created_at'] ?? null),
        ];
    }

    /**
     * @param array<string, int> $counts
     * @return array<int, array<string, mixed>>
     */
    private function exportCounts(array $counts, string $keyName, string $valueName): array
    {
        $items = [];
        foreach ($counts as $key => $value) {
            $items[] = [
                $keyName => (string) $key,
                $valueName => (int) $value,
            ];
        }

        return $items;
    }

    private function isDecisionErrorStatus(string $status): bool
    {
        $status = strtolower(trim($status));
        return in_array($status, ['error', 'failed', 'blocked', 'unresolved'], true);
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
            'agent_decision_traces',
            'tool_execution_traces',
            'support_tickets',
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
            'agent_decision_traces' => ['idx_agent_decision_scope', 'idx_agent_decision_session', 'idx_agent_decision_module'],
            'tool_execution_traces' => ['idx_tool_execution_scope', 'idx_tool_execution_module', 'idx_tool_execution_status'],
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

    public function getAppCatalogStats(): array
    {
        // Agregación de señales RED (Rate, Errors, Duration) + Costos por Proyecto
        $sql = "SELECT 
                    p.id as project_id, 
                    p.name as project_name, 
                    p.status as project_status,
                    COUNT(DISTINCT i.session_id) as total_sessions,
                    AVG(i.latency_ms) as avg_latency_ms,
                    SUM(CASE WHEN i.status = 'error' THEN 1 ELSE 0 END) * 100.0 / NULLIF(COUNT(i.id), 0) as error_rate,
                    SUM(t.total_tokens) as total_tokens,
                    SUM(t.estimated_cost_usd) as total_cost_usd,
                    (SELECT COUNT(DISTINCT tenant_id) FROM auth_users WHERE project_id = p.id) as tenant_count
                FROM projects p
                LEFT JOIN ops_intent_metrics i ON i.project_id = p.id
                LEFT JOIN ops_token_usage t ON t.session_id = i.session_id
                GROUP BY p.id";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getAppHealthTimeline(string $projectId, int $days = 7): array
    {
        $projectId = $this->normProject($projectId);
        $since = date('Y-m-d H:i:s', time() - ($days * 86400));
        
        $sql = "SELECT 
                    strftime('%Y-%m-%d %H:00:00', created_at) as hour,
                    SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as successes,
                    SUM(CASE WHEN status = 'error' THEN 1 ELSE 0 END) as errors,
                    AVG(latency_ms) as avg_latency
                FROM ops_intent_metrics
                WHERE project_id = :project AND created_at >= :since
                GROUP BY hour
                ORDER BY hour ASC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':project' => $projectId, ':since' => $since]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getHealthByWorld(int $days = 1): array
    {
        $since = date('Y-m-d H:i:s', time() - ($days * 86400));
        $sql = "SELECT project_id, mode, AVG(latency_ms) as p50, MAX(latency_ms) as p95, 
                       COUNT(*) as total, 
                       SUM(CASE WHEN status != 'success' THEN 1 ELSE 0 END) as errors
                FROM ops_intent_metrics
                WHERE created_at >= :since
                GROUP BY project_id, mode";
        
        $rows = $this->selectRows($sql, [':since' => $since]);
        $worlds = ['marketplace' => null, 'apps' => null, 'builder' => null, 'torre' => null];
        
        foreach ($rows as $row) {
            $key = $this->mapWorld($row['project_id'], $row['mode']);
            $worlds[$key] = [
                'status' => ($row['p95'] > 2000 || $row['errors'] > ($row['total'] * 0.1)) ? 'Lento' : 'OK',
                'p50' => round($row['p50'], 2),
                'p95' => round($row['p95'], 2),
                'uptime' => 99.9 // Placeholder for hosting uptime
            ];
        }
        return $worlds;
    }

    private function mapWorld(string $pid, string $mode): string {
        if ($pid === 'torre' || str_contains($pid, 'tower')) return 'torre';
        if ($mode === 'builder') return 'builder';
        if ($pid === 'marketplace' || $pid === 'default') return 'marketplace';
        return 'apps';
    }

    public function getApiDetailedMetrics(string $range = 'today'): array
    {
        $since = match($range) {
            'week' => date('Y-m-d H:i:s', time() - 604800),
            'month' => date('Y-m-d H:i:s', time() - 2592000),
            default => date('Y-m-d 00:00:00'),
        };
        
        $sql = "SELECT provider, 
                       COUNT(*) as calls, 
                       SUM(total_tokens) as tokens, 
                       SUM(estimated_cost_usd) as cost,
                       AVG(total_tokens) as avg_tokens
                FROM ops_token_usage
                WHERE created_at >= :since
                GROUP BY provider";
        
        $results = $this->selectRows($sql, [':since' => $since]);
        
        // Split Gemini into LLM and Embedding for the UI if possible
        $final = [];
        foreach ($results as $res) {
            if ($res['provider'] === 'gemini') {
                $final['Gemini LLM'] = $res;
                // Dummy split for embedding if not explicitly tracked
                $final['Gemini Embedding'] = [
                    'provider' => 'gemini-emb',
                    'calls' => round($res['calls']*0.2), 
                    'tokens' => round($res['tokens']*0.05),
                    'cost' => $res['cost']*0.01 
                ];
            } else {
                $final[ucwords($res['provider'])] = $res;
            }
        }
        return $final;
    }

    public function getDatabaseStats(): array
    {
        $projectRoot = defined('PROJECT_ROOT') ? PROJECT_ROOT : dirname(__DIR__, 3) . '/project';
        $dbPath = $projectRoot . '/storage/meta/project_registry.sqlite';
        $size = file_exists($dbPath) ? filesize($dbPath) : 0;
        
        return [
            'total_dbs' => 1,
            'total_disk_gb' => round($size / (1024*1024*1024), 6),
            'top_tenants' => $this->selectRows(
                "SELECT tenant_id, COUNT(*) as activity FROM ops_intent_metrics GROUP BY tenant_id ORDER BY activity DESC LIMIT 5",
                []
            )
        ];
    }

    public function getSupportTicketSummary(int $limit = 5): array
    {
        $sql = "SELECT id, subject, sentiment, status, created_at, metadata_json
                FROM support_tickets
                ORDER BY created_at DESC LIMIT :limit";
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return array_map(function($row) {
            $meta = $this->decodeJson($row['metadata_json']);
            $row['resolved_by_suki'] = $meta['resolved_by_suki'] ?? ($row['status'] === 'closed' ? 'unknown' : 'no');
            return $row;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
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

    /**
     * @param mixed $value
     * @return array<string, mixed>
     */
    private function decodeJson($value): array
    {
        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param mixed $value
     */
    private function encodeJson($value): string
    {
        $encoded = json_encode(
            is_array($value) ? $value : [],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        return is_string($encoded) ? $encoded : '{}';
    }
}
