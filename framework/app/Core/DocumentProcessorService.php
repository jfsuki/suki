<?php
declare(strict_types=1);

namespace App\Core;

use PhpOffice\PhpSpreadsheet\IOFactory;
use Smalot\PdfParser\Parser as PdfParser;

/**
 * Enruta archivos al parser correcto según extensión.
 * Nunca inventa datos — devuelve error estructurado si no puede procesar.
 */
final class DocumentProcessorService
{
    private const MAX_ROWS_PREVIEW = 200;
    private const MAX_TEXT_CHARS   = 8000;
    private const SUPPORTED_EXCEL  = ['xlsx', 'xls', 'ods', 'csv'];
    private const SUPPORTED_PDF    = ['pdf'];
    private const SUPPORTED_IMAGE  = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    /**
     * @param string $context 'analysis' = lectura para LLM | 'import' = ingesta a DB
     * @return array{type:string,content_type:string,headers?:array,rows?:array,
     *               text?:string,page_count?:int,metadata:array,summary:string,error?:string}
     */
    public function process(string $filePath, string $mimeType = '', string $context = 'analysis'): array
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            return $this->errorResult("Archivo no encontrado o sin permisos: {$filePath}");
        }

        $ext      = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $filename = basename($filePath);
        $size     = (int) filesize($filePath);

        if (in_array($ext, self::SUPPORTED_EXCEL, true)) {
            return $this->processExcel($filePath, $filename, $size, $ext, $context);
        }

        if (in_array($ext, self::SUPPORTED_PDF, true)) {
            return $this->processPdf($filePath, $filename, $size);
        }

        if (in_array($ext, self::SUPPORTED_IMAGE, true)) {
            return $this->processImage($filePath, $filename, $size);
        }

        return $this->errorResult(
            "Tipo de archivo no soportado: .{$ext}. Formatos aceptados: Excel (.xlsx, .xls, .csv), PDF, imágenes (JPG, PNG)."
        );
    }

    private function processExcel(string $path, string $filename, int $size, string $ext, string $context): array
    {
        try {
            if ($ext === 'csv') {
                $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                $rows  = array_map('str_getcsv', $lines !== false ? $lines : []);
            } else {
                $spreadsheet = IOFactory::load($path);
                $sheet       = $spreadsheet->getActiveSheet();
                $rows        = $sheet->toArray(null, true, true, false);
            }

            if (empty($rows)) {
                return $this->errorResult("El archivo Excel está vacío.");
            }

            $headers  = array_map('strval', is_array($rows[0]) ? $rows[0] : []);
            $dataRows = array_slice($rows, 1);
            $total    = count($dataRows);
            $preview  = array_slice($dataRows, 0, self::MAX_ROWS_PREVIEW);

            $mappedRows = [];
            foreach ($preview as $row) {
                $mapped = [];
                foreach ($headers as $i => $header) {
                    $mapped[$header] = $row[$i] ?? null;
                }
                $mappedRows[] = $mapped;
            }

            $truncated = $total > self::MAX_ROWS_PREVIEW;
            $summary   = "Archivo Excel: {$filename}\n"
                       . "Columnas (" . count($headers) . "): " . implode(', ', array_filter($headers)) . "\n"
                       . "Filas de datos: {$total}" . ($truncated ? " (mostrando primeras " . self::MAX_ROWS_PREVIEW . ")" : "") . "\n"
                       . "Primera fila de ejemplo: " . json_encode($mappedRows[0] ?? [], JSON_UNESCAPED_UNICODE);

            return [
                'type'         => 'excel',
                'content_type' => 'tabular',
                'headers'      => $headers,
                'rows'         => $mappedRows,
                'total_rows'   => $total,
                'truncated'    => $truncated,
                'metadata'     => [
                    'filename'     => $filename,
                    'size'         => $size,
                    'ext'          => $ext,
                    'processed_at' => date('c'),
                ],
                'summary'      => $summary,
            ];
        } catch (\Throwable $e) {
            return $this->errorResult("No fue posible leer el Excel: " . $e->getMessage());
        }
    }

    private function processPdf(string $path, string $filename, int $size): array
    {
        try {
            $parser    = new PdfParser();
            $pdf       = $parser->parseFile($path);
            $pages     = $pdf->getPages();
            $pageCount = count($pages);
            $text      = '';

            foreach ($pages as $page) {
                $text .= $page->getText() . "\n\n";
                if (strlen($text) >= self::MAX_TEXT_CHARS) {
                    break;
                }
            }

            $text      = mb_substr(trim($text), 0, self::MAX_TEXT_CHARS);
            $truncated = $pageCount > 1 && strlen($text) >= self::MAX_TEXT_CHARS;

            $summary = "Archivo PDF: {$filename}\n"
                     . "Páginas: {$pageCount}\n"
                     . "Texto extraído (" . strlen($text) . " caracteres" . ($truncated ? ", truncado" : "") . "):\n"
                     . mb_substr($text, 0, 500) . (strlen($text) > 500 ? "..." : "");

            return [
                'type'         => 'pdf',
                'content_type' => 'text',
                'text'         => $text,
                'page_count'   => $pageCount,
                'truncated'    => $truncated,
                'metadata'     => [
                    'filename'     => $filename,
                    'size'         => $size,
                    'processed_at' => date('c'),
                ],
                'summary'      => $summary,
            ];
        } catch (\Throwable $e) {
            return $this->errorResult("No fue posible extraer texto del PDF: " . $e->getMessage());
        }
    }

    private function processImage(string $path, string $filename, int $size): array
    {
        // Tesseract path detection — Windows uses where, Unix uses which
        $whereCmd     = PHP_OS_FAMILY === 'Windows' ? 'where tesseract 2>NUL' : 'which tesseract 2>/dev/null';
        $tesseractBin = trim((string) shell_exec($whereCmd));
        if ($tesseractBin === '') {
            $tesseractBin = 'tesseract';
        }

        $versionOutput = (string) shell_exec(escapeshellarg($tesseractBin) . ' --version 2>&1');
        $hasTesseract  = str_contains($versionOutput, 'tesseract');

        if ($hasTesseract) {
            $tmpOut   = tempnam(sys_get_temp_dir(), 'suki_ocr_');
            $textFile = $tmpOut . '.txt';

            shell_exec(
                escapeshellarg($tesseractBin) . ' '
                . escapeshellarg($path) . ' '
                . escapeshellarg((string) $tmpOut)
                . ' -l spa 2>&1'
            );

            $text = is_file($textFile) ? trim((string) file_get_contents($textFile)) : '';
            @unlink((string) $tmpOut);
            @unlink($textFile);

            if ($text !== '') {
                $summary = "Imagen: {$filename}\nTexto extraído via OCR:\n" . mb_substr($text, 0, 400);
                return [
                    'type'         => 'image',
                    'content_type' => 'ocr_text',
                    'text'         => mb_substr($text, 0, self::MAX_TEXT_CHARS),
                    'metadata'     => [
                        'filename'     => $filename,
                        'size'         => $size,
                        'ocr_engine'   => 'tesseract',
                        'processed_at' => date('c'),
                    ],
                    'summary'      => $summary,
                ];
            }
        }

        return [
            'type'         => 'image',
            'content_type' => 'image_no_ocr',
            'text'         => null,
            'metadata'     => [
                'filename'     => $filename,
                'size'         => $size,
                'processed_at' => date('c'),
            ],
            'summary'      => "Imagen recibida: {$filename} ({$size} bytes). OCR no disponible en este servidor. "
                             . "Para extraer texto de imágenes instala Tesseract OCR.",
            'note'         => 'Para activar OCR de imágenes: instalar tesseract-ocr en el servidor.',
        ];
    }

    /** @return array{type:'error',content_type:'error',error:string,metadata:array,summary:string} */
    private function errorResult(string $message): array
    {
        return [
            'type'         => 'error',
            'content_type' => 'error',
            'error'        => $message,
            'metadata'     => ['processed_at' => date('c')],
            'summary'      => "Error al procesar documento: {$message}",
        ];
    }
}
