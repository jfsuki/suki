<?php
declare(strict_types=1);

namespace App\Core;

/**
 * AppFeedbackService
 *
 * Collects structured improvement signals from production agents and
 * surfaces them as improvement proposals for app owners.
 *
 * Agents call reportGap() when they encounter:
 *   - An intent they can't resolve with current skills
 *   - A missing field or table the user asked about
 *   - A recurring question pattern that should become a trained intent
 *   - A tool that would make their responses better
 *
 * Feedback is stored in AppMemoryService and in a local SQLite log.
 * The app owner can review proposals and approve improvements via chat.
 */
final class AppFeedbackService
{
    private AppMemoryService $memory;
    private string $logPath;

    public function __construct(?AppMemoryService $memory = null, ?string $logPath = null)
    {
        $this->memory  = $memory  ?? new AppMemoryService();
        $this->logPath = $logPath ?? $this->resolveLogPath();

        $dir = dirname($this->logPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
    }

    /**
     * Agent reports a gap or improvement signal.
     *
     * @param string $tenantId
     * @param string $appId
     * @param string $type     'missing_skill'|'missing_field'|'recurring_question'|'missing_tool'|'error'
     * @param string $summary  Human-readable description (what the user asked, what failed)
     * @param array  $meta     Additional context (intent, session_id, etc.)
     */
    public function reportGap(
        string $tenantId,
        string $appId,
        string $type,
        string $summary,
        array  $meta = []
    ): void {
        $entry = [
            'id'         => uniqid('fb_', true),
            'type'       => $type,
            'summary'    => $summary,
            'tenant_id'  => $tenantId,
            'app_id'     => $appId,
            'status'     => 'pending',
            'meta'       => $meta,
            'created_at' => date('Y-m-d H:i:s'),
        ];

        // Persist to app memory
        $this->memory->appendFeedback($tenantId, $appId, $entry);

        // Also log to SQLite for cross-tenant analytics
        $this->appendToLog($entry);
    }

    /**
     * Returns a human-readable summary of pending improvements for the app owner.
     */
    public function getPendingSummary(string $tenantId, string $appId): string
    {
        $items = $this->memory->getPendingImprovements($tenantId, $appId);
        if (empty($items)) {
            return "No hay sugerencias de mejora pendientes para esta app.";
        }

        $grouped = [];
        foreach ($items as $item) {
            $type = $item['type'] ?? 'general';
            $grouped[$type][] = $item['summary'];
        }

        $typeLabels = [
            'missing_skill'       => '🔧 Skills faltantes',
            'missing_field'       => '📋 Campos que los usuarios piden',
            'recurring_question'  => '❓ Preguntas frecuentes sin respuesta',
            'missing_tool'        => '🛠️ Herramientas que mejorarían la app',
            'error'               => '❌ Errores registrados',
        ];

        $lines = ["## Sugerencias de Mejora para tu App\n"];
        $lines[] = "Basadas en el uso real de tu sistema, tus agentes identificaron lo siguiente:\n";

        foreach ($grouped as $type => $summaries) {
            $label = $typeLabels[$type] ?? "📌 {$type}";
            $lines[] = "### {$label}";
            foreach (array_unique($summaries) as $s) {
                $lines[] = "- {$s}";
            }
            $lines[] = '';
        }

        $count = count($items);
        $lines[] = "---\n¿Quieres que aplique alguna de estas mejoras? Puedo hacerlo de forma segura sin afectar tus datos actuales.";
        $lines[] = "(Total: {$count} sugerencia(s) pendiente(s))";

        return implode("\n", $lines);
    }

    /**
     * Marks a feedback item as applied after improvement is deployed.
     */
    public function markApplied(string $tenantId, string $appId, string $feedbackId): void
    {
        $mem = $this->memory->load($tenantId, $appId);
        if (empty($mem)) {
            return;
        }
        foreach ($mem['feedback'] ?? [] as &$item) {
            if (($item['id'] ?? '') === $feedbackId) {
                $item['status']     = 'applied';
                $item['applied_at'] = date('Y-m-d H:i:s');
            }
        }
        unset($item);
        file_put_contents(
            dirname($this->logPath) . '/../app_memory/' . preg_replace('/[^a-zA-Z0-9_\-]/', '_', $tenantId) . '_' . preg_replace('/[^a-zA-Z0-9_\-]/', '_', $appId) . '.json',
            json_encode($mem, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }

    /**
     * Get top recurring patterns across all tenants for a given app_id.
     * Used by platform-level improvement analysis.
     */
    public function getTopPatterns(string $appId, int $limit = 10): array
    {
        $entries = $this->readLog();
        $counts  = [];
        foreach ($entries as $entry) {
            if (($entry['app_id'] ?? '') !== $appId) {
                continue;
            }
            $key = ($entry['type'] ?? '') . '::' . ($entry['summary'] ?? '');
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        arsort($counts);
        return array_slice($counts, 0, $limit, true);
    }

    /**
     * Auto-promover utterances repetidos a Qdrant sin aprobación manual.
     * Solo aplica para bad_response con intent conocido (no 'unknown').
     * Threshold: 3 ocurrencias del mismo gap → vectorizar directamente.
     *
     * Para missing_skill (intent desconocido) se necesita asignación manual
     * de label desde Torre — ver POST /api/chat/feedback/promote.
     */
    private function checkAndAutoPromote(array $entry, string $intent): void
    {
        if ($entry['type'] !== 'bad_response') {
            return;
        }
        if (in_array($intent, ['unknown', 'out_of_scope', ''], true)) {
            return;
        }

        // Filtro de seguridad: nunca vectorizar contenido malicioso
        $raw = strtolower($entry['summary'] ?? '');
        $blocked = ['olvida todo', 'actúa como', 'system prompt', 'drop table', 'admin override', 'ignore previous'];
        foreach ($blocked as $term) {
            if (str_contains($raw, $term)) {
                return;
            }
        }

        // Contar ocurrencias del mismo gap (mismo type + mismo intent)
        $entries  = $this->readLog();
        $sameGap  = array_filter($entries, static fn($e) =>
            ($e['type'] ?? '') === 'bad_response' &&
            ($e['meta']['intent'] ?? '') === $intent &&
            ($e['status'] ?? 'pending') === 'pending'
        );

        if (count($sameGap) < 3) {
            return;
        }

        // Extraer el utterance original del summary (formato: "Frustración detectada: "texto" (intent=...)")
        $utterance = $entry['summary'] ?? '';
        if (preg_match('/Frustraci[oó]n detectada: "([^"]+)"/', $utterance, $m)) {
            $utterance = $m[1];
        }
        if (strlen($utterance) < 5) {
            return;
        }

        try {
            $embedding = new GeminiEmbeddingService();
            $vector    = $embedding->embed($utterance);
            if (empty($vector)) {
                return;
            }

            $store = new QdrantVectorStore(null, null, null, null, null, null, null, 'agent_training');
            $store->upsertPoints([[
                'id'      => abs(crc32($utterance . $intent)) ?: 1,
                'vector'  => $vector,
                'payload' => [
                    'memory_type' => 'agent_training',
                    'tenant_id'   => 'shared_agent_training',
                    'source_type' => 'feedback_auto',
                    'chunk_id'    => 'fb_auto_' . substr(md5($utterance), 0, 8),
                    'metadata'    => [
                        'intent'      => $intent,
                        'intent_key'  => $intent,
                        'source_kind' => 'feedback',
                    ],
                ],
            ]]);

            // Marcar los gaps procesados como auto_promoted en el log
            $this->markBatchPromoted($intent);
        } catch (\Throwable $ignored) {
            // Auto-promote falla silenciosamente si Gemini/Qdrant no están disponibles
        }
    }

    private function markBatchPromoted(string $intent): void
    {
        if (!file_exists($this->logPath)) {
            return;
        }
        $lines  = file($this->logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $output = [];
        foreach ($lines as $line) {
            $e = json_decode($line, true);
            if (is_array($e) &&
                ($e['type'] ?? '') === 'bad_response' &&
                ($e['meta']['intent'] ?? '') === $intent &&
                ($e['status'] ?? 'pending') === 'pending'
            ) {
                $e['status']      = 'auto_promoted';
                $e['promoted_at'] = date('Y-m-d H:i:s');
            }
            $output[] = json_encode($e, JSON_UNESCAPED_UNICODE);
        }
        file_put_contents($this->logPath, implode("\n", $output) . "\n", LOCK_EX);
    }

    private function appendToLog(array $entry): void
    {
        $line = json_encode($entry, JSON_UNESCAPED_UNICODE) . "\n";
        file_put_contents($this->logPath, $line, FILE_APPEND | LOCK_EX);

        // Tras guardar, verificar si se alcanzó el umbral de auto-promoción
        $intent = (string) ($entry['meta']['intent'] ?? '');
        $this->checkAndAutoPromote($entry, $intent);
    }

    private function readLog(): array
    {
        if (!file_exists($this->logPath)) {
            return [];
        }
        $lines   = file($this->logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $entries = [];
        foreach ($lines as $line) {
            $data = json_decode($line, true);
            if (is_array($data)) {
                $entries[] = $data;
            }
        }
        return $entries;
    }

    private function resolveLogPath(): string
    {
        return dirname(__DIR__, 3) . '/project/storage/meta/app_feedback/feedback.jsonl';
    }
}
