<?php
// framework/app/Core/OCRService.php

namespace App\Core;

/**
 * OCRService
 * Simula y procesa la extracción de datos desde texto "leído" (OCR) de facturas físicas.
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
     * Procesa un archivo (en el futuro integraría Tesseract)
     */
    public function processFile(string $filePath): array
    {
        // Por ahora, simulamos que leemos el archivo y obtenemos texto.
        // En producción aquí se llamaría a 'tesseract $filePath stdout'
        return $this->extractInvoiceData("RESTAURANTE EL SABOR\nNIT: 900.123.456-1\nFACTURA NO: FE-123\nFECHA: 2026-04-07\nPRODUCTO 1 ... 50.000\nTOTAL: $50.000");
    }
}
