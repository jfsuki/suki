<?php
// framework/app/Core/TrainingPortalController.php

declare(strict_types=1);

namespace App\Core;

use Exception;
use RuntimeException;

class TrainingPortalController
{
    private KnowledgeRegistryRepository $knowledgeRepo;
    private SemanticMemoryService $memoryService;

    public function __construct()
    {
        $this->knowledgeRepo = new KnowledgeRegistryRepository();
        $this->memoryService = new SemanticMemoryService();
    }

    /**
     * @param array $files $_FILES array
     * @param array $post $_POST array
     * @return array Response status and message
     */
    public function handleIngestion(array $files, array $post): array
    {
        try {
            if (!isset($files['knowledge_file']) || $files['knowledge_file']['error'] !== UPLOAD_ERR_OK) {
                return ['success' => false, 'message' => 'Archivo no proporcionado o error en la subida.'];
            }

            $sector = trim((string)($post['sector'] ?? 'general'));
            $trustScore = (float)($post['trust_score'] ?? 0.5);
            $sourceUri = trim((string)($post['source_uri'] ?? 'manual_upload'));
            
            $tmpFile = $files['knowledge_file']['tmp_name'];
            $content = file_get_contents($tmpFile);
            
            if ($content === false) {
                return ['success' => false, 'message' => 'No se pudo leer el contenido del archivo.'];
            }

            // 1. Validar contra el contrato KTC (Lógica simplificada para esta fase)
            $this->validateKtc($content);

            // 2. Vectorizar en Qdrant
            $result = $this->memoryService->ingestRawSectorKnowledge(
                $sector,
                $content,
                [
                    'source_uri' => $sourceUri,
                    'trust_score' => $trustScore,
                    'ingested_at' => date('Y-m-d H:i:s'),
                ]
            );

            return [
                'success' => true, 
                'message' => "Conocimiento de sector '$sector' ingerido correctamente. Qdrant Ref: " . ($result['operation_id'] ?? 'OK')
            ];

        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Error en la ingesta: ' . $e->getMessage()];
        }
    }

    private function validateKtc(string $content): void
    {
        // En una implementación real, aquí usaríamos el JSON Schema del KTC
        if (strlen($content) < 10) {
            throw new RuntimeException("El contenido del conocimiento es demasiado corto para ser válido.");
        }
    }
}
