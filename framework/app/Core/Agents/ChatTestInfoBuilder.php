<?php
declare(strict_types=1);

namespace App\Core\Agents;

final class ChatTestInfoBuilder
{
    public static function attach(
        array $reply,
        bool $testMode,
        array $telemetry,
        array $runtime = [],
        float $startedAt = 0.0
    ): array {
        if (!$testMode) {
            return $reply;
        }

        if ($startedAt > 0.0 && !isset($runtime['elapsed_ms'])) {
            $runtime['elapsed_ms'] = (int) round((microtime(true) - $startedAt) * 1000);
        }

        $data = is_array($reply['data'] ?? null) ? (array) $reply['data'] : [];
        $data['test_info'] = self::build($telemetry, $runtime);
        $reply['data'] = $data;

        return $reply;
    }

    public static function build(array $telemetry, array $runtime = []): array
    {
        $retrieval = is_array($telemetry['retrieval'] ?? null) ? (array) $telemetry['retrieval'] : [];
        $semanticIntentCollection = trim((string) ($telemetry['semantic_intent_collection'] ?? ''));
        $semanticIntentSource = trim((string) ($telemetry['semantic_intent_source'] ?? ''));
        $semanticIntentUsed = $semanticIntentSource === 'agent_training';
        $retrievalAttempted = (bool) ($retrieval['retrieval_attempted'] ?? false);
        $embeddingUsed = $retrievalAttempted || $semanticIntentUsed;
        $embeddingModel = $embeddingUsed ? (string) (getenv('EMBEDDING_MODEL') ?: 'gemini-embedding-001') : '';
        $embeddingDimensions = $embeddingUsed
            ? max(1, (int) (getenv('EMBEDDING_OUTPUT_DIMENSIONALITY') ?: 768))
            : 0;
        $collection = trim((string) ($retrieval['collection'] ?? ''));
        if ($collection === '' && $semanticIntentCollection !== '') {
            $collection = $semanticIntentCollection;
        }
        $memoryType = trim((string) ($retrieval['memory_type'] ?? ''));
        if ($memoryType === '' && $semanticIntentUsed) {
            $memoryType = trim((string) ($telemetry['semantic_intent_memory_type'] ?? ''));
        }
        $hits = 0;
        if ($retrieval !== []) {
            $hits = max(
                0,
                (int) ($telemetry['rag_result_count_raw'] ?? $retrieval['retrieval_result_count'] ?? $telemetry['rag_result_count'] ?? 0)
            );
        } elseif ($semanticIntentUsed) {
            $hits = max(0, (int) ($telemetry['semantic_intent_hit_count'] ?? 0));
        }
        $topK = 0;
        if ($retrieval !== []) {
            $topK = max(0, (int) ($retrieval['top_k'] ?? 0));
        } elseif ($semanticIntentUsed) {
            $topK = max(0, (int) ($telemetry['semantic_intent_top_k'] ?? 0));
        }
        $evidenceCount = 0;
        if ($retrieval !== []) {
            $evidenceCount = max(0, (int) ($telemetry['rag_result_count'] ?? 0));
        } elseif ($semanticIntentUsed) {
            $evidenceCount = $hits;
        }
        $providerUsed = trim((string) ($runtime['provider_used'] ?? $telemetry['provider_used'] ?? ''));
        if (strtolower($providerUsed) === 'llm') {
            $providerUsed = '';
        }
        $providerErrorsRaw = is_array($runtime['provider_errors'] ?? null) ? (array) $runtime['provider_errors'] : [];
        $providerStatusesRaw = is_array($runtime['provider_statuses'] ?? null) ? (array) $runtime['provider_statuses'] : [];
        $llmCalled = (bool) ($runtime['llm_called'] ?? $telemetry['llm_called'] ?? false);
        $llmProvider = trim((string) ($runtime['llm_provider_attempted'] ?? ''));
        if ($llmProvider === '' && $llmCalled) {
            $llmProvider = $providerUsed !== '' ? $providerUsed : 'llm';
        } elseif ($llmProvider === 'llm' && $providerUsed !== '') {
            $llmProvider = $providerUsed;
        } elseif (($llmProvider === '' || $llmProvider === 'llm') && $providerUsed === '' && $providerStatusesRaw !== []) {
            $firstProvider = array_key_first($providerStatusesRaw);
            if (is_string($firstProvider) && trim($firstProvider) !== '') {
                $llmProvider = $firstProvider;
            }
        }
        $providerUsedLabel = self::normalizeProviderLabel($providerUsed);
        $llmProviderLabel = self::normalizeProviderLabel($llmProvider);
        $llmModel = $llmCalled
            ? self::resolveLlmModel(
                is_array($runtime['llm_result'] ?? null) ? (array) $runtime['llm_result'] : [],
                $providerUsed
            )
            : '';

        return [
            'route_path' => trim((string) ($telemetry['route_path'] ?? '')) ?: 'unknown',
            'classification' => trim((string) ($telemetry['classification'] ?? '')) ?: 'unknown',
            'action' => trim((string) ($runtime['action'] ?? $telemetry['action'] ?? '')) ?: 'unknown',
            'resolved_locally' => (bool) ($runtime['resolved_locally'] ?? $telemetry['resolved_locally'] ?? false),
            'route_reason' => trim((string) ($telemetry['route_reason'] ?? '')) ?: 'unknown',
            'embedding_model' => $embeddingModel,
            'embedding_dimensions' => $embeddingDimensions,
            'embeddings_used' => $embeddingUsed,
            'vector_store' => $collection !== '' ? 'qdrant' : 'none',
            'collection' => $collection,
            'memory_type' => $memoryType !== '' ? $memoryType : 'none',
            'hits' => $hits,
            'top_k' => $topK,
            'evidence_count' => $evidenceCount,
            'top_score' => is_numeric($telemetry['retrieval_top_score'] ?? $telemetry['semantic_intent_similarity_score'] ?? null)
                ? (float) ($telemetry['retrieval_top_score'] ?? $telemetry['semantic_intent_similarity_score'])
                : 0.0,
            'llm_provider' => $llmCalled ? ($llmProviderLabel !== '' ? $llmProviderLabel : 'llm') : 'none',
            'llm_model' => $llmModel,
            'llm_error' => trim((string) ($runtime['llm_error'] ?? '')),
            'provider_errors' => self::normalizeProviderMap($providerErrorsRaw),
            'provider_statuses' => self::normalizeProviderMap($providerStatusesRaw),
            'elapsed_ms' => (int) ($runtime['elapsed_ms'] ?? 0),
            'semantic_fallback_used' => (bool) ($runtime['semantic_fallback_used'] ?? false),
            'agents_used' => self::collectAgentsUsed(
                $telemetry,
                $runtime,
                $providerUsedLabel !== ''
                    ? $providerUsedLabel
                    : ($llmProviderLabel !== '' ? $llmProviderLabel : $providerUsed),
                $llmCalled
            ),
        ];
    }

    private static function normalizeProviderLabel(string $provider): string
    {
        return match (strtolower(trim($provider))) {
            'deepseek' => 'deepseek_direct',
            default => strtolower(trim($provider)),
        };
    }

    private static function normalizeProviderMap(array $providerMap): array
    {
        $normalized = [];
        foreach ($providerMap as $provider => $value) {
            $normalized[self::normalizeProviderLabel((string) $provider)] = $value;
        }
        return $normalized;
    }

    private static function resolveLlmModel(array $llmResult, string $providerUsed): string
    {
        $raw = is_array($llmResult['raw'] ?? null) ? (array) $llmResult['raw'] : [];
        $rawData = is_array($raw['data'] ?? null) ? (array) $raw['data'] : [];
        foreach ([
            trim((string) ($llmResult['model'] ?? '')),
            trim((string) ($raw['model'] ?? '')),
            trim((string) ($rawData['model'] ?? '')),
        ] as $candidate) {
            if ($candidate !== '') {
                return $candidate;
            }
        }
        return match (strtolower(trim($providerUsed))) {
            'gemini' => (string) (getenv('GEMINI_MODEL') ?: 'gemini-2.5-flash-lite'),
            'deepseek' => (string) (getenv('DEEPSEEK_MODEL') ?: 'deepseek-chat'),
            'openrouter' => (string) (getenv('OPENROUTER_MODEL') ?: 'openrouter/free'),
            'claude' => (string) (getenv('CLAUDE_MODEL') ?: 'claude-3-5-haiku-latest'),
            'groq' => (string) (getenv('GROQ_MODEL') ?: ''),
            default => '',
        };
    }

    private static function collectAgentsUsed(array $telemetry, array $runtime, string $providerUsed, bool $llmCalled): array
    {
        $agentsUsed = [];
        $skillSelected = trim((string) ($telemetry['skill_selected'] ?? ''));
        $moduleUsed = trim((string) ($telemetry['module_used'] ?? ''));
        $skillGroup = trim((string) ($telemetry['skill_group'] ?? ''));
        $semanticIntentSource = trim((string) ($telemetry['semantic_intent_source'] ?? ''));
        $agentToolsAction = trim((string) ($telemetry['agent_tools_action'] ?? ''));

        if ($semanticIntentSource !== '') {
            $agentsUsed[] = $semanticIntentSource;
        }
        if ((bool) ($telemetry['rag_attempted'] ?? false)) {
            $agentsUsed[] = 'rag';
        }
        if ($skillSelected !== '' && $skillSelected !== 'none') {
            $agentsUsed[] = 'skill:' . $skillSelected;
        }
        if ($skillGroup !== '' && $skillGroup !== 'unknown') {
            $agentsUsed[] = 'skill_group:' . $skillGroup;
        }
        if ($moduleUsed !== '' && $moduleUsed !== 'none') {
            $agentsUsed[] = 'module:' . $moduleUsed;
        }
        if ($agentToolsAction !== '' && $agentToolsAction !== 'none') {
            $agentsUsed[] = 'agent_tools:' . $agentToolsAction;
        }
        if ($llmCalled) {
            $agentsUsed[] = 'llm:' . ($providerUsed !== '' ? $providerUsed : 'llm');
        }

        return array_values(array_unique(array_filter(
            array_map(static fn($v): string => trim((string) $v), $agentsUsed),
            static fn(string $v): bool => $v !== ''
        )));
    }
}
