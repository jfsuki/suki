<?php
// framework/app/Core/Skills/FiscalTaxSkill.php

declare(strict_types=1);

namespace App\Core\Skills;

class FiscalTaxSkill
{
    /**
     * @param array{base_amount: float, tax_type: string, rate: float, rounding: int} $params
     */
    public function calculate(array $params): array
    {
        $base = (float) ($params['base_amount'] ?? 0);
        $rate = (float) ($params['rate'] ?? 0); // Ej: 19 para IVA
        $rounding = (int) ($params['rounding'] ?? 0); // Ej: 500 para DIAN CO

        $taxAmount = $base * ($rate / 100);
        $total = $base + $taxAmount;

        // DIAN Rounding rule (example: round to nearest 500)
        if ($rounding > 0) {
            $total = round($total / $rounding) * $rounding;
        }

        return [
            'ok' => true,
            'base' => $base,
            'tax_rate' => $rate,
            'tax_amount' => $taxAmount,
            'total_rounded' => $total,
            'regulator' => 'DIAN_CO',
            'details' => sprintf(
                "Cálculo Fiscal: Base %s * %s%% = %s Tax. Total con redondeo %s (%s).",
                number_format($base, 2), $rate, number_format($taxAmount, 2), number_format($total, 2), $rounding
            )
        ];
    }
}
