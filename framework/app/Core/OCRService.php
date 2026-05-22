<?php
declare(strict_types=1);
// framework/app/Core/OCRService.php

namespace App\Core;

/**
 * OCRService — extrae datos estructurados de facturas a partir de texto o archivo.
 */
class OCRService
{
    /**
     * Extrae datos estructurados de una cadena de texto (simulando salida de Tesseract).
     */
    public function extractInvoiceData(string $text): array
    {
        $data = [
            'total' => 0.0,
            'vendor' => 'Desconocido',
            'nit' => null,
            'date' => date('Y-m-d'),
            'invoice_number' => null,
            'items' => []
        ];

        // 1. Extraer Total ($ o TOTAL: )
        if (preg_match('/(?:TOTAL|VALOR|PAGO|NETO)[:\s]*\$?\s*([\d\.,]+)/i', $text, $matches)) {
            $data['total'] = (float)str_replace([',', '.'], '', $matches[1]);
            // Ajuste simple: si termina en decimales (ej 10.500) dividimos si es muy alto
            if ($data['total'] > 10000000) $data['total'] /= 100; 
        }

        // 2. Extraer NIT
        if (preg_match('/(?:NIT|C\.C|ID)[:\s]*([\d-]+)/i', $text, $matches)) {
            $data['nit'] = $matches[1];
        }

        // 3. Extraer Número de Factura
        if (preg_match('/(?:FACTURA|INV|DOC|No)[:\s]*#?\s*([A-Z0-9-]+)/i', $text, $matches)) {
            $data['invoice_number'] = $matches[1];
        }

        // 4. Vendor (Primera línea o palabras en mayúsculas)
        $lines = explode("\n", $text);
        if (!empty($lines[0])) {
            $data['vendor'] = trim($lines[0]);
        }

        return $data;
    }

    /**
     * Procesa un archivo real delegando en DocumentProcessorService.
     * Graceful degradation: retorna estructura vacía si el archivo no existe o no se puede procesar.
     */
    public function processFile(string $filePath): array
    {
        $processor = new \App\Core\DocumentProcessorService();
        $result    = $processor->process($filePath, '', 'analysis');

        if (isset($result['error'])) {
            return [
                'total'          => 0.0,
                'vendor'         => 'No identificado',
                'nit'            => null,
                'date'           => date('Y-m-d'),
                'invoice_number' => null,
                'items'          => [],
                'raw_text'       => $result['error'],
            ];
        }

        if (!empty($result['text'])) {
            $data             = $this->extractInvoiceData($result['text']);
            $data['raw_text'] = $result['text'];
            return $data;
        }

        // Excel/CSV: retornar primera fila como datos de factura aproximados
        if (!empty($result['rows'])) {
            $first = $result['rows'][0] ?? [];
            return [
                'total'          => (float) ($first['total'] ?? $first['valor'] ?? $first['TOTAL'] ?? 0),
                'vendor'         => (string) ($first['proveedor'] ?? $first['vendor'] ?? $first['nombre'] ?? 'Desconocido'),
                'nit'            => (string) ($first['nit'] ?? $first['NIT'] ?? ''),
                'date'           => date('Y-m-d'),
                'invoice_number' => (string) ($first['factura'] ?? $first['numero'] ?? ''),
                'items'          => $result['rows'],
                'raw_summary'    => $result['summary'],
            ];
        }

        return [
            'total'          => 0.0,
            'vendor'         => 'No identificado',
            'nit'            => null,
            'date'           => date('Y-m-d'),
            'invoice_number' => null,
            'items'          => [],
        ];
    }
}
