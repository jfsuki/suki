<?php
declare(strict_types=1);

namespace App\Core;

/**
 * AgentKnowledgeService
 *
 * Gestiona el conocimiento propio de cada agente custom:
 * - Ingestión de documentos (PDF, Excel, texto) con validación de seguridad
 * - Vectorización en colección Qdrant privada del agente (agent_kb_{tenant}_{area})
 * - Protección contra training poisoning (OWASP LLM03)
 * - Rate limiting por tenant/agente
 * - Tenant isolation estricta en toda query
 *
 * La colección Qdrant se deriva SIEMPRE internamente via deriveCollection().
 * Nunca se acepta el nombre de colección desde payloads externos.
 */
final class AgentKnowledgeService
{
    private const MAX_DOC_BYTES       = 5 * 1024 * 1024;
    private const MAX_CHUNK_TOKENS    = 400;
    private const CHUNK_OVERLAP_CHARS = 100;
    private const MAX_DOCS_PER_DAY    = 20;
    private const COLLECTION_PREFIX   = 'agent_kb';

    // Protección OWASP LLM01 + LLM03: prompt injection / training poisoning
    private const INJECTION_PATTERNS = [
        '/ignore\s+(previous|above|all)\s+instructions?/i',
        '/system\s*:/i',
        '/<\|im_start\|>/i',
        '/<\|im_end\|>/i',
        '/\[INST\]/i',
        '/###\s*(system|instruction)/i',
        '/you\s+are\s+now\s+(a\s+)?(?:different|new|another)/i',
        '/forget\s+(everything|all|your)\s+(you|previous)/i',
        '/disregard\s+(all|previous|your)/i',
        '/act\s+as\s+(if\s+you\s+(are|were))/i',
        '/override\s+(system|prompt|instructions?)/i',
        '/jailbreak/i',
        '/DAN\s+mode/i',
    ];

    public function __construct(
        private readonly GeminiEmbeddingService $embedding,
        private readonly \PDO $mysql
    ) {}

    /**
     * Ingerir documento en la colección privada del agente.
     *
     * @return array{ok: bool, chunks_stored: int, warnings: string[], error?: string}
     */
    public function ingest(
        string $tenantId,
        string $agentId,
        string $area,
        string $documentText,
        string $sourceType = 'user_upload',
        string $docId = ''
    ): array {
        if ($tenantId === '' || $agentId === '') {
            return ['ok' => false, 'chunks_stored' => 0, 'warnings' => [], 'error' => 'tenant_id y agent_id requeridos'];
        }

        if (strlen($documentText) > self::MAX_DOC_BYTES) {
            return ['ok' => false, 'chunks_stored' => 0, 'warnings' => [], 'error' => 'Documento excede límite de 5MB'];
        }

        $sanitized = $this->sanitize($documentText);
        $warnings  = [];

        $injectionCheck = $this->detectInjection($sanitized);
        if (!$injectionCheck['safe']) {
            error_log("[AgentKnowledgeService] SECURITY: training poisoning attempt — tenant:{$tenantId} agent:{$agentId} pattern:{$injectionCheck['pattern']}");
            return ['ok' => false, 'chunks_stored' => 0, 'warnings' => [], 'error' => 'Contenido no permitido en entrenamiento de agentes.'];
        }

        if (!$this->checkRateLimit($tenantId, $agentId)) {
            return ['ok' => false, 'chunks_stored' => 0, 'warnings' => ['Límite de entrenamiento alcanzado (20 documentos/día)'], 'error' => 'rate_limit_exceeded'];
        }

        // Colección derivada internamente — nunca del payload externo
        $collection = self::deriveCollection($tenantId, $area);
        $docId      = $docId !== '' ? $docId : 'doc_' . bin2hex(random_bytes(8));

        $chunks = $this->chunk($sanitized);
        if (empty($chunks)) {
            return ['ok' => false, 'chunks_stored' => 0, 'warnings' => [], 'error' => 'Documento vacío después de sanitización'];
        }

        try {
            $store  = new QdrantVectorStore(null, null, $collection);
            $store->ensureCollection();
            $points = [];

            foreach ($chunks as $idx => $chunk) {
                $embedResult = $this->embedding->embed($chunk);
                $vector = is_array($embedResult['vector'] ?? null) ? (array) $embedResult['vector'] : [];
                if (empty($vector)) {
                    $warnings[] = "Chunk {$idx} no pudo vectorizarse (embedding vacío)";
                    continue;
                }
                // ID determinístico por tenant+doc+chunk — idempotente
                $pointId  = abs(crc32("{$tenantId}_{$docId}_{$idx}"));
                $points[] = [
                    'id'      => $pointId,
                    'vector'  => $vector,
                    'payload' => [
                        'tenant_id'   => $tenantId,
                        'agent_id'    => $agentId,
                        'area'        => strtolower($area),
                        'doc_id'      => $docId,
                        'chunk_idx'   => $idx,
                        'text'        => $chunk,
                        'source_type' => $sourceType,
                        'indexed_at'  => date('c'),
                    ],
                ];
            }

            if (!empty($points)) {
                $store->upsertPoints($points);
            }

            $this->recordIngestion($tenantId, $agentId, $docId, count($points));

            return ['ok' => true, 'chunks_stored' => count($points), 'warnings' => $warnings];

        } catch (\Throwable $e) {
            error_log("[AgentKnowledgeService] Error vectorizing: " . $e->getMessage());
            return ['ok' => false, 'chunks_stored' => 0, 'warnings' => $warnings, 'error' => 'Error vectorizando: ' . $e->getMessage()];
        }
    }

    /**
     * Estadísticas de conocimiento de un agente.
     */
    public function getStats(string $tenantId, string $agentId, string $area): array
    {
        $collection = self::deriveCollection($tenantId, $area);
        try {
            $store  = new QdrantVectorStore(null, null, $collection);
            $info   = $store->inspectCollection();
            // inspectCollection no filtra por tenant — retorna totales de la colección
            $docsToday = $this->getDocsToday($tenantId, $agentId);
            return [
                'collection'      => $collection,
                'collection_exists' => (bool) ($info['exists'] ?? false),
                'docs_today'      => $docsToday,
                'daily_limit'     => self::MAX_DOCS_PER_DAY,
                'remaining_today' => max(0, self::MAX_DOCS_PER_DAY - $docsToday),
            ];
        } catch (\Throwable $e) {
            return ['collection' => $collection, 'collection_exists' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Derivar nombre de colección — NUNCA del payload externo.
     * Solo alfanumérico + guion bajo para compatibilidad con Qdrant.
     */
    public static function deriveCollection(string $tenantId, string $area): string
    {
        $safeTenant = preg_replace('/[^a-z0-9_]/', '_', strtolower($tenantId));
        $safeArea   = preg_replace('/[^a-z0-9_]/', '_', strtolower($area));
        return self::COLLECTION_PREFIX . '_' . $safeTenant . '_' . $safeArea;
    }

    /** Sanitizar texto: strip HTML, normalizar whitespace, eliminar control chars */
    private function sanitize(string $text): string
    {
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);
        $text = preg_replace('/\n{4,}/', "\n\n\n", $text);
        $text = preg_replace('/[ \t]{4,}/', '   ', $text);
        return trim($text);
    }

    /** Detectar patrones de prompt injection / training poisoning */
    private function detectInjection(string $text): array
    {
        foreach (self::INJECTION_PATTERNS as $pattern) {
            if (preg_match($pattern, $text)) {
                return ['safe' => false, 'pattern' => $pattern];
            }
        }
        return ['safe' => true, 'pattern' => ''];
    }

    /**
     * Dividir en chunks de ~400 tokens con overlap.
     * Aproximación: 1 token ≈ 4 caracteres.
     *
     * @return string[]
     */
    private function chunk(string $text): array
    {
        $maxChars = self::MAX_CHUNK_TOKENS * 4;
        $overlap  = self::CHUNK_OVERLAP_CHARS;
        $chunks   = [];
        $len      = strlen($text);
        $start    = 0;

        while ($start < $len) {
            $end = min($start + $maxChars, $len);
            if ($end < $len) {
                $window = substr($text, $start, $end - $start);
                $cutPos = strrpos($window, "\n");
                if ($cutPos === false || $cutPos < (int) ($maxChars / 2)) {
                    $cutPos = strrpos($window, '. ');
                }
                if ($cutPos !== false && $cutPos > (int) ($maxChars / 3)) {
                    $end = $start + $cutPos + 1;
                }
            }
            $chunk = trim(substr($text, $start, $end - $start));
            if ($chunk !== '') {
                $chunks[] = $chunk;
            }
            $start = max($start + 1, $end - $overlap);
        }

        return $chunks;
    }

    private function checkRateLimit(string $tenantId, string $agentId): bool
    {
        return $this->getDocsToday($tenantId, $agentId) < self::MAX_DOCS_PER_DAY;
    }

    private function getDocsToday(string $tenantId, string $agentId): int
    {
        try {
            $this->ensureRateLimitTable();
            $stmt = $this->mysql->prepare(
                'SELECT COALESCE(SUM(docs_count), 0) FROM agent_training_rate_limits
                 WHERE tenant_id = ? AND agent_id = ? AND date_bucket = CURDATE()'
            );
            $stmt->execute([$tenantId, $agentId]);
            return (int) $stmt->fetchColumn();
        } catch (\Throwable) {
            return 0;
        }
    }

    private function recordIngestion(string $tenantId, string $agentId, string $docId, int $chunks): void
    {
        try {
            $this->ensureRateLimitTable();
            $this->mysql->prepare(
                'INSERT INTO agent_training_rate_limits
                    (tenant_id, agent_id, doc_id, chunks_count, docs_count, date_bucket, created_at)
                 VALUES (?, ?, ?, ?, 1, CURDATE(), NOW())
                 ON DUPLICATE KEY UPDATE
                    chunks_count = chunks_count + VALUES(chunks_count),
                    docs_count   = docs_count + 1'
            )->execute([$tenantId, $agentId, $docId, $chunks]);
        } catch (\Throwable $e) {
            error_log('[AgentKnowledgeService] Rate limit record error: ' . $e->getMessage());
        }
    }

    private function ensureRateLimitTable(): void
    {
        $this->mysql->exec("
            CREATE TABLE IF NOT EXISTS agent_training_rate_limits (
                id           INT AUTO_INCREMENT PRIMARY KEY,
                tenant_id    VARCHAR(100)  NOT NULL,
                agent_id     VARCHAR(150)  NOT NULL,
                doc_id       VARCHAR(150)  NOT NULL,
                chunks_count INT           DEFAULT 0,
                docs_count   INT           DEFAULT 0,
                date_bucket  DATE          NOT NULL,
                created_at   DATETIME      NOT NULL,
                UNIQUE KEY uq_rl_tenant_agent_doc (tenant_id, agent_id, doc_id),
                KEY idx_rl_tenant_agent_date (tenant_id, agent_id, date_bucket)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }
}
