<?php
declare(strict_types=1);

namespace App\Core\Agents;

use App\Core\LogSanitizer;

/**
 * Valida y sanitiza la respuesta del LLM antes de entregarla al usuario.
 * Detecta: leakage de system prompt, XSS, secretos expuestos, respuestas excesivamente largas.
 * Config en framework/data/response_validator_config.json.
 */
final class ResponseValidator
{
    private static ?array $config = null;

    /**
     * @param array{system_prompt?: string} $context
     * @return array{valid: bool, risk: string|null, sanitized: string}
     */
    public function validate(string $response, array $context = []): array
    {
        if (trim($response) === '') {
            return ['valid' => true, 'risk' => null, 'sanitized' => $response];
        }

        $cfg = $this->getConfig();

        // 1. Sanitizar secretos del texto (API keys, tokens, etc.)
        $sanitized = LogSanitizer::sanitizeString($response);

        // 2. Detectar leakage del system prompt
        $systemPrompt = (string) ($context['system_prompt'] ?? '');
        if ($systemPrompt !== '' && strlen($systemPrompt) > 40) {
            $excerpt = mb_substr($systemPrompt, 0, 50);
            if (str_contains($sanitized, $excerpt)) {
                return ['valid' => false, 'risk' => 'system_prompt_leak', 'sanitized' => $sanitized];
            }
        }

        // 3. Eliminar patrones XSS si los hay
        if ($this->hasXssPattern($sanitized)) {
            $sanitized = $this->stripXss($sanitized);
        }

        // 4. Truncar respuestas excesivamente largas
        $maxChars = (int) ($cfg['max_response_chars'] ?? 8000);
        if (strlen($sanitized) > $maxChars) {
            $sanitized = mb_substr($sanitized, 0, $maxChars) . "\n[respuesta truncada]";
        }

        return ['valid' => true, 'risk' => null, 'sanitized' => $sanitized];
    }

    private function hasXssPattern(string $text): bool
    {
        return (bool) preg_match('/<script\b[^>]*>.*?<\/script>/is', $text)
            || (bool) preg_match('/javascript\s*:/i', $text)
            || (bool) preg_match('/on\w+\s*=\s*["\']?[^"\'>\s]/i', $text);
    }

    private function stripXss(string $text): string
    {
        $out = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $text);
        $out = preg_replace('/javascript\s*:/i', '', is_string($out) ? $out : $text);
        $out = preg_replace('/on\w+\s*=\s*["\'][^"\']*["\']/i', '', is_string($out) ? $out : $text);
        return is_string($out) ? $out : $text;
    }

    private function getConfig(): array
    {
        if (self::$config === null) {
            $path = dirname(__DIR__, 3) . '/data/response_validator_config.json';
            $raw = file_exists($path) ? (string) file_get_contents($path) : '{}';
            $decoded = json_decode($raw, true);
            self::$config = is_array($decoded) ? $decoded : [];
        }
        return self::$config;
    }
}
