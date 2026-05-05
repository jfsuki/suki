<?php
declare(strict_types=1);

namespace App\Core\Agents;

/**
 * Detecta intentos de prompt injection antes de que el texto llegue al LLM.
 * Patrones cargados desde framework/data/injection_detection_config.json — cero hardcode.
 */
final class PromptInjectionDetector
{
    private static ?array $config = null;

    /**
     * Escanea $text en busca de patrones de injection.
     *
     * @return array{clean: bool, risk_score: float, matched_pattern: string|null}
     */
    public function scan(string $text): array
    {
        if (trim($text) === '') {
            return ['clean' => true, 'risk_score' => 0.0, 'matched_pattern' => null];
        }

        $cfg = $this->getConfig();
        $patterns = is_array($cfg['patterns'] ?? null) ? (array) $cfg['patterns'] : [];
        $threshold = (float) ($cfg['block_threshold'] ?? 0.7);
        $normalized = mb_strtolower($text);

        $maxScore = 0.0;
        $matched = null;

        foreach ($patterns as $entry) {
            $pattern = (string) ($entry['pattern'] ?? '');
            $score = (float) ($entry['risk_score'] ?? 0.5);
            $type = (string) ($entry['type'] ?? 'regex');

            if ($pattern === '') {
                continue;
            }

            $hit = false;
            if ($type === 'substring') {
                $hit = str_contains($normalized, mb_strtolower($pattern));
            } elseif ($type === 'regex') {
                $hit = (bool) @preg_match($pattern, $normalized);
            }

            if ($hit && $score > $maxScore) {
                $maxScore = $score;
                $matched = (string) ($entry['id'] ?? $pattern);
            }
        }

        return [
            'clean' => $maxScore < $threshold,
            'risk_score' => $maxScore,
            'matched_pattern' => $matched,
        ];
    }

    private function getConfig(): array
    {
        if (self::$config === null) {
            $path = dirname(__DIR__, 3) . '/data/injection_detection_config.json';
            $raw = file_exists($path) ? (string) file_get_contents($path) : '{}';
            $decoded = json_decode($raw, true);
            self::$config = is_array($decoded) ? $decoded : [];
        }
        return self::$config;
    }
}
