<?php

declare(strict_types=1);

namespace App\Core;

use Smalot\PdfParser\Parser;
use Throwable;

/**
 * Servicio avanzado para la extracción de datos de documentos fiscales (RUT).
 * Soporta inicialmente Colombia (DIAN) con estructura extensible para otros países.
 */
final class PdfExtractorService
{
    private const COLOMBIAN_RESPONSIBILITIES = [
        '05' => 'Impuesto sobre la renta y complementario - Régimen ordinario',
        '07' => 'Retención en la fuente a título de renta',
        '10' => 'Obligado aduanero',
        '13' => 'Gran contribuyente',
        '14' => 'Informante de exógena',
        '16' => 'Autorretenedor',
        '22' => 'Obligado a presentar declaración de ingresos y patrimonio',
        '37' => 'Obligado a facturar electrónicamente',
        '38' => 'Facturación electrónica - Validación previa',
        '42' => 'Obligado a llevar contabilidad',
        '47' => 'Régimen Simple de Tributación - SIMPLE',
        '48' => 'Impuesto sobre las ventas - IVA',
        '52' => 'Facturador electrónico',
        '55' => 'Informante de Beneficiarios Finales'
    ];

    /**
     * Extrae información estructurada de un PDF de RUT.
     * Detecta el país automáticamente para aplicar reglas específicas.
     */
    public function extractFromRut(string $pdfPath): array
    {
        try {
            $parser = new Parser();
            $pdf    = $parser->parseFile($pdfPath);
            $text   = $pdf->getText();

            // Detectar país
            $country = $this->detectCountry($text);

            if ($country === 'COLOMBIA') {
                return $this->parseColombianRut($text);
            }

            // Estructura base para otros países (entrenamiento futuro)
            return [
                'country' => $country,
                'nit' => $this->extractGenericNit($text),
                'full_name' => $this->extractGenericName($text),
                'location' => ['country' => $country],
                'is_pj' => $this->detectIfJuridical($text),
                'responsibilities' => [],
                'activities' => [],
                'text_sample' => substr($text, 0, 1000)
            ];

        } catch (Throwable $e) {
            return [
                'error' => $e->getMessage(),
                'success' => false
            ];
        }
    }

    private function detectCountry(string $text): string
    {
        if (stripos($text, 'DIAN') !== false || stripos($text, 'Colombia') !== false) {
            return 'COLOMBIA';
        }
        if (stripos($text, 'SAT') !== false || stripos($text, 'México') !== false) {
            return 'MEXICO';
        }
        return 'UNKNOWN';
    }

    private function parseColombianRut(string $text): array
    {
        $resps = $this->extractResponsibilities($text);
        $mappedResps = [];
        foreach ($resps as $code) {
            $mappedResps[$code] = self::COLOMBIAN_RESPONSIBILITIES[$code] ?? "Código $code";
        }

        return [
            'success' => true,
            'country' => 'COLOMBIA',
            'nit' => $this->extractNit($text),
            'full_name' => $this->extractFullName($text),
            'is_pj' => $this->detectIfJuridical($text),
            'email' => $this->extractEmail($text),
            'phone' => $this->extractPhone($text),
            'location' => $this->extractLocation($text),
            'activities' => $this->extractActivities($text),
            'responsibilities' => $resps,
            'responsibilities_desc' => $mappedResps
        ];
    }

    private function extractNit(string $text): string
    {
        // En el RUT DIAN el NIT suele ser un bloque de 10 dígitos (9+1 DV)
        // Evitamos el número de formulario (empieza por 149)
        if (preg_match_all('/(\d{10})/', $text, $matches)) {
            foreach ($matches[1] as $val) {
                // NITs comunes empiezan por 8 o 9 (PJ) o 1/7/4 (PN)
                if (!str_starts_with($val, '149')) {
                    return $val;
                }
            }
        }
        return '';
    }

    private function extractFullName(string $text): string
    {
        if (preg_match('/Persona jurídica\s+1\s+([\w\s]+?)(?=\n|\r)/i', $text, $m)) {
            return trim($m[1]);
        }
        if (preg_match('/35\. Razón social\s+([\w\s&]+?)(?=\n|\r)/i', $text, $m)) {
            return trim($m[1]);
        }
        return '';
    }

    private function extractEmail(string $text): string
    {
        if (preg_match('/[a-zA-Z0-9._%+-]+@suki\.com\.co/i', $text, $m)) {
             return strtolower($m[0]);
        }
        if (preg_match('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', $text, $m)) {
            return strtolower($m[0]);
        }
        return '';
    }

    private function extractPhone(string $text): string
    {
        if (preg_match('/(3\d{9})/', $text, $m)) {
            return $m[1];
        }
        return '';
    }

    private function extractLocation(string $text): array
    {
        $loc = [
            'country' => 'COLOMBIA',
            'department' => '',
            'city' => '',
            'address' => ''
        ];

        // Intento de captura de departamento y ciudad (Patrón: COLOMBIA\s+Dpto\s+Ciudad)
        if (preg_match('/COLOMBIA\s+(?:\d+)?\s?([\w\s]+?)\s+(\d+)\s+([\w\s]+?)(?=\s+\d+|$)/i', $text, $m)) {
            $loc['department'] = trim($m[1]);
            $loc['city'] = trim($m[3]);
        }

        if (preg_match('/((?:CL|CR|AV|CRA|CALLE|CARRERA)\s+[\d\s\w#-]+)/i', $text, $m)) {
            $loc['address'] = trim($m[1]);
        }

        return $loc;
    }

    private function extractActivities(string $text): array
    {
        $act = ['primary' => '', 'secondary' => '', 'others' => []];
        
        // El código CIIU suele ser de 4 dígitos. Casilla 46 (Principal)
        if (preg_match_all('/(\d{4})202\d{5}/', $text, $matches)) {
            $act['primary'] = $matches[1][0] ?? '';
            $act['secondary'] = $matches[1][1] ?? '';
        }

        return $act;
    }

    private function extractResponsibilities(string $text): array
    {
        $codes = [];
        // Busca códigos de 2 dígitos que tengan una descripción de responsabilidad después
        if (preg_match_all('/(\d{2})\s*-\s*[\w\s]+/i', $text, $matches)) {
            foreach ($matches[1] as $c) {
                if (isset(self::COLOMBIAN_RESPONSIBILITIES[$c])) {
                    $codes[] = $c;
                }
            }
        }
        
        // Fallback: búsqueda directa de códigos conocidos
        if (empty($codes)) {
            foreach (array_keys(self::COLOMBIAN_RESPONSIBILITIES) as $c) {
                if (preg_match('/\b' . $c . '\b/', $text)) {
                    $codes[] = $c;
                }
            }
        }

        return array_values(array_unique($codes));
    }

    private function detectIfJuridical(string $text): bool
    {
        return stripos($text, 'Persona jurídica') !== false || stripos($text, 'SAS') !== false || stripos($text, 'S.A.') !== false;
    }

    private function extractGenericNit(string $text): string { return ''; }
    private function extractGenericName(string $text): string { return ''; }
}
