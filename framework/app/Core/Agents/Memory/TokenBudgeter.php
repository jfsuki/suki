<?php
declare(strict_types=1);
// app/Core/Agents/Memory/TokenBudgeter.php

namespace App\Core\Agents\Memory;

/**
 * TokenBudgeter
 * Inspirado en AutoGen: Calcula estimativamente (y rápido sin librerías pesadas) el 
 * tamaño en tokens de un string o payload antes de invocar a un LLM (Mistral/DeepSeek).
 * También provee métodos para recortar el payload (Text/Arrays) y asegurar
 * que la llamada no exceda el presupuesto ni queme tokens innecesariamente.
 */
class TokenBudgeter
{
    /**
     * Estima el uso de tokens.
     * La heurística estándar de la industria (OpenAI/Mistral) es que 1 token ≈ 4 caracteres en texto normal.
     * @param string $text
     * @return int
     */
    public function estimate(string $text): int
    {
        if (trim($text) === '') {
            return 0;
        }
        return (int) ceil(mb_strlen($text, 'UTF-8') / 4);
    }

    /**
     * Verifica si el texto entra en el presupuesto máximo.
     * @param string $text
     * @param int $maxTokens
     * @return bool
     */
    public function isWithinBudget(string $text, int $maxTokens): bool
    {
        return $this->estimate($text) <= $maxTokens;
    }

    /**
     * Recorta un string para que encaje dentro del presupuesto de tokens.
     * @param string $text El texto completo.
     * @param int $maxTokens El límite duro de tokens permitidos.
     * @param string $strategy 'end' (corta el final) o 'start' (corta el inicio, ej. historial viejo).
     * @return string
     */
    public function cropText(string $text, int $maxTokens, string $strategy = 'end'): string
    {
        $currentTokens = $this->estimate($text);
        if ($currentTokens <= $maxTokens) {
            return $text;
        }

        $indicator = '...[recortado por presupuesto]...';
        $indicatorTokens = $this->estimate($indicator);
        
        $allowedTokensForText = $maxTokens - $indicatorTokens;
        if ($allowedTokensForText <= 0) {
            return '';
        }

        $maxChars = $allowedTokensForText * 4;

        if ($strategy === 'end') {
            return mb_substr($text, 0, $maxChars, 'UTF-8') . $indicator;
        }

        // strategy === 'start' (usado para historiales de navegación donde lo reciente importa)
        return $indicator . mb_substr($text, -$maxChars, null, 'UTF-8');
    }

    /**
     * Especial para resultados de Tools/DB (ej. 100 productos de una base de datos).
     * Elimina iterativamente el último elemento hasta que el JSON quepa en el budget.
     *
     * @param array<int|string, mixed> $items
     * @param int $maxTokens
     * @return array<int|string, mixed>
     */
    public function cropJsonArray(array $items, int $maxTokens): array
    {
        $json = json_encode($items, JSON_UNESCAPED_UNICODE);
        if ($this->estimate($json ?: '') <= $maxTokens) {
            return $items;
        }

        $cropped = $items;
        while (!empty($cropped)) {
            array_pop($cropped); // Drop the last result
            $currentJson = json_encode($cropped, JSON_UNESCAPED_UNICODE);
            if ($this->estimate($currentJson ?: '') <= $maxTokens) {
                break;
            }
        }

        return $cropped;
    }

    /**
     * Validador de guarda para rechazar payloads. En lugar de recortar, lanza una excepción si excede.
     * @param string $text
     * @param int $maxTokens
     * @throws \RuntimeException
     */
    public function enforceBudget(string $text, int $maxTokens): void
    {
        $estimated = $this->estimate($text);
        if ($estimated > $maxTokens) {
            throw new \RuntimeException(
                sprintf('TokenBudgeter Alert: Payload excede el presupuesto rígido (%d > %d tokens). Riesgo de sobrecosto.', $estimated, $maxTokens)
            );
        }
    }
}
