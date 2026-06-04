<?php

declare(strict_types=1);

// framework/app/Core/CriticAgent.php

namespace App\Core;

use App\Core\LLM\LLMRouter;

/**
 * Lightweight critic that validates LLM responses for financial/stock/date accuracy.
 *
 * Only activates when the response text contains monetary amounts, stock quantities,
 * or fiscal dates.  Uses the cheapest available LLM (Groq → DeepSeek → Mistral).
 * Always fails silently — never throws or blocks the main response flow.
 */
final class CriticAgent
{
    private ?LLMRouter $llmRouter;

    public function __construct(?LLMRouter $llmRouter = null)
    {
        $this->llmRouter = $llmRouter;
    }

    /**
     * @param array<mixed>  $lastToolResults  Raw tool result array from the tool-call loop.
     * @return array{valid: bool, correctedText: string, reason: string}
     */
    public function evaluate(string $llmText, array $lastToolResults, string $tenantId): array
    {
        $passthrough = ['valid' => true, 'correctedText' => '', 'reason' => ''];

        if (!$this->shouldActivate($llmText)) {
            return $passthrough;
        }

        if (empty($lastToolResults)) {
            return $passthrough;
        }

        try {
            $router = $this->llmRouter ?? new LLMRouter();
            $toolJson = json_encode($lastToolResults, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            // Keep the prompt under 200 tokens — critic model only needs a yes/no answer.
            $prompt = "Herramienta devolvió: {$toolJson}\nRespuesta agente: {$llmText}\n"
                    . '¿Son consistentes los números/fechas? Responde SOLO: VÁLIDO o INVÁLIDO: {razón breve}';

            $result = $router->complete(
                [['role' => 'user', 'content' => $prompt]],
                [
                    'provider_mode' => $this->cheapestProvider(),
                    'max_tokens'    => 80,
                    'temperature'   => 0.0,
                    'tenant_id'     => $tenantId,
                ]
            );

            $text = trim((string) ($result['text'] ?? ''));

            if (stripos($text, 'INVÁLIDO') !== false || stripos($text, 'INVALIDO') !== false) {
                $reason = $this->extractReason($text);
                return [
                    'valid'         => false,
                    'correctedText' => $this->buildCorrectedText($llmText, $lastToolResults, $reason),
                    'reason'        => $reason,
                ];
            }

            return $passthrough;
        } catch (\Throwable $ignored) {
            // LLM timeout or API error — do not break the main flow.
            return $passthrough;
        }
    }

    /**
     * Only trigger the critic when the text contains monetarily or operationally
     * sensitive information that could mislead the user if hallucinated.
     */
    private function shouldActivate(string $text): bool
    {
        // Monetary amounts
        if (preg_match('/\$[\s]?[\d,]+|[\d]+[\.,]\d{2}\s*(COP|USD|EUR|pesos?)/ui', $text)) {
            return true;
        }
        // Stock quantities
        if (preg_match('/\b\d+\s*(unidades?|uds?|items?|productos?|stock)\b/ui', $text)) {
            return true;
        }
        // Fiscal dates (Colombian format: dd/mm/yyyy or yyyy-mm-dd)
        if (preg_match('/\b\d{1,2}\/\d{1,2}\/\d{4}\b|\b\d{4}-\d{2}-\d{2}\b/', $text)) {
            return true;
        }

        return false;
    }

    /**
     * Resolve cheapest provider available based on env keys present.
     * Groq is typically free-tier; DeepSeek is cheaper than Mistral for short tasks.
     */
    private function cheapestProvider(): string
    {
        if (!empty(getenv('GROQ_API_KEY'))) {
            return 'groq';
        }
        if (!empty(getenv('DEEPSEEK_API_KEY'))) {
            return 'deepseek';
        }
        return 'mistral';
    }

    private function extractReason(string $criticText): string
    {
        if (preg_match('/(?:INVÁLIDO|INVALIDO)\s*:\s*(.+)/ui', $criticText, $m)) {
            return trim((string) ($m[1] ?? ''));
        }
        return 'Inconsistencia detectada entre datos de herramienta y respuesta';
    }

    /**
     * Build a corrected text that appends real values from tool results as a note,
     * without deleting the original agent prose.
     *
     * @param array<mixed> $toolResults
     */
    private function buildCorrectedText(string $originalText, array $toolResults, string $reason): string
    {
        $summary = json_encode($toolResults, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        return $originalText
            . "\n\n_(Nota: Se detectó una inconsistencia con los datos registrados — {$reason}."
            . " Datos verificados: " . mb_substr($summary, 0, 400) . ")_";
    }
}
