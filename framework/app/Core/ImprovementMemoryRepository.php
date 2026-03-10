<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use RuntimeException;

final class ImprovementMemoryRepository
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
            'ImprovementMemoryRepository',
            fn() => $this->ensureSchema(),
            $this->requiredTables(),
            $this->requiredIndexes(),
            $this->requiredColumns(),
            'db/migrations/sqlite/20260310_012_project_memory_system.sql'
        );
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    public function recordImprovement(array $record): array
    {
        $normalized = $this->normalizeImprovementRecord($record);
        $existing = $this->findImprovementByFingerprint(
            $normalized['tenant_id'],
            $normalized['module'],
            $normalized['problem_type'],
            $normalized['evidence_hash']
        );

        if (is_array($existing)) {
            $stmt = $this->db->prepare(
                'UPDATE improvement_memory
                 SET frequency = frequency + 1,
                     severity = :severity,
                     suggestion = :suggestion
                 WHERE id = :id'
            );
            $stmt->execute([
                ':severity' => $this->higherSeverity(
                    (string) ($existing['severity'] ?? 'medium'),
                    $normalized['severity']
                ),
                ':suggestion' => trim((string) ($existing['suggestion'] ?? '')) !== ''
                    ? (string) $existing['suggestion']
                    : $normalized['suggestion'],
                ':id' => $existing['id'],
            ]);

            $saved = $this->findImprovementById((string) $existing['id']);
            if (!is_array($saved)) {
                throw new RuntimeException('IMPROVEMENT_MEMORY_UPDATE_FETCH_FAILED');
            }

            return $saved;
        }

        $stmt = $this->db->prepare(
            'INSERT INTO improvement_memory (
                tenant_id, module, problem_type, frequency, severity, evidence, evidence_hash, suggestion, status, created_at
             ) VALUES (
                :tenant_id, :module, :problem_type, :frequency, :severity, :evidence, :evidence_hash, :suggestion, :status, :created_at
             )'
        );
        $stmt->execute([
            ':tenant_id' => $normalized['tenant_id'],
            ':module' => $normalized['module'],
            ':problem_type' => $normalized['problem_type'],
            ':frequency' => $normalized['frequency'],
            ':severity' => $normalized['severity'],
            ':evidence' => $normalized['evidence'],
            ':evidence_hash' => $normalized['evidence_hash'],
            ':suggestion' => $normalized['suggestion'],
            ':status' => $normalized['status'],
            ':created_at' => $normalized['created_at'],
        ]);

        $saved = $this->findImprovementById((string) $this->db->lastInsertId());
        if (!is_array($saved)) {
            throw new RuntimeException('IMPROVEMENT_MEMORY_INSERT_FETCH_FAILED');
        }

        return $saved;
    }

    /**
     * @param array<string, mixed> $candidate
     * @return array<string, mixed>
     */
    public function upsertLearningCandidate(array $candidate): array
    {
        $normalized = $this->normalizeCandidateRecord($candidate);
        $existing = $this->findLearningCandidate($normalized['tenant_id'], $normalized['candidate_id']);

        if (is_array($existing)) {
            $stmt = $this->db->prepare(
                'UPDATE learning_candidates
                 SET source_metric = :source_metric,
                     module = :module,
                     description = :description,
                     frequency = :frequency,
                     confidence = :confidence,
                     review_status = :review_status
                 WHERE tenant_id = :tenant_id AND candidate_id = :candidate_id'
            );
            $stmt->execute([
                ':source_metric' => $normalized['source_metric'],
                ':module' => $normalized['module'],
                ':description' => $normalized['description'],
                ':frequency' => $normalized['frequency'],
                ':confidence' => $normalized['confidence'],
                ':review_status' => $normalized['review_status'],
                ':tenant_id' => $normalized['tenant_id'],
                ':candidate_id' => $normalized['candidate_id'],
            ]);
        } else {
            $stmt = $this->db->prepare(
                'INSERT INTO learning_candidates (
                    candidate_id, tenant_id, source_metric, module, description, frequency, confidence, review_status, created_at
                 ) VALUES (
                    :candidate_id, :tenant_id, :source_metric, :module, :description, :frequency, :confidence, :review_status, :created_at
                 )'
            );
            $stmt->execute([
                ':candidate_id' => $normalized['candidate_id'],
                ':tenant_id' => $normalized['tenant_id'],
                ':source_metric' => $normalized['source_metric'],
                ':module' => $normalized['module'],
                ':description' => $normalized['description'],
                ':frequency' => $normalized['frequency'],
                ':confidence' => $normalized['confidence'],
                ':review_status' => $normalized['review_status'],
                ':created_at' => $normalized['created_at'],
            ]);
        }

        $saved = $this->findLearningCandidate($normalized['tenant_id'], $normalized['candidate_id']);
        if (!is_array($saved)) {
            throw new RuntimeException('LEARNING_CANDIDATE_FETCH_FAILED');
        }

        return $saved;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findImprovementById(string $id): ?array
    {
        if ($id === '') {
            return null;
        }
        $stmt = $this->db->prepare('SELECT * FROM improvement_memory WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $this->normalizeImprovementRow($row) : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findImprovementByFingerprint(string $tenantId, string $module, string $problemType, string $evidenceHash): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM improvement_memory
             WHERE tenant_id = :tenant_id
               AND module = :module
               AND problem_type = :problem_type
               AND evidence_hash = :evidence_hash
             LIMIT 1'
        );
        $stmt->execute([
            ':tenant_id' => $this->normText($tenantId, 'default'),
            ':module' => $this->normText($module, 'router'),
            ':problem_type' => $this->normText($problemType, 'tool_failure'),
            ':evidence_hash' => trim($evidenceHash),
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $this->normalizeImprovementRow($row) : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findLearningCandidate(string $tenantId, string $candidateId): ?array
    {
        if ($candidateId === '') {
            return null;
        }
        $stmt = $this->db->prepare(
            'SELECT * FROM learning_candidates
             WHERE tenant_id = :tenant_id AND candidate_id = :candidate_id
             LIMIT 1'
        );
        $stmt->execute([
            ':tenant_id' => $this->normText($tenantId, 'default'),
            ':candidate_id' => $candidateId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $this->normalizeCandidateRow($row) : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listTopImprovements(string $tenantId, int $limit = 10, int $days = 30): array
    {
        $limit = max(1, min(100, $limit));
        $since = $this->since($days);
        $stmt = $this->db->prepare(
            'SELECT * FROM improvement_memory
             WHERE tenant_id = :tenant_id AND created_at >= :since
             ORDER BY frequency DESC,
                      CASE severity
                          WHEN "critical" THEN 4
                          WHEN "high" THEN 3
                          WHEN "medium" THEN 2
                          ELSE 1
                      END DESC,
                      id DESC
             LIMIT :limit'
        );
        $stmt->bindValue(':tenant_id', $this->normText($tenantId, 'default'));
        $stmt->bindValue(':since', $since);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map(fn(array $row): array => $this->normalizeImprovementRow($row), $rows);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listLearningCandidates(string $tenantId, string $reviewStatus = '', int $limit = 10): array
    {
        $limit = max(1, min(100, $limit));
        $where = ['tenant_id = :tenant_id'];
        $params = [':tenant_id' => $this->normText($tenantId, 'default')];
        if (trim($reviewStatus) !== '') {
            $where[] = 'review_status = :review_status';
            $params[':review_status'] = trim($reviewStatus);
        }

        $sql = 'SELECT * FROM learning_candidates
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY confidence DESC, frequency DESC, created_at DESC
                LIMIT :limit';
        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map(fn(array $row): array => $this->normalizeCandidateRow($row), $rows);
    }

    /**
     * @return array<string, mixed>
     */
    public function aggregate(string $tenantId, int $days = 30): array
    {
        $tenantId = $this->normText($tenantId, 'default');
        $since = $this->since($days);

        $summaryRow = $this->selectOne(
            'SELECT
                COUNT(*) AS improvements,
                COALESCE(SUM(frequency), 0) AS total_frequency,
                SUM(CASE WHEN status IN ("open", "reviewing", "pending") THEN 1 ELSE 0 END) AS open_items
             FROM improvement_memory
             WHERE tenant_id = :tenant_id AND created_at >= :since',
            [':tenant_id' => $tenantId, ':since' => $since]
        ) ?? [];

        $candidateRow = $this->selectOne(
            'SELECT
                SUM(CASE WHEN review_status = "pending" THEN 1 ELSE 0 END) AS pending_candidates,
                SUM(CASE WHEN review_status = "approved" THEN 1 ELSE 0 END) AS approved_candidates,
                SUM(CASE WHEN review_status = "rejected" THEN 1 ELSE 0 END) AS rejected_candidates
             FROM learning_candidates
             WHERE tenant_id = :tenant_id',
            [':tenant_id' => $tenantId]
        ) ?? [];

        $problemRows = $this->selectRows(
            'SELECT
                problem_type,
                COUNT(*) AS items,
                COALESCE(SUM(frequency), 0) AS frequency
             FROM improvement_memory
             WHERE tenant_id = :tenant_id AND created_at >= :since
             GROUP BY problem_type
             ORDER BY frequency DESC, problem_type ASC',
            [':tenant_id' => $tenantId, ':since' => $since]
        );

        $moduleRows = $this->selectRows(
            'SELECT
                module,
                COUNT(*) AS items,
                COALESCE(SUM(frequency), 0) AS frequency
             FROM improvement_memory
             WHERE tenant_id = :tenant_id AND created_at >= :since
             GROUP BY module
             ORDER BY frequency DESC, module ASC',
            [':tenant_id' => $tenantId, ':since' => $since]
        );

        return [
            'tenant_id' => $tenantId,
            'days' => max(1, min(90, $days)),
            'totals' => [
                'improvements' => (int) ($summaryRow['improvements'] ?? 0),
                'total_frequency' => (int) ($summaryRow['total_frequency'] ?? 0),
                'open_items' => (int) ($summaryRow['open_items'] ?? 0),
                'pending_candidates' => (int) ($candidateRow['pending_candidates'] ?? 0),
                'approved_candidates' => (int) ($candidateRow['approved_candidates'] ?? 0),
                'rejected_candidates' => (int) ($candidateRow['rejected_candidates'] ?? 0),
            ],
            'by_problem_type' => array_map(
                static fn(array $row): array => [
                    'problem_type' => (string) ($row['problem_type'] ?? ''),
                    'items' => (int) ($row['items'] ?? 0),
                    'frequency' => (int) ($row['frequency'] ?? 0),
                ],
                $problemRows
            ),
            'by_module' => array_map(
                static fn(array $row): array => [
                    'module' => (string) ($row['module'] ?? ''),
                    'items' => (int) ($row['items'] ?? 0),
                    'frequency' => (int) ($row['frequency'] ?? 0),
                ],
                $moduleRows
            ),
            'top_issues' => $this->listTopImprovements($tenantId, 5, $days),
        ];
    }

    private function ensureSchema(): void
    {
        if ($this->driver() === 'mysql') {
            $this->ensureSchemaMySql();
            return;
        }

        $this->ensureSchemaSqlite();
    }

    private function ensureSchemaSqlite(): void
    {
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS improvement_memory (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id TEXT NOT NULL,
                module TEXT NOT NULL,
                problem_type TEXT NOT NULL,
                frequency INTEGER NOT NULL DEFAULT 1,
                severity TEXT NOT NULL DEFAULT "medium",
                evidence TEXT NOT NULL DEFAULT "",
                evidence_hash TEXT NOT NULL,
                suggestion TEXT NOT NULL DEFAULT "",
                status TEXT NOT NULL DEFAULT "open",
                created_at TEXT NOT NULL
            )'
        );
        $this->db->exec(
            'CREATE UNIQUE INDEX IF NOT EXISTS uq_improvement_memory_fingerprint
             ON improvement_memory (tenant_id, module, problem_type, evidence_hash)'
        );
        $this->db->exec(
            'CREATE INDEX IF NOT EXISTS idx_improvement_memory_scope
             ON improvement_memory (tenant_id, module, problem_type, created_at)'
        );

        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS learning_candidates (
                candidate_id TEXT PRIMARY KEY,
                tenant_id TEXT NOT NULL,
                source_metric TEXT NOT NULL,
                module TEXT NOT NULL,
                description TEXT NOT NULL,
                frequency INTEGER NOT NULL DEFAULT 0,
                confidence REAL NOT NULL DEFAULT 0,
                review_status TEXT NOT NULL DEFAULT "pending",
                created_at TEXT NOT NULL
            )'
        );
        $this->db->exec(
            'CREATE INDEX IF NOT EXISTS idx_learning_candidates_scope
             ON learning_candidates (tenant_id, review_status, created_at)'
        );
        $this->db->exec(
            'CREATE INDEX IF NOT EXISTS idx_learning_candidates_source
             ON learning_candidates (tenant_id, source_metric, module)'
        );
    }

    private function ensureSchemaMySql(): void
    {
        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS improvement_memory (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                tenant_id VARCHAR(120) NOT NULL,
                module VARCHAR(120) NOT NULL,
                problem_type VARCHAR(64) NOT NULL,
                frequency INT UNSIGNED NOT NULL DEFAULT 1,
                severity VARCHAR(16) NOT NULL DEFAULT 'medium',
                evidence TEXT NOT NULL,
                evidence_hash CHAR(40) NOT NULL,
                suggestion TEXT NOT NULL,
                status VARCHAR(32) NOT NULL DEFAULT 'open',
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_improvement_memory_fingerprint (tenant_id, module, problem_type, evidence_hash),
                KEY idx_improvement_memory_scope (tenant_id, module, problem_type, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS learning_candidates (
                candidate_id VARCHAR(64) NOT NULL,
                tenant_id VARCHAR(120) NOT NULL,
                source_metric VARCHAR(64) NOT NULL,
                module VARCHAR(120) NOT NULL,
                description TEXT NOT NULL,
                frequency INT UNSIGNED NOT NULL DEFAULT 0,
                confidence DECIMAL(6,4) NOT NULL DEFAULT 0,
                review_status VARCHAR(16) NOT NULL DEFAULT 'pending',
                created_at DATETIME NOT NULL,
                PRIMARY KEY (candidate_id),
                KEY idx_learning_candidates_scope (tenant_id, review_status, created_at),
                KEY idx_learning_candidates_source (tenant_id, source_metric, module)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
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

    private function driver(): string
    {
        return strtolower((string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME));
    }

    /**
     * @return array<int, string>
     */
    private function requiredTables(): array
    {
        return ['improvement_memory', 'learning_candidates'];
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function requiredIndexes(): array
    {
        return [
            'improvement_memory' => [
                'uq_improvement_memory_fingerprint',
                'idx_improvement_memory_scope',
            ],
            'learning_candidates' => [
                'idx_learning_candidates_scope',
                'idx_learning_candidates_source',
            ],
        ];
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function requiredColumns(): array
    {
        return [
            'improvement_memory' => ['evidence_hash'],
            'learning_candidates' => ['tenant_id'],
        ];
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    private function normalizeImprovementRecord(array $record): array
    {
        $tenantId = $this->normText($record['tenant_id'] ?? '', 'default');
        $module = $this->normText($record['module'] ?? '', 'router');
        $problemType = $this->normText($record['problem_type'] ?? '', 'tool_failure');
        $severity = $this->normText($record['severity'] ?? '', 'medium');
        $suggestion = trim((string) ($record['suggestion'] ?? ''));
        $status = $this->normText($record['status'] ?? '', 'open');
        $createdAt = $this->createdAt($record['created_at'] ?? null);
        $frequency = max(1, (int) ($record['frequency'] ?? 1));
        $evidencePayload = $record['evidence'] ?? [];
        if (!is_array($evidencePayload)) {
            $evidencePayload = ['summary' => trim((string) $evidencePayload)];
        }
        $evidencePayload = $this->sortRecursive($evidencePayload);
        $evidenceJson = json_encode($evidencePayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($evidenceJson === false) {
            $evidenceJson = '{"summary":"serialization_failed"}';
        }

        return [
            'tenant_id' => $tenantId,
            'module' => $module,
            'problem_type' => $problemType,
            'frequency' => $frequency,
            'severity' => $severity,
            'evidence' => $evidenceJson,
            'evidence_hash' => sha1($tenantId . '|' . $module . '|' . $problemType . '|' . $evidenceJson),
            'suggestion' => $suggestion,
            'status' => $status,
            'created_at' => $createdAt,
        ];
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    private function normalizeCandidateRecord(array $record): array
    {
        $tenantId = $this->normText($record['tenant_id'] ?? '', 'default');
        $sourceMetric = $this->normText($record['source_metric'] ?? '', 'unknown');
        $module = $this->normText($record['module'] ?? '', 'router');
        $description = trim((string) ($record['description'] ?? ''));
        $frequency = max(0, (int) ($record['frequency'] ?? 0));
        $confidence = round(max(0.0, min(1.0, (float) ($record['confidence'] ?? 0.0))), 4);
        $reviewStatus = $this->normText($record['review_status'] ?? '', 'pending');
        $createdAt = $this->createdAt($record['created_at'] ?? null);
        $candidateId = trim((string) ($record['candidate_id'] ?? ''));
        if ($candidateId === '') {
            $candidateId = 'lc_' . substr(sha1($tenantId . '|' . $sourceMetric . '|' . $module . '|' . $description), 0, 20);
        }

        return [
            'candidate_id' => $candidateId,
            'tenant_id' => $tenantId,
            'source_metric' => $sourceMetric,
            'module' => $module,
            'description' => $description,
            'frequency' => $frequency,
            'confidence' => $confidence,
            'review_status' => $reviewStatus,
            'created_at' => $createdAt,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeImprovementRow(array $row): array
    {
        $decoded = json_decode((string) ($row['evidence'] ?? ''), true);
        return [
            'id' => (string) ($row['id'] ?? ''),
            'tenant_id' => (string) ($row['tenant_id'] ?? ''),
            'module' => (string) ($row['module'] ?? ''),
            'problem_type' => (string) ($row['problem_type'] ?? ''),
            'frequency' => (int) ($row['frequency'] ?? 0),
            'severity' => (string) ($row['severity'] ?? 'medium'),
            'evidence' => is_array($decoded) ? $decoded : ['raw' => (string) ($row['evidence'] ?? '')],
            'suggestion' => (string) ($row['suggestion'] ?? ''),
            'status' => (string) ($row['status'] ?? 'open'),
            'created_at' => (string) ($row['created_at'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeCandidateRow(array $row): array
    {
        return [
            'candidate_id' => (string) ($row['candidate_id'] ?? ''),
            'tenant_id' => (string) ($row['tenant_id'] ?? ''),
            'source_metric' => (string) ($row['source_metric'] ?? ''),
            'module' => (string) ($row['module'] ?? ''),
            'description' => (string) ($row['description'] ?? ''),
            'frequency' => (int) ($row['frequency'] ?? 0),
            'confidence' => (float) ($row['confidence'] ?? 0.0),
            'review_status' => (string) ($row['review_status'] ?? 'pending'),
            'created_at' => (string) ($row['created_at'] ?? ''),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function selectOne(string $sql, array $params): ?array
    {
        $rows = $this->selectRows($sql, $params);
        return is_array($rows[0] ?? null) ? (array) $rows[0] : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function selectRows(string $sql, array $params): array
    {
        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function normText($value, string $fallback = ''): string
    {
        $value = trim((string) $value);
        return $value !== '' ? $value : $fallback;
    }

    private function createdAt($value): string
    {
        $value = trim((string) $value);
        return $value !== '' ? $value : date('Y-m-d H:i:s');
    }

    private function since(int $days): string
    {
        $days = max(1, min(90, $days));
        return date('Y-m-d H:i:s', time() - ($days * 86400));
    }

    /**
     * @param array<string, mixed> $value
     * @return array<string, mixed>
     */
    private function sortRecursive(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->sortRecursive($item);
            }
        }
        ksort($value);
        return $value;
    }

    private function higherSeverity(string $current, string $candidate): string
    {
        $weight = [
            'low' => 1,
            'medium' => 2,
            'high' => 3,
            'critical' => 4,
        ];
        return ($weight[$candidate] ?? 2) > ($weight[$current] ?? 2) ? $candidate : $current;
    }
}
