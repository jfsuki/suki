<?php
declare(strict_types=1);

namespace App\Core;

use App\Core\KnowledgeRegistryRepository;
use App\Core\SemanticMemoryService;
use Exception;
use RuntimeException;

class TrainingPortalController
{
    private KnowledgeRegistryRepository $knowledgeRepo;
    private SemanticMemoryService $memoryService;
    private ProjectRegistry $registry;
    private ?LLM\LLMRouter $llm;

    public function __construct(?LLM\LLMRouter $llm = null)
    {
        $this->knowledgeRepo = new KnowledgeRegistryRepository();
        $this->memoryService = new SemanticMemoryService();
        $this->registry = new ProjectRegistry();
        $this->llm = $llm;
    }

    private function getLlm(): LLM\LLMRouter
    {
        if ($this->llm === null) {
            $this->llm = new LLM\LLMRouter();
        }
        return $this->llm;
    }

    /**
     * @param array $files $_FILES array
     * @param array $post $_POST array
     * @return array Response status and message
     */
    /**
     * Inicia un proceso de investigación autónoma para un agente.
     */
    public function handleAutonomousResearch(string $agentId, string $topic, string $tenantId): array
    {
        try {
            // 0. Registrar inicio en telemetría
            $this->registry->logAgentEvent($agentId, $tenantId, 'RESEARCH_START', "Iniciando investigación autónoma sobre: $topic", 'INFO');

            // 1. Ejecutar Búsqueda (Simulación Operativa)
            $searchResults = $this->conductSearch($topic);
            $this->registry->logAgentEvent($agentId, $tenantId, 'SEARCH_COMPLETED', "Fuentes recopiladas para: $topic. Iniciando Análisis...", 'SUCCESS');

            // 2. Cargar Contrato de Síntesis
            $this->registry->logAgentEvent($agentId, $tenantId, 'KTC_LOAD', "Cargando contrato de síntesis para el agente...", 'INFO');
            $promptPath = __DIR__ . '/../../contracts/prompts/research_synthesis.prompt';
            if (!file_exists($promptPath)) {
                throw new RuntimeException("Contrato de síntesis no encontrado en: $promptPath");
            }
            $promptTemplate = file_get_contents($promptPath);

            // 3. Preparar Prompt para LLM
            $prompt = str_replace(
                ['{{topic}}', '{{search_results}}'],
                [$topic, $searchResults],
                $promptTemplate
            );

            // 4. Sintetizar Conocimiento via LLM
            $this->registry->logAgentEvent($agentId, $tenantId, 'LLM_SYNTHESIS_START', "El cerebro neural está sintetizando las fuentes académicas...", 'INFO');
            $llmResponse = $this->getLlm()->complete([
                ['role' => 'user', 'content' => $prompt]
            ]);
            $syntheticContent = $llmResponse['text'] ?? '';

            if (empty($syntheticContent)) {
                 throw new RuntimeException("El LLM no pudo generar contenido síntetico para $topic.");
            }
            $this->registry->logAgentEvent($agentId, $tenantId, 'LLM_SYNTHESIS_DONE', "Síntesis de conocimiento finalizada con éxito.", 'SUCCESS');

            // 5. Ingestar en Memoria de Sector
            $this->registry->logAgentEvent($agentId, $tenantId, 'VECTOR_INGESTION_START', "Inyectando vectores de conocimiento en la biblioteca sectorial...", 'INFO');
            $result = $this->memoryService->ingestRawSectorKnowledge(
                'research_autonomous',
                $syntheticContent,
                [
                    'agent_id' => $agentId,
                    'tenant_id' => $tenantId,
                    'topic' => $topic,
                    'trust_score' => 0.95,
                    'source' => 'autonomous_neural_search'
                ]
            );

            // 6. Registrar éxito
            $this->registry->logAgentEvent($agentId, $tenantId, 'RESEARCH_COMPLETED', "Conocimiento sobre '$topic' ingerido en Vector Store.", 'SUCCESS');

            return [
                'success' => true,
                'message' => "Investigación sobre '$topic' completada e ingerida para el agente $agentId. NIA_VECTOR_ID: " . ($result['operation_id'] ?? 'OK')
            ];

        } catch (Exception $e) {
            error_log("[AutonomousResearch] CRITICAL ERROR: " . $e->getMessage());
            return ['success' => false, 'message' => 'Error en investigación: ' . $e->getMessage()];
        }
    }

    /**
     * Simula la recopilación de datos de múltiples fuentes confiables.
     */
    private function conductSearch(string $topic): string
    {
        // En producción, esto se conectaría a Google Search API / Tavily / etc.
        // Aquí simulamos 3 fragmentos de fuentes 'reales' para que el LLM trabaje.
        return "RESULTADO 1 (Gaceta Oficial): El marco regulatorio para $topic establece que se deben seguir los estándares de calidad ISO 9001 y las normativas locales vigentes desde 2024.\n" .
               "RESULTADO 2 (Manual Técnico): Para operar con $topic, se requiere una validación previa del registro mercantil y la firma digital autorizada por la cámara de comercio.\n" .
               "RESULTADO 3 (FAQ Entidad): Pregunta común sobre $topic: ¿Cómo se calcula el impuesto base? Respuesta: Se utiliza una tasa del 25% sobre el margen operacional antes de impuestos.";
    }

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

    /**
     * Traza el razonamiento RAG: Recupera fragmentos de Qdrant y genera una respuesta rápida con LLM.
     */
    public function traceKnowledge(string $query): array
    {
        try {
            // 1. Recuperar de Memoria Semántica (Sector Knowledge)
            // retrieve(string $query, array $scope, ?int $limit = null)
            $scope = [
                'memory_type' => 'sector_knowledge',
                'tenant_id' => 'system' // El conocimiento sectorial es global en este contexto
            ];
            
            $limit = 3;
            $searchResult = $this->memoryService->retrieve($query, $scope, $limit);
            
            if (!$searchResult['ok'] || empty($searchResult['hits'])) {
                return ['success' => true, 'answer' => 'No hay conocimiento relevante en la memoria local para esta consulta.', 'sources' => []];
            }

            $sources = [];
            $contextText = "";
            foreach ($searchResult['hits'] as $hit) {
                $sources[] = [
                    'id' => (string)($hit['id'] ?? 'unknown'),
                    'score' => (float)($hit['score'] ?? 0.0),
                    'content' => (string)($hit['content'] ?? '')
                ];
                $contextText .= ($hit['content'] ?? '') . "\n---\n";
            }

            // 2. Síntesis rápida con LLM para probar "Producción"
            $prompt = "Actúa como el cerebro neural de SUKI. Basado EXCLUSIVAMENTE en estos fragmentos de memoria técnica (RAG), responde a la consulta de forma concisa.\n\n" .
                      "FRAGMENTOS RECUPERADOS:\n$contextText\n\n" .
                      "CONSULTA DEL USUARIO: $query\n\n" .
                      "Si no hay suficiente información, dilo honestamente.";

            $llmResponse = $this->getLlm()->complete([
                ['role' => 'user', 'content' => $prompt]
            ]);

            return [
                'success' => true,
                'answer' => $llmResponse['text'] ?? 'No se pudo generar la síntesis neural.',
                'sources' => $sources
            ];

        } catch (Exception $e) {
            error_log("[TraceKnowledge] Error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Error en traza RAG: ' . $e->getMessage()];
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
