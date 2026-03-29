<?php
declare(strict_types=1);

namespace App\Core;

/**
 * TokenBudgeter provides approximate token counting and limit enforcement.
 */
final class TokenBudgeter
{
    private const CHARS_PER_TOKEN = 4.0;
    
    private const MODELS_LIMITS = [
        'gpt-3.5-turbo' => 4096,
        'gpt-4'         => 8192,
        'deepseek-chat' => 32768,
        'claude-3-haiku' => 200000,
        'gemini-1.5-flash' => 1048576,
        'qwen-max'      => 32768,
    ];

    public function calculate(string $text): int
    {
        return (int) ceil(mb_strlen($text) / self::CHARS_PER_TOKEN);
    }

    public function estimate(string $text): int
    {
        return $this->calculate($text);
    }

    public function cropText(string $text, int $maxTokens, string $anchor = 'start'): string
    {
        $maxChars = (int) ($maxTokens * self::CHARS_PER_TOKEN);
        if (mb_strlen($text) <= $maxChars) return $text;
        
        if ($anchor === 'start') {
            return mb_substr($text, 0, $maxChars) . '...';
        }
        return '...' . mb_substr($text, -$maxChars);
    }

    public function check(string $text, string $model, int $safetyMargin = 500): bool
    {
        $limit = self::MODELS_LIMITS[$model] ?? 4096;
        return $this->calculate($text) < ($limit - $safetyMargin);
    }

    public function getRemaining(array $history, string $model): int
    {
        $total = 0;
        foreach ($history as $msg) {
            $content = is_array($msg) ? ($msg['content'] ?? $msg['text'] ?? '') : (string) $msg;
            $total += $this->calculate((string) $content);
        }
        $limit = self::MODELS_LIMITS[$model] ?? 4096;
        return max(0, $limit - $total);
    }

    public function shouldSummarize(array $history, string $model, float $threshold = 0.8): bool
    {
        $total = 0;
        foreach ($history as $msg) {
            $content = is_array($msg) ? ($msg['content'] ?? $msg['text'] ?? '') : (string) $msg;
            $total += $this->calculate((string) $content);
        }
        $limit = self::MODELS_LIMITS[$model] ?? 4096;
        return $total > ($limit * $threshold);
    }
}
