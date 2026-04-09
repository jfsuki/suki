<?php
// framework/app/Core/Skills/MediaIngestionSkill.php

namespace App\Core\Skills;

/**
 * Skill especializado en la ingestion de archivos y medios durante la fase de construccion.
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
        $state['pending_media_action'] = 'document_analysis';
        return [
            'reply' => "Entendido. Si tienes tu RUT en PDF o facturas en XML/PDF, adjúntalos ahora.\n"
                     . "Extraeré la información fiscal (Régimen, CIIU) automáticamente para configurar tu app.",
            'state' => $state
        ];
    }

    private function handleMediaIngestion(array &$state): array
    {
        $state['pending_media_action'] = 'media_gallery';
        
        // Simulación de OCR si es un recibo/factura
        $ocr = new \App\Core\OCRService();
        $data = $ocr->processFile('uploaded_file.jpg'); // Mock file result

        return [
            'reply' => "He detectado una imagen de factura o recibo. He extraído los siguientes datos:\n"
                     . "- Comercio: {$data['vendor']}\n"
                     . "- Valor: $".number_format($data['total'])."\n"
                     . "- Documento No: {$data['invoice_number']}\n\n"
                     . "¿Deseas que registre este gasto automáticamente en tu contabilidad?",
            'state' => $state,
            'data' => $data
        ];
    }
}
