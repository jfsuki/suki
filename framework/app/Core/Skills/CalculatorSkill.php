<?php
// app/Core/Skills/CalculatorSkill.php

namespace App\Core\Skills;

final class CalculatorSkill
{
    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function handle(array $input, array $context = []): array
    {
        $op = trim((string) ($input['op'] ?? 'evaluate'));
        
        return match ($op) {
            'margin_price' => $this->calculateMarginPrice($input),
            'round_multiple' => $this->calculateRoundMultiple($input),
            'tax_projection' => $this->calculateTaxProjection($input),
            default => $this->evaluateExpression($input),
        };
    }

    private function calculateMarginPrice(array $input): array
    {
        $cost = (float) ($input['cost'] ?? 0);
        $margin = (float) ($input['margin'] ?? 0.25);
        if ($margin >= 1) $margin /= 100; // handle percentage
        
        $price = $cost / (1 - $margin);
        
        if (!empty($input['round_to'])) {
            $price = $this->roundTo($price, (int)$input['round_to']);
        }

        return ['price' => $price, 'cost' => $cost, 'margin' => $margin];
    }

    private function calculateRoundMultiple(array $input): array
    {
        $value = (float) ($input['value'] ?? 0);
        $multiple = (int) ($input['multiple'] ?? 5000);
        return ['value' => $this->roundTo($value, $multiple), 'original' => $value, 'multiple' => $multiple];
    }

    private function calculateTaxProjection(array $input): array
    {
        $total = (float) ($input['total'] ?? 0);
        $iva_rate = (float) ($input['iva_rate'] ?? 0.19);
        $ica_rate = (float) ($input['ica_rate'] ?? 0.007); // 7 per thousand standard retail
        
        $iva = $total * $iva_rate;
        $ica = $total * $ica_rate;
        
        return [
            'total' => $total,
            'iva' => $iva,
            'ica' => $ica,
            'final_tax' => $iva + $ica
        ];
    }

    private function evaluateExpression(array $input): array
    {
        $expression = (string) ($input['expression'] ?? '');
        // BASIC EVALUATION (SECURE)
        // In a real environment, use a math evaluator library
        return ['result' => 0, 'notice' => 'Custom logic evaluation waiting for engine bridge'];
    }

    private function roundTo(float $value, int $multiple): float
    {
        if ($multiple <= 0) return $value;
        return ceil($value / $multiple) * $multiple;
    }
}
