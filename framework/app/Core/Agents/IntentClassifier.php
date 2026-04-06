<?php
// app/Core/Agents/IntentClassifier.php

declare(strict_types=1);

namespace App\Core\Agents;

use App\Core\GeminiEmbeddingService;
use App\Core\QdrantVectorStore;
use App\Core\LLM\LLMRouter;
use RuntimeException;

/**
 * Semantic intent classification engine.
 *
 * Layer 1: Qdrant cosine similarity (free, fast, <5ms).
 * Layer 2: Mistral JSON Fast Path (cheap, reliable).
 * Layer 3: PHP keyword fallback (hardcoded last resort).
 *
 * NEVER use preg_match or in_array for business intent classification.
 */
final class IntentClassifier
{
    private const QDRANT_CONFIDENCE_THRESHOLD = 0.65;
    private const LLM_ALLOWED_INTENTS = [
        'greeting', 'farewell', 'affirmation', 'negation', 'frustration',
        'create_request', 'business_description', 'scope_question',
        'pricing_question', 'crud', 'status', 'out_of_scope', 'faq', 'question',
        'solve_unit_conversion', 'builder_install_playbook',
    ];

    private ?GeminiEmbeddingService $embedder;
    private ?QdrantVectorStore $vectorStore;
    private ?LLMRouter $llmRouter;
    private string $tenantId;
    private array $lastClassificationTelemetry = [];

    public function __construct(
        ?GeminiEmbeddingService $embedder = null,
        ?QdrantVectorStore $vectorStore = null,
        ?LLMRouter $llmRouter = null,
        string $tenantId = 'system'
    ) {
        $this->embedder = $embedder;
        $this->vectorStore = $vectorStore;
        $this->llmRouter = $llmRouter;
        $this->tenantId = $tenantId !== '' ? $tenantId : 'system';
    }

    /**
     * Classify a user utterance. Returns [intent, score, layer].
     *
     * @return array{intent: string, score: float, layer: string}
     */
    public function classify(string $text): array
    {
        $res = $this->search($text);
        return [
            'intent' => $res['intent'],
            'score' => $res['score'],
            'layer' => $res['layer']
        ];
    }

    /**
     * Search for the most relevant semantic hit.
     * 
     * @return array{intent: string, score: float, layer: string, payload: array}
     */
    public function search(string $text): array
    {
        $this->lastClassificationTelemetry = [];
        $text = strtolower(trim($text));
        if ($text === '') {
            return ['intent' => 'faq', 'score' => 1.0, 'layer' => 'empty_guard', 'payload' => []];
        }

        // Layer 1: Qdrant semantic search
        $qdrant = $this->queryQdrant($text, true); // explicitly request full hit
        if ($qdrant !== null && $qdrant['score'] >= self::QDRANT_CONFIDENCE_THRESHOLD) {
            $this->logTraining($text, $qdrant['intent'], $qdrant['score'], 'skip');
            return [
                'intent' => $qdrant['intent'], 
                'score' => $qdrant['score'], 
                'layer' => 'qdrant',
                'payload' => $qdrant['payload'] ?? []
            ];
        }

        // Layer 2: LLM Fast Path
        $llm = $this->queryLLM($text);
        if ($llm !== null) {
            $this->logTraining($text, $llm['intent'], $llm['score'], 'pending');
            return ['intent' => $llm['intent'], 'score' => $llm['score'], 'layer' => 'llm', 'payload' => []];
        }

        // Layer 3: PHP keyword fallback
        $fallback = $this->keywordFallback($text);
        return ['intent' => $fallback, 'score' => 0.5, 'layer' => 'keyword_fallback', 'payload' => []];
    }

    public function isIntent(string $text, string $targetIntent): bool
    {
        $result = $this->classify($text);
        return $result['intent'] === $targetIntent;
    }

    public function getLastClassificationTelemetry(): array
    {
        return $this->lastClassificationTelemetry;
    }

    // --- Private helpers ---

    /** @return array{intent:string,score:float,payload:array}|null */
    private function queryQdrant(string $text, bool $includePayload = false): ?array
    {
        if ($this->embedder === null || $this->vectorStore === null) {
            return null;
        }
        try {
            $embedding = $this->embedder->embed($text, ['task_type' => 'RETRIEVAL_QUERY']);
            $must = [
                ['key' => 'memory_type', 'match' => ['value' => 'agent_training']],
            ];

            if ($this->tenantId !== 'system') {
                $must[] = [
                    'should' => [
                        ['key' => 'tenant_id', 'match' => ['value' => $this->tenantId]],
                        ['key' => 'tenant_id', 'match' => ['value' => 'system']],
                    ],
                ];
            } else {
                $must[] = [
                    'key' => 'tenant_id',
                    'match' => ['value' => 'system'],
                ];
            }

            $results = $this->vectorStore->query(
                $embedding['vector'],
                ['must' => $must],
                3,
                true
            );
            
            if (empty($results[0])) {
                return null;
            }
            $hit = $results[0];
            $score = (float) ($hit['score'] ?? 0.0);
            $intent = (string) ($hit['payload']['metadata']['intent'] ?? '');
            if ($intent === '' || $score < 0.1) {
                return null;
            }
            return [
                'intent' => $intent, 
                'score' => $score,
                'payload' => $includePayload ? ($hit['payload'] ?? []) : []
            ];
        } catch (\Throwable $e) {
            // Graceful degradation: Qdrant is down or misconfigured
            return null;
        }
    }

    /** @return array{intent:string,score:float}|null */
    private function queryLLM(string $text): ?array
    {
        if ($this->llmRouter === null) {
            return null;
        }
        try {
            $allowedStr = implode(', ', self::LLM_ALLOWED_INTENTS);
            $capsule = [
                'policy' => ['requires_strict_json' => true, 'max_output_tokens' => 60],
                'prompt_contract' => [
                    'TASK' => 'Classify the user message into one intent.',
                    'ALLOWED_INTENTS' => self::LLM_ALLOWED_INTENTS,
                    'USER_MESSAGE' => $text,
                    'OUTPUT_FORMAT' => ['intent' => 'string', 'confidence' => 'float 0-1'],
                    'RULES' => [
                        'Return ONLY valid JSON.',
                        'Intent must be one of: ' . $allowedStr,
                    ],
                ],
            ];
            $result = $this->llmRouter->chat($capsule, ['temperature' => 0.0]);
            
            if (isset($result['telemetry'])) {
                $this->lastClassificationTelemetry = $result['telemetry'];
            }

            $json = $result['json'] ?? null;
            if (!is_array($json)) {
                return null;
            }
            $intent = strtolower(trim((string) ($json['intent'] ?? '')));
            $confidence = isset($json['confidence']) && is_numeric($json['confidence'])
                ? (float) $json['confidence']
                : 0.6;
            if (!in_array($intent, self::LLM_ALLOWED_INTENTS, true)) {
                return null;
            }
            return ['intent' => $intent, 'score' => $confidence];
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function keywordFallback(string $text): string
    {
        if (str_contains($text, 'gracias') || str_contains($text, 'adios') || str_contains($text, 'chao')) {
            return 'farewell';
        }
        if (str_contains($text, 'hola') || str_contains($text, 'buenos dias') || str_contains($text, 'saludos')) {
            return 'greeting';
        }
        if (str_contains($text, 'crear') || str_contains($text, 'nuevo') || str_contains($text, 'agregar')) {
            return 'crud';
        }
        if (str_contains($text, 'estado') || str_contains($text, 'progreso') || str_contains($text, 'resumen')) {
            return 'status';
        }
        if (str_contains($text, 'ayuda') || str_contains($text, 'menu') || str_contains($text, 'opciones')) {
            return 'faq';
        }
        return 'question';
    }

    /**
     * Log classification to the auto-training SQLite for future Qdrant ingestion.
     */
    private function logTraining(string $text, string $intent, float $score, string $status): void
    {
        try {
            $dbPath = $this->resolveTrainingDbPath();
            $db = new \PDO('sqlite:' . $dbPath);
            $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_SILENT);
            $db->exec('CREATE TABLE IF NOT EXISTS training_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_text TEXT,
                intent_classified TEXT,
                llm_score REAL,
                status TEXT DEFAULT \'pending\',
                created_at TEXT
            )');
            $stmt = $db->prepare(
                'INSERT INTO training_log (user_text, intent_classified, llm_score, status, created_at)
                 VALUES (:text, :intent, :score, :status, :created)'
            );
            $stmt->execute([
                ':text' => $text,
                ':intent' => $intent,
                ':score' => $score,
                ':status' => $status,
                ':created' => date('c'),
            ]);
        } catch (\Throwable $e) {
            // Silently fail — logging is non-critical
        }
    }

    private function resolveTrainingDbPath(): string
    {
        $projectRoot = defined('PROJECT_ROOT') ? PROJECT_ROOT : dirname(__DIR__, 4) . '/project';
        $dir = $projectRoot . '/storage/meta';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        return $dir . '/intent_training_log.sqlite';
    }
}
