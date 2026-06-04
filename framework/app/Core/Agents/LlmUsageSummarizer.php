<?php
declare(strict_types=1);

namespace App\Core\Agents;

final class LlmUsageSummarizer
{
    public static function buildSummary(string $tenantId): array
    {
        $tenantId = trim($tenantId) !== '' ? trim($tenantId) : 'default';
        $safeTenant = preg_replace('/[^a-zA-Z0-9_\\-]/', '_', $tenantId) ?? 'default';
        $dir = PROJECT_ROOT . '/storage/tenants/' . trim($safeTenant, '_') . '/telemetry';
        $file = $dir . '/' . date('Y-m-d') . '.log.jsonl';

        if (!is_file($file)) {
            return [
                'reply' => 'Hoy no hay consumo IA registrado. Requests IA: 0, Tokens: 0.',
                'data' => [
                    'llm_requests' => 0,
                    'prompt_tokens' => 0,
                    'completion_tokens' => 0,
                    'total_tokens' => 0,
                    'providers' => [],
                    'source' => $file,
                ],
            ];
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $llmRequests = 0;
        $promptTokens = 0;
        $completionTokens = 0;
        $totalTokens = 0;
        $providers = [];

        foreach ($lines as $line) {
            $row = json_decode((string) $line, true);
            if (!is_array($row)) {
                continue;
            }
            $provider = (string) ($row['provider_used'] ?? '');
            if ($provider === '') {
                continue;
            }
            $llmRequests++;
            $providers[$provider] = (int) ($providers[$provider] ?? 0) + 1;
            $usage = self::normalizeUsage((array) ($row['usage'] ?? []));
            $promptTokens += (int) ($usage['prompt_tokens'] ?? 0);
            $completionTokens += (int) ($usage['completion_tokens'] ?? 0);
            $totalTokens += (int) ($usage['total_tokens'] ?? 0);
        }

        arsort($providers);
        $providerText = empty($providers)
            ? 'sin llamadas a proveedor'
            : implode(', ', array_map(
                static fn(string $name, int $count): string => $name . ':' . $count,
                array_keys($providers),
                array_values($providers)
            ));

        $reply = 'Consumo IA de hoy:'
            . "\n- Requests IA: " . $llmRequests
            . "\n- Prompt tokens: " . $promptTokens
            . "\n- Completion tokens: " . $completionTokens
            . "\n- Total tokens: " . $totalTokens
            . "\n- Proveedores: " . $providerText;

        return [
            'reply' => $reply,
            'data' => [
                'llm_requests' => $llmRequests,
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $completionTokens,
                'total_tokens' => $totalTokens,
                'providers' => $providers,
                'source' => $file,
            ],
        ];
    }

    public static function normalizeUsage(array $usage): array
    {
        $prompt = (int) ($usage['prompt_tokens']
            ?? $usage['promptTokenCount']
            ?? $usage['input_tokens']
            ?? $usage['inputTokenCount']
            ?? 0);
        $completion = (int) ($usage['completion_tokens']
            ?? $usage['candidatesTokenCount']
            ?? $usage['output_tokens']
            ?? $usage['outputTokenCount']
            ?? 0);
        $total = (int) ($usage['total_tokens']
            ?? $usage['totalTokenCount']
            ?? ($prompt + $completion));

        return [
            'prompt_tokens' => max(0, $prompt),
            'completion_tokens' => max(0, $completion),
            'total_tokens' => max(0, $total),
        ];
    }
}
