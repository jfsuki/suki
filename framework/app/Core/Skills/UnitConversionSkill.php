<?php
// framework/app/Core/Skills/UnitConversionSkill.php

declare(strict_types=1);

namespace App\Core\Skills;

class UnitConversionSkill
{
    /**
     * @param array{qty_purchase: float, factor: float, sales: float} $params
     */
    public function calculate(array $params): array
    {
        $qtyPurchase = (float) ($params['qty_purchase'] ?? 0);
        $factor = (float) ($params['factor'] ?? 1);
        $sales = (float) ($params['sales'] ?? 0);

        $stockTotalVenta = ($qtyPurchase * $factor) - $sales;
        
        return [
            'ok' => true,
            'stock_total_venta' => $stockTotalVenta,
            'unit_name' => $params['unit_name'] ?? 'unidad',
            'details' => sprintf(
                "Mapeo: %s rollos/cajas * %s = %s unidades base. Menos %s ventas = %s final.",
                $qtyPurchase, $factor, ($qtyPurchase * $factor), $sales, $stockTotalVenta
            )
        ];
    }
}
