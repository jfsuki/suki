<?php
declare(strict_types=1);
// framework/app/Core/Skills/MediaIngestionSkill.php

namespace App\Core\Skills;

/**
 * Skill especializado en la ingestión de archivos y medios durante la fase de construcción.
 */
class MediaIngestionSkill
{
    public function handle(string $text, array &$state, array $profile): array
    {
        $text = strtolower($text);
        error_log("MEDIA_INGESTION_SKILL: Handling text: $text");
        
        if (str_contains($text, 'excel') || str_contains($text, 'csv') || str_contains($text, 'tabla')) {
            return $this->handleDataImport($state);
        }
        
        if (str_contains($text, 'rut') || str_contains($text, 'pdf') || str_contains($text, 'xml')) {
            return $this->handleDocumentIngestion($state);
        }
        
        if (str_contains($text, 'foto') || str_contains($text, 'imagen')) {
            return $this->handleMediaIngestion($state);
        }

        return [
            'reply' => "Entendido. ¿Qué tipo de archivo deseas cargar? Puedo procesar Excel/CSV para datos, o PDF/RUT para configuración fiscal.",
            'state' => $state
        ];
    }

    private function handleDataImport(array &$state): array
    {
        $state['pending_media_action'] = 'data_import';
        return [
            'reply' => "¡Excelente! Para importar tus datos o inventario desde Excel o CSV, por favor usa el botón de adjuntar (clip) y selecciona el archivo.\n"
                     . "Una vez cargado, abriré el **Mapeador de Inventario** para emparejar tus columnas (SKU, Nombre, Precio, Stock) y cargar todo en un solo paso.",
            'state' => $state
        ];
    }

    private function handleDocumentIngestion(array &$state): array
    {
        $uploadedFile = $state['uploaded_file'] ?? $state['pending_upload'] ?? null;
        if (!$uploadedFile) {
            $state['pending_media_action'] = 'document_analysis';
            return [
                'reply' => "Adjunta el PDF o documento (RUT, factura, contrato). Extraeré los datos automáticamente.",
                'state' => $state,
            ];
        }

        $filePath  = is_array($uploadedFile) ? ($uploadedFile['path'] ?? '') : (string) $uploadedFile;
        $processor = new \App\Core\DocumentProcessorService();
        $result    = $processor->process($filePath, '', 'analysis');
        unset($state['uploaded_file'], $state['pending_upload']);

        return [
            'reply' => $result['summary'],
            'state' => $state,
            'data'  => $result,
        ];
    }

    private function handleMediaIngestion(array &$state): array
    {
        $uploadedFile = $state['uploaded_file'] ?? $state['pending_upload'] ?? null;

        if (!$uploadedFile) {
            $state['pending_media_action'] = 'awaiting_image_upload';
            return [
                'reply' => "Adjunta la imagen, factura o documento. Una vez cargado, extraeré los datos automáticamente.",
                'state' => $state,
            ];
        }

        $filePath  = is_array($uploadedFile) ? ($uploadedFile['path'] ?? '') : (string) $uploadedFile;
        $processor = new \App\Core\DocumentProcessorService();
        $result    = $processor->process($filePath, is_array($uploadedFile) ? ($uploadedFile['mime'] ?? '') : '', 'analysis');
        unset($state['uploaded_file'], $state['pending_upload']);

        if (isset($result['error'])) {
            return [
                'reply' => "No pude procesar el archivo: {$result['error']}",
                'state' => $state,
            ];
        }

        if ($result['content_type'] === 'ocr_text' || $result['content_type'] === 'text') {
            $ocr  = new \App\Core\OCRService();
            $data = $ocr->extractInvoiceData($result['text'] ?? '');
            return [
                'reply' => "He extraído los siguientes datos del documento:\n"
                         . "- Comercio: {$data['vendor']}\n"
                         . "- Valor: $" . number_format((float) $data['total']) . "\n"
                         . "- Documento No: {$data['invoice_number']}\n\n"
                         . "¿Deseas que registre este gasto en tu contabilidad?",
                'state' => $state,
                'data'  => $data,
            ];
        }

        if ($result['content_type'] === 'tabular') {
            $shownHeaders = implode(', ', array_slice($result['headers'] ?? [], 0, 6));
            return [
                'reply' => "He leído el archivo **{$result['metadata']['filename']}**:\n\n"
                         . "{$result['total_rows']} filas con columnas: {$shownHeaders}\n\n"
                         . "¿Qué quieres hacer con estos datos?\n"
                         . "- Importar al inventario\n"
                         . "- Analizar y mostrar resumen\n"
                         . "- Crear registros en una app",
                'state' => array_merge($state, ['pending_excel_data' => $result]),
                'data'  => $result,
            ];
        }

        return [
            'reply' => $result['summary'],
            'state' => $state,
            'data'  => $result,
        ];
    }
}
