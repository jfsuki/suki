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
            'db/migrations/sqlite/20260310_013_learning_promotion_pipeline.sql'
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
            $reviewStatus = $normalized['review_status'];
            if (
                (!array_key_exists('review_status', $candidate) || $reviewStatus === 'pending')
                && in_array((string) ($existing['review_status'] ?? ''), ['approved', 'rejected'], true)
            ) {
                $reviewStatus = (string) ($existing['review_status'] ?? 'pending');
            }
            $processedAt = array_key_exists('processed_at', $candidate)
                ? $normalized['processed_at']
                : ($existing['processed_at'] ?? null);
            $proposalId = array_key_exists('proposal_id', $candidate)
                ? $normalized['proposal_id']
                : ($existing['proposal_id'] ?? null);
            $stmt = $this->db->prepare(
                'UPDATE learning_candidates
                 SET source_metric = :source_metric,
                     module = :module,
                     problem_type = :problem_type,
                     severity = :severity,
                     evidence = :evidence,
                     description = :description,
                     frequency = :frequency,
                     confidence = :confidence,
                     review_status = :review_status,
                     processed_at = :processed_at,
                     proposal_id = :proposal_id,
                     sentiment_score = :sentiment_score,
                     feedback_type = :feedback_type
                 WHERE tenant_id = :tenant_id AND candidate_id = :candidate_id'
            );
            $stmt->execute([
                ':source_metric' => $normalized['source_metric'],
                ':module' => $normalized['module'],
                ':problem_type' => $normalized['problem_type'],
                ':severity' => $normalized['severity'],
                ':evidence' => $normalized['evidence'],
                ':description' => $normalized['description'],
                ':frequency' => $normalized['frequency'],
                ':confidence' => $normalized['confidence'],
                ':review_status' => $reviewStatus,
                ':processed_at' => $processedAt,
                ':proposal_id' => $proposalId,
                ':sentiment_score' => $normalized['sentiment_score'],
                ':feedback_type' => $normalized['feedback_type'],
                ':tenant_id' => $normalized['tenant_id'],
                ':candidate_id' => $normalized['candidate_id'],
            ]);
        } else {
            $stmt = $this->db->prepare(
                'INSERT INTO learning_candidates (
                    candidate_id, tenant_id, source_metric, module, problem_type, severity, evidence, description, frequency, confidence, review_status, processed_at, proposal_id, sentiment_score, feedback_type, created_at
                 ) VALUES (
                    :candidate_id, :tenant_id, :source_metric, :module, :problem_type, :severity, :evidence, :description, :frequency, :confidence, :review_status, :processed_at, :proposal_id, :sentiment_score, :feedback_type, :created_at
                 )'
            );
            $stmt->execute([
                ':candidate_id' => $normalized['candidate_id'],
                ':tenant_id' => $normalized['tenant_id'],
                ':source_metric' => $normalized['source_metric'],
                ':module' => $normalized['module'],
                ':problem_type' => $normalized['problem_type'],
                ':severity' => $normalized['severity'],
                ':evidence' => $normalized['evidence'],
                ':description' => $normalized['description'],
                ':frequency' => $normalized['frequency'],
                ':confidence' => $normalized['confidence'],
                ':review_status' => $normalized['review_status'],
                ':processed_at' => $normalized['processed_at'],
                ':proposal_id' => $normalized['proposal_id'],
                ':sentiment_score' => $normalized['sentiment_score'],
                ':feedback_type' => $normalized['feedback_type'],
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
    public function listLearningCandidates(string $tenantId, string $reviewStatus = '', int $limit = 10, bool $onlyUnprocessed = false): array
    {
        $limit = max(1, min(100, $limit));
        $where = ['tenant_id = :tenant_id'];
        $params = [':tenant_id' => $this->normText($tenantId, 'default')];
        if (trim($reviewStatus) !== '') {
            $where[] = 'review_status = :review_status';
            $params[':review_status'] = trim($reviewStatus);
        }
        if ($onlyUnprocessed) {
            $where[] = 'processed_at IS NULL';
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
     * @return array<int, array<string, mixed>>
     */
    public function listApprovedCandidatesForPromotion(?string $tenantId = null, int $limit = 25): array
    {
        $limit = max(1, min(200, $limit));
        $where = ['review_status = :review_status', 'processed_at IS NULL'];
        $params = [':review_status' => 'approved'];
        if ($tenantId !== null && trim($tenantId) !== '') {
            $where[] = 'tenant_id = :tenant_id';
            $params[':tenant_id'] = $this->normText($tenantId, 'default');
        }

        $sql = 'SELECT * FROM learning_candidates
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY confidence DESC, frequency DESC, created_at ASC
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
     * @return array<string, mixed>|null
     */
    public function markLearningCandidateProcessed(string $tenantId, string $candidateId, ?string $proposalId = null): ?array
    {
        if ($candidateId === '') {
            return null;
        }

        $stmt = $this->db->prepare(
            'UPDATE learning_candidates
             SET processed_at = :processed_at,
                 proposal_id = :proposal_id
             WHERE tenant_id = :tenant_id AND candidate_id = :candidate_id'
        );
        $stmt->execute([
            ':processed_at' => date('Y-m-d H:i:s'),
            ':proposal_id' => $proposalId,
            ':tenant_id' => $this->normText($tenantId, 'default'),
            ':candidate_id' => $candidateId,
        ]);

        return $this->findLearningCandidate($tenantId, $candidateId);
    }

    /**
     * Promote an approved candidate to specific knowledge (e.g. Semantic Memory)
     * 
     * @return array<string, mixed>|null
     */
    public function promoteToKnowledge(string $tenantId, string $candidateId, string $targetMemoryType = 'sector_knowledge'): ?array
    {
        $candidate = $this->findLearningCandidate($tenantId, $candidateId);
        if (!$candidate) {
            return null;
        }

        if ((string) ($candidate['review_status'] ?? 'pending') !== 'approved') {
            throw new RuntimeException('CANDIDATE_NOT_APPROVED_FOR_PROMOTION');
        }

        return $this->markLearningCandidateProcessed($tenantId, $candidateId, 'promoted_' . $targetMemoryType);
    }

    /**
     * @param array<string, mixed> $proposal
     * @return array<string, mixed>
     */
    public function insertImprovementProposal(array $proposal): array
    {
        $normalized = $this->normalizeProposalRecord($proposal);
        $stmt = $this->db->prepare(
            'INSERT INTO improvement_proposals (
                id, tenant_id, candidate_id, proposal_type, module, title, description, evidence, frequency, confidence, priority, status, created_at
             ) VALUES (
                :id, :tenant_id, :candidate_id, :proposal_type, :module, :title, :description, :evidence, :frequency, :confidence, :priority, :status, :created_at
             )'
        );
        $stmt->execute([
            ':id' => $normalized['id'],
            ':tenant_id' => $normalized['tenant_id'],
            ':candidate_id' => $normalized['candidate_id'],
            ':proposal_type' => $normalized['proposal_type'],
            ':module' => $normalized['module'],
            ':title' => $normalized['title'],
            ':description' => $normalized['description'],
            ':evidence' => $normalized['evidence'],
            ':frequency' => $normalized['frequency'],
            ':confidence' => $normalized['confidence'],
            ':priority' => $normalized['priority'],
            ':status' => $normalized['status'],
            ':created_at' => $normalized['created_at'],
        ]);

        $saved = $this->findImprovementProposal($normalized['id']);
        if (!is_array($saved)) {
            throw new RuntimeException('IMPROVEMENT_PROPOSAL_INSERT_FETCH_FAILED');
        }

        return $saved;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findImprovementProposal(string $proposalId): ?array
    {
        if ($proposalId === '') {
            return null;
        }
        $stmt = $this->db->prepare('SELECT * FROM improvement_proposals WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $proposalId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $this->normalizeProposalRow($row) : null;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function listImprovementProposals(array $filters = [], int $limit = 25): array
    {
        $limit = max(1, min(200, $limit));
        $where = ['1 = 1'];
        $params = [];

        if (array_key_exists('tenant_id', $filters)) {
            $tenantId = $filters['tenant_id'];
            if ($tenantId === null || trim((string) $tenantId) === '') {
                $where[] = 'tenant_id IS NULL';
            } else {
                $where[] = 'tenant_id = :tenant_id';
                $params[':tenant_id'] = $this->normText($tenantId, 'default');
            }
        }
        foreach (['module', 'proposal_type', 'candidate_id', 'priority'] as $key) {
            if (!array_key_exists($key, $filters) || $filters[$key] === null || trim((string) $filters[$key]) === '') {
                continue;
            }
            $where[] = $key . ' = :' . $key;
            $params[':' . $key] = trim((string) $filters[$key]);
        }
        if (is_array($filters['statuses'] ?? null) && $filters['statuses'] !== []) {
            $placeholders = [];
            foreach (array_values((array) $filters['statuses']) as $index => $status) {
                $key = ':status_' . $index;
                $placeholders[] = $key;
                $params[$key] = trim((string) $status);
            }
            if ($placeholders !== []) {
                $where[] = 'status IN (' . implode(', ', $placeholders) . ')';
            }
        }

        $sql = 'SELECT * FROM improvement_proposals
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY created_at DESC, id DESC
                LIMIT :limit';
        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map(fn(array $row): array => $this->normalizeProposalRow($row), $rows);
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
                problem_type TEXT NOT NULL DEFAULT "",
                severity TEXT NOT NULL DEFAULT "medium",
                evidence TEXT NULL,
                description TEXT NOT NULL,
                frequency INTEGER NOT NULL DEFAULT 0,
                confidence REAL NOT NULL DEFAULT 0,
                review_status TEXT NOT NULL DEFAULT "pending",
                processed_at TEXT NULL,
                proposal_id TEXT NULL,
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

        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS improvement_proposals (
                id TEXT PRIMARY KEY,
                tenant_id TEXT NULL,
                candidate_id TEXT NOT NULL,
                proposal_type TEXT NOT NULL,
                module TEXT NOT NULL,
                title TEXT NOT NULL,
                description TEXT NOT NULL,
                evidence TEXT NOT NULL DEFAULT "",
                frequency INTEGER NOT NULL DEFAULT 0,
                confidence REAL NOT NULL DEFAULT 0,
                priority TEXT NOT NULL DEFAULT "medium",
                status TEXT NOT NULL DEFAULT "open",
                created_at TEXT NOT NULL
            )'
        );
        $this->db->exec(
            'CREATE INDEX IF NOT EXISTS idx_improvement_proposals_scope
             ON improvement_proposals (tenant_id, module, proposal_type, status, created_at)'
        );
        $this->db->exec(
            'CREATE INDEX IF NOT EXISTS idx_improvement_proposals_candidate
             ON improvement_proposals (candidate_id, status)'
        );

        $this->ensureColumn('learning_candidates', 'problem_type', 'ALTER TABLE learning_candidates ADD COLUMN problem_type TEXT NOT NULL DEFAULT ""');
        $this->ensureColumn('learning_candidates', 'severity', 'ALTER TABLE learning_candidates ADD COLUMN severity TEXT NOT NULL DEFAULT "medium"');
        $this->ensureColumn('learning_candidates', 'evidence', 'ALTER TABLE learning_candidates ADD COLUMN evidence TEXT NULL');
        $this->ensureColumn('learning_candidates', 'processed_at', 'ALTER TABLE learning_candidates ADD COLUMN processed_at TEXT NULL');
        $this->ensureColumn('learning_candidates', 'proposal_id', 'ALTER TABLE learning_candidates ADD COLUMN proposal_id TEXT NULL');
        $this->ensureColumn('learning_candidates', 'sentiment_score', 'ALTER TABLE learning_candidates ADD COLUMN sentiment_score REAL DEFAULT 0.5');
        $this->ensureColumn('learning_candidates', 'feedback_type', 'ALTER TABLE learning_candidates ADD COLUMN feedback_type TEXT DEFAULT "general"');
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
                problem_type VARCHAR(64) NOT NULL DEFAULT '',
                severity VARCHAR(16) NOT NULL DEFAULT 'medium',
                evidence TEXT NULL,
                description TEXT NOT NULL,
                frequency INT UNSIGNED NOT NULL DEFAULT 0,
                confidence DECIMAL(6,4) NOT NULL DEFAULT 0,
                review_status VARCHAR(16) NOT NULL DEFAULT 'pending',
                processed_at DATETIME NULL,
                proposal_id VARCHAR(64) NULL,
                sentiment_score DECIMAL(5,4) NOT NULL DEFAULT 0.5,
                feedback_type VARCHAR(32) NOT NULL DEFAULT 'general',
                created_at DATETIME NOT NULL,
                PRIMARY KEY (candidate_id),
                KEY idx_learning_candidates_scope (tenant_id, review_status, created_at),
                KEY idx_learning_candidates_source (tenant_id, source_metric, module)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS improvement_proposals (
                id VARCHAR(64) NOT NULL,
                tenant_id VARCHAR(120) NULL,
                candidate_id VARCHAR(64) NOT NULL,
                proposal_type VARCHAR(64) NOT NULL,
                module VARCHAR(120) NOT NULL,
                title VARCHAR(255) NOT NULL,
                description TEXT NOT NULL,
                evidence TEXT NOT NULL,
                frequency INT UNSIGNED NOT NULL DEFAULT 0,
                confidence DECIMAL(6,4) NOT NULL DEFAULT 0,
                priority VARCHAR(16) NOT NULL DEFAULT 'medium',
                status VARCHAR(16) NOT NULL DEFAULT 'open',
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY idx_improvement_proposals_scope (tenant_id, module, proposal_type, status, created_at),
                KEY idx_improvement_proposals_candidate (candidate_id, status)
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
        return ['improvement_memory', 'learning_candidates', 'improvement_proposals'];
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
            'improvement_proposals' => [
                'idx_improvement_proposals_scope',
                'idx_improvement_proposals_candidate',
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
            'learning_candidates' => ['tenant_id', 'problem_type', 'severity', 'evidence', 'processed_at', 'proposal_id'],
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
        $problemType = $this->normText($record['problem_type'] ?? '', 'tool_failure');
        $severity = $this->normText($record['severity'] ?? '', 'medium');
        $evidencePayload = $record['evidence'] ?? [];
        if (!is_array($evidencePayload)) {
            $evidencePayload = ['summary' => trim((string) $evidencePayload)];
        }
        $evidencePayload = $this->sortRecursive($evidencePayload);
        $evidenceJson = json_encode($evidencePayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($evidenceJson === false) {
            $evidenceJson = '{"summary":"serialization_failed"}';
        }
        $description = trim((string) ($record['description'] ?? ''));
        $frequency = max(0, (int) ($record['frequency'] ?? 0));
        $confidence = round(max(0.0, min(1.0, (float) ($record['confidence'] ?? 0.0))), 4);
        $reviewStatus = $this->normText($record['review_status'] ?? '', 'pending');
        $processedAt = trim((string) ($record['processed_at'] ?? ''));
        if ($processedAt === '') {
            $processedAt = null;
        }
        $proposalId = trim((string) ($record['proposal_id'] ?? ''));
        if ($proposalId === '') {
            $proposalId = null;
        }
        $sentimentScore = round(max(0.0, min(1.0, (float) ($record['sentiment_score'] ?? 0.5))), 4);
        $feedbackType = $this->normText($record['feedback_type'] ?? '', 'general');
        $createdAt = $this->createdAt($record['created_at'] ?? null);
        $candidateId = trim((string) ($record['candidate_id'] ?? ''));
        if ($candidateId === '') {
            $candidateId = 'lc_' . substr(sha1($tenantId . '|' . $problemType . '|' . $sourceMetric . '|' . $module . '|' . $description), 0, 20);
        }

        return [
            'candidate_id' => $candidateId,
            'tenant_id' => $tenantId,
            'source_metric' => $sourceMetric,
            'module' => $module,
            'problem_type' => $problemType,
            'severity' => $severity,
            'evidence' => $evidenceJson,
            'description' => $description,
            'frequency' => $frequency,
            'confidence' => $confidence,
            'review_status' => $reviewStatus,
            'processed_at' => $processedAt,
            'proposal_id' => $proposalId,
            'sentiment_score' => $sentimentScore,
            'feedback_type' => $feedbackType,
            'created_at' => $createdAt,
        ];
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    private function normalizeProposalRecord(array $record): array
    {
        $tenantId = $record['tenant_id'] ?? null;
        $tenantId = $tenantId === null || trim((string) $tenantId) === '' ? null : trim((string) $tenantId);
        $candidateId = $this->normText($record['candidate_id'] ?? '', 'unknown_candidate');
        $proposalType = $this->normText($record['proposal_type'] ?? '', 'dataset_proposal');
        $module = $this->normText($record['module'] ?? '', 'router');
        $title = trim((string) ($record['title'] ?? ''));
        $description = trim((string) ($record['description'] ?? ''));
        $evidencePayload = $record['evidence'] ?? [];
        if (!is_array($evidencePayload)) {
            $evidencePayload = ['summary' => trim((string) $evidencePayload)];
        }
        $evidencePayload = $this->sortRecursive($evidencePayload);
        $evidenceJson = json_encode($evidencePayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($evidenceJson === false) {
            $evidenceJson = '{"summary":"serialization_failed"}';
        }
        $frequency = max(0, (int) ($record['frequency'] ?? 0));
        $confidence = round(max(0.0, min(1.0, (float) ($record['confidence'] ?? 0.0))), 4);
        $priority = $this->normText($record['priority'] ?? '', 'medium');
        $status = $this->normText($record['status'] ?? '', 'open');
        $createdAt = $this->createdAt($record['created_at'] ?? null);
        $proposalId = trim((string) ($record['id'] ?? ''));
        if ($proposalId === '') {
            $proposalId = 'ip_' . substr(sha1((string) $tenantId . '|' . $proposalType . '|' . $module . '|' . $title), 0, 20);
        }

        return [
            'id' => $proposalId,
            'tenant_id' => $tenantId,
            'candidate_id' => $candidateId,
            'proposal_type' => $proposalType,
            'module' => $module,
            'title' => $title,
            'description' => $description,
            'evidence' => $evidenceJson,
            'frequency' => $frequency,
            'confidence' => $confidence,
            'priority' => $priority,
            'status' => $status,
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
        $decoded = json_decode((string) ($row['evidence'] ?? ''), true);
        return [
            'candidate_id' => (string) ($row['candidate_id'] ?? ''),
            'tenant_id' => (string) ($row['tenant_id'] ?? ''),
            'source_metric' => (string) ($row['source_metric'] ?? ''),
            'module' => (string) ($row['module'] ?? ''),
            'problem_type' => (string) ($row['problem_type'] ?? ''),
            'severity' => (string) ($row['severity'] ?? 'medium'),
            'evidence' => is_array($decoded) ? $decoded : [],
            'description' => (string) ($row['description'] ?? ''),
            'frequency' => (int) ($row['frequency'] ?? 0),
            'confidence' => (float) ($row['confidence'] ?? 0.0),
            'review_status' => (string) ($row['review_status'] ?? 'pending'),
            'processed_at' => ($row['processed_at'] ?? null) !== null ? (string) $row['processed_at'] : null,
            'proposal_id' => ($row['proposal_id'] ?? null) !== null ? (string) $row['proposal_id'] : null,
            'sentiment_score' => (float) ($row['sentiment_score'] ?? 0.5),
            'feedback_type' => (string) ($row['feedback_type'] ?? 'general'),
            'created_at' => (string) ($row['created_at'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeProposalRow(array $row): array
    {
        $decoded = json_decode((string) ($row['evidence'] ?? ''), true);
        return [
            'id' => (string) ($row['id'] ?? ''),
            'tenant_id' => ($row['tenant_id'] ?? null) !== null ? (string) $row['tenant_id'] : null,
            'candidate_id' => (string) ($row['candidate_id'] ?? ''),
            'proposal_type' => (string) ($row['proposal_type'] ?? ''),
            'module' => (string) ($row['module'] ?? ''),
            'title' => (string) ($row['title'] ?? ''),
            'description' => (string) ($row['description'] ?? ''),
            'evidence' => is_array($decoded) ? $decoded : ['raw' => (string) ($row['evidence'] ?? '')],
            'frequency' => (int) ($row['frequency'] ?? 0),
            'confidence' => (float) ($row['confidence'] ?? 0.0),
            'priority' => (string) ($row['priority'] ?? 'medium'),
            'status' => (string) ($row['status'] ?? 'open'),
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

    private function ensureColumn(string $table, string $column, string $alterSql): void
    {
        if ($this->tableHasColumn($table, $column)) {
            return;
        }
        $this->db->exec($alterSql);
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
