<?php
// framework/app/Core/AccountingMessageParser.php

declare(strict_types=1);

namespace App\Core;

final class AccountingMessageParser
{
    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function parse(string $skillName, array $context): array
    {
        $message = trim((string) ($context['message_text'] ?? ''));
        $pairs = $this->extractKeyValuePairs($message);
        $telemetry = ['module_used' => 'accounting', 'action' => $skillName];

        return match ($skillName) {
            'accounting_record_entry' => $this->parseRecordEntry($message, $pairs, $telemetry),
            'accounting_balance_sheet' => ['kind' => 'command', 'action' => 'balance_sheet', 'telemetry' => $telemetry],
            'accounting_record_sale' => $this->parseRecordSale($message, $pairs, $telemetry),
            default => [
                'kind' => 'ask_user', 
                'reply' => 'No pude interpretar la operación contable.', 
                'telemetry' => $telemetry
            ],
        };
    }

    private function parseRecordEntry(string $message, array $pairs, array $telemetry): array
    {
        $monto = (float) ($pairs['monto'] ?? $pairs['amount'] ?? 0);
        $referencia = $pairs['referencia'] ?? $pairs['ref'] ?? 'Manual AI';
        
        if ($monto <= 0) {
            return [
                'kind' => 'ask_user',
                'reply' => 'Indica el monto para registrar el asiento contable.',
                'telemetry' => $telemetry
            ];
        }

        // Simulating a simple double entry if accounts are provided
        $debitAcc = (int) ($pairs['debe_cuenta'] ?? 1); // Default to 1 (Caja)
        $creditAcc = (int) ($pairs['haber_cuenta'] ?? 2); // Default to 2 (Ventas)

        return [
            'kind' => 'command',
            'action' => 'record_entry',
            'data' => [
                'fecha' => date('Y-m-d'),
                'referencia' => $referencia,
                'glosa' => $pairs['glosa'] ?? 'Asiento manual registrado por AI',
                'lines' => [
                    ['cuenta_id' => $debitAcc, 'debe' => $monto, 'haber' => 0, 'glosa_linea' => $referencia],
                    ['cuenta_id' => $creditAcc, 'debe' => 0, 'haber' => $monto, 'glosa_linea' => $referencia]
                ]
            ],
            'telemetry' => $telemetry
        ];
    }

    private function parseRecordSale(string $message, array $pairs, array $telemetry): array
    {
        $monto = (float) ($pairs['monto'] ?? $pairs['total'] ?? 0);
        $ref = $pairs['ref'] ?? $pairs['venta'] ?? 'SALE-' . time();

        if ($monto <= 0) {
            return [
                'kind' => 'ask_user',
                'reply' => 'Indica el total de la venta para contabilizar.',
                'telemetry' => $telemetry
            ];
        }

        return [
            'kind' => 'command',
            'action' => 'record_sale_accounting',
            'total' => $monto,
            'ref' => $ref,
            'telemetry' => $telemetry
        ];
    }

    private function extractKeyValuePairs(string $message): array
    {
        $pairs = [];
        preg_match_all('/([a-zA-Z_]+):\s*([a-zA-Z0-9.@_-]+)/', $message, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $pairs[strtolower($match[1])] = $match[2];
        }
        return $pairs;
    }
}
