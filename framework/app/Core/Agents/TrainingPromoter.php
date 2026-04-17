<?php
declare(strict_types=1);
// app/Core/Agents/TrainingPromoter.php

namespace App\Core\Agents;

use App\Core\GeminiEmbeddingService;
use App\Core\QdrantVectorStore;

/**
 * Promotes pending intent classifications from SQLite training_log into Qdrant.
 *
 * Called lazily from IntentClassifier::logTraining() whenever the pending
 * count exceeds PROMOTE_THRESHOLD. Safe to call concurrently — uses
 * SQLite UPDATE to claim rows atomically before ingesting.
 */
final class TrainingPromoter
{
    private const PROMOTE_THRESHOLD = 10;
    private const BATCH_SIZE = 25;

    private \PDO $db;
    private ?GeminiEmbeddingService $embedder;
    private ?QdrantVectorStore $vectorStore;
    private string $tenantId;

    public function __construct(
        \PDO $db,
        ?GeminiEmbeddingService $embedder = null,
        ?QdrantVectorStore $vectorStore = null,
        string $tenantId = 'system'
    ) {
        $this->db = $db;
        $this->embedder = $embedder;
        $this->vectorStore = $vectorStore;
        $this->tenantId = $tenantId !== '' ? $tenantId : 'system';
    }

    public function countPending(): int
    {
        $stmt = $this->db->query("SELECT COUNT(*) FROM training_log WHERE status = 'pending'");
        if (!$stmt) return 0;
        return (int) $stmt->fetchColumn();
    }

    /**
     * Promote if pending count exceeds threshold.
     * Returns number of rows promoted (0 if below threshold or no embedder).
     */
    public function promoteIfReady(): int
    {
        if ($this->countPending() < self::PROMOTE_THRESHOLD) {
            return 0;
        }
        return $this->promote();
    }

    /**
     * Claim and ingest up to BATCH_SIZE pending rows into Qdrant.
     */
    public function promote(): int
    {
        if ($this->embedder === null || $this->vectorStore === null) {
            return 0;
        }

        // Claim rows atomically
        $batchId = 'batch_' . bin2hex(random_bytes(6));
        $this->db->prepare(
            "UPDATE training_log SET status = :batch WHERE status = 'pending' AND id IN
             (SELECT id FROM training_log WHERE status = 'pending' ORDER BY id LIMIT :limit)"
        )->execute([':batch' => $batchId, ':limit' => self::BATCH_SIZE]);

        $rows = $this->db->prepare(
            "SELECT id, user_text, intent_classified, llm_score, tenant_id FROM training_log WHERE status = :batch"
        );
        $rows->execute([':batch' => $batchId]);
        $records = $rows->fetchAll(\PDO::FETCH_ASSOC);

        if (empty($records)) {
            return 0;
        }

        $promoted = 0;
        foreach ($records as $row) {
            try {
                $tenantId = ($row['tenant_id'] ?? '') ?: $this->tenantId;
                $embedding = $this->embedder->embed($row['user_text'], ['task_type' => 'RETRIEVAL_DOCUMENT']);
                $point = [
                    'id'     => bin2hex(random_bytes(8)),
                    'vector' => $embedding['vector'],
                    'payload' => [
                        'text'        => $row['user_text'],
                        'memory_type' => 'agent_training',
                        'tenant_id'   => $tenantId,
                        'metadata'    => [
                            'intent'    => $row['intent_classified'],
                            'llm_score' => (float) $row['llm_score'],
                            'source'    => 'auto_promoted',
                        ],
                    ],
                ];
                $this->vectorStore->upsertPoints([$point]);
                $this->db->prepare(
                    "UPDATE training_log SET status = 'promoted' WHERE id = :id"
                )->execute([':id' => $row['id']]);
                $promoted++;
            } catch (\Throwable $e) {
                // Mark as failed so next batch can skip
                $this->db->prepare(
                    "UPDATE training_log SET status = 'error' WHERE id = :id"
                )->execute([':id' => $row['id']]);
                error_log('[TrainingPromoter] Failed row ' . $row['id'] . ': ' . $e->getMessage());
            }
        }

        return $promoted;
    }
}
