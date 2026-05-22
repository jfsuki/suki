<?php
declare(strict_types=1);

namespace App\Core\Skills;

use App\Core\ProjectRegistry;

/**
 * CreateAgentSkill — crea agentes especializados personalizados via entrevista conversacional.
 *
 * Phase 1 (confirmed != true): retorna developer_instructions al LLM para conducir la entrevista.
 * Phase 2 (confirmed = true): LLM entrega la definición completa, PHP valida y persiste en ai_agents.
 */
final class CreateAgentSkill
{
    private const MAX_PROMPT_LENGTH = 4000;

    private const DANGEROUS_PATTERNS = [
        '<script', 'system(', 'exec(', 'shell_exec', 'passthru', '<?php', 'DROP TABLE', 'DELETE FROM',
    ];

    public function handle(array $args, array $context): array
    {
        $tenantId  = (string) ($context['tenant_id']  ?? 'default');
        $sessionId = (string) ($context['session_id'] ?? 'main');

        $confirmed = $args['confirmed'] ?? false;
        if ($confirmed === true || $confirmed === 'true' || $confirmed === 1) {
            return $this->executeCreation($tenantId, $sessionId, $args);
        }

        return $this->startInterview($tenantId, $sessionId, $args);
    }

    // ─── Phase 1: Interview ────────────────────────────────────────────────

    private function startInterview(string $tenantId, string $sessionId, array $args): array
    {
        $existingAgents = $this->listCustomAgents($tenantId);
        $existingBlock  = '';
        if (!empty($existingAgents)) {
            $names         = array_map(fn($a) => "- **{$a['area']}**: {$a['task']}", $existingAgents);
            $existingBlock = "\n\nAGENTES YA CREADOS PARA ESTE TENANT:\n" . implode("\n", $names) . "\n";
        }

        $instructions = <<<PROMPT
MODO: Eres un arquitecto de IA senior ayudando al usuario a definir un agente inteligente para su negocio.
{$existingBlock}
TAREA: Hacer una entrevista profesional para entender qué agente necesita el usuario.

PREGUNTAS OBLIGATORIAS (en conversación natural, no como formulario):
1. ¿Cuál es la tarea principal de este agente? (análisis de cotizaciones, revisión de contratos, cálculo de precios, etc.)
2. ¿Qué condiciones o reglas debe aplicar? (por ejemplo: "si el margen es < 15% alertar", "verificar que el proveedor esté en lista aprobada")
3. ¿Tiene fórmulas de cálculo? (descuentos, impuestos, márgenes, tarifas)
4. ¿Con qué documentos trabaja? (Excel de cotizaciones, PDF de contratos, facturas)
5. ¿En qué frases o situaciones el usuario activa este agente? (trigger phrases: "analiza esta cotización", "revisa el contrato", etc.)
6. ¿Tiene acceso a datos del negocio en SUKI? (inventario, clientes, precios)
7. ¿Nombre para este agente? (ej: "Analista de Cotizaciones")

Cuando tengas toda la información (mínimo 4 respuestas completas), genera:
- Un system prompt profesional en español que defina completamente al agente
- Una lista de 5-10 frases de activación (trigger_phrases)
- Un área única en snake_case (ej: QUOTATION_ANALYSIS, CONTRACT_REVIEW)

Luego llama create_agent con:
- confirmed: true
- area: (NOMBRE_AREA en mayúsculas, snake_case)
- task_description: (qué hace en una frase)
- trigger_phrases: (array de frases naturales que lo activan)
- conditions: (array de reglas que aplica)
- formulas: (string con fórmulas en lenguaje natural, vacío si no aplica)
- document_types: (array: 'excel_quotation', 'pdf_contract', 'image_invoice', etc.)
- prompt_override: (system prompt completo generado)
- agent_name: (nombre amigable del agente)
PROMPT;

        return [
            'ok'                     => false,
            'action'                 => 'agent_interview_mode',
            'developer_instructions' => $instructions,
            'reply'                  => "Voy a ayudarte a crear un agente inteligente personalizado para tu negocio.\n\n"
                                       . "Este agente aprenderá las reglas y condiciones de tu operación para asistirte de forma autónoma.\n\n"
                                       . "---\n\n"
                                       . "Para empezar: **¿qué tarea específica necesitas que haga este agente?** "
                                       . "Por ejemplo: analizar cotizaciones de proveedores, revisar contratos, calcular precios con descuentos, "
                                       . "o cualquier otra tarea repetitiva de tu negocio.",
        ];
    }

    // ─── Phase 2: Execute ──────────────────────────────────────────────────

    private function executeCreation(string $tenantId, string $sessionId, array $args): array
    {
        $area            = strtoupper(trim(preg_replace('/[^A-Z0-9_]/i', '_', (string) ($args['area']            ?? ''))));
        $taskDescription = trim((string) ($args['task_description'] ?? ''));
        $promptOverride  = trim((string) ($args['prompt_override']  ?? ''));
        $agentName       = trim((string) ($args['agent_name']       ?? $area));
        $triggerPhrases  = is_array($args['trigger_phrases'] ?? null) ? (array) $args['trigger_phrases'] : [];
        $conditions      = is_array($args['conditions']      ?? null) ? (array) $args['conditions']      : [];
        $formulas        = trim((string) ($args['formulas']          ?? ''));
        $documentTypes   = is_array($args['document_types']  ?? null) ? (array) $args['document_types']  : [];

        if ($area === '' || $taskDescription === '' || $promptOverride === '') {
            return [
                'ok'     => false,
                'action' => 'respond_local',
                'reply'  => 'Faltan datos del agente: area, task_description y prompt_override son requeridos.',
            ];
        }

        if (strlen($promptOverride) > self::MAX_PROMPT_LENGTH) {
            return [
                'ok'     => false,
                'action' => 'respond_local',
                'reply'  => "El system prompt es demasiado largo (máx " . self::MAX_PROMPT_LENGTH . " caracteres). Por favor resumir.",
            ];
        }

        foreach (self::DANGEROUS_PATTERNS as $pattern) {
            if (stripos($promptOverride, $pattern) !== false) {
                return [
                    'ok'     => false,
                    'action' => 'respond_local',
                    'reply'  => "El system prompt contiene patrones no permitidos. Por favor reformular.",
                ];
            }
        }

        try {
            $db      = (new ProjectRegistry())->db();
            $agentId = 'agent_' . $tenantId . '_' . strtolower($area) . '_' . time();

            $configJson = json_encode([
                'task_description' => $taskDescription,
                'conditions'       => $conditions,
                'formulas'         => $formulas,
                'document_types'   => $documentTypes,
                'trigger_phrases'  => $triggerPhrases,
                'created_by'       => 'user_chat',
                'created_at'       => date('c'),
            ], JSON_UNESCAPED_UNICODE);

            $stmt = $db->prepare('SELECT agent_id FROM ai_agents WHERE tenant_id = ? AND area = ?');
            $stmt->execute([$tenantId, $area]);
            $existingId = $stmt->fetchColumn();

            if ($existingId !== false) {
                $db->prepare(
                    'UPDATE ai_agents SET prompt_override = ?, config_json = ?, status = \'active\', updated_at = ? WHERE agent_id = ?'
                )->execute([$promptOverride, $configJson, date('Y-m-d H:i:s'), $existingId]);
                $agentId = (string) $existingId;
                $action  = 'actualizado';
            } else {
                $db->prepare(
                    'INSERT INTO ai_agents (agent_id, tenant_id, area, role, status, source, prompt_override, config_json, created_at, updated_at)
                     VALUES (?, ?, ?, ?, \'active\', \'custom\', ?, ?, ?, ?)'
                )->execute([
                    $agentId, $tenantId, $area, $agentName,
                    $promptOverride, $configJson,
                    date('Y-m-d H:i:s'), date('Y-m-d H:i:s'),
                ]);
                $action = 'creado';
            }

            $this->vectorizeTriggers($tenantId, $area, $agentId, $triggerPhrases, $taskDescription);

            $triggerList = !empty($triggerPhrases)
                ? "\n\nPuedes activarlo diciendo cosas como:\n"
                  . implode("\n", array_map(fn($p) => "- \"{$p}\"", array_slice($triggerPhrases, 0, 5)))
                : '';

            return [
                'ok'       => true,
                'action'   => 'respond_local',
                'agent_id' => $agentId,
                'area'     => $area,
                'reply'    => "Agente '{$agentName}' {$action} exitosamente.\n\n"
                            . "Tu nuevo agente está listo para asistirte con: {$taskDescription}"
                            . $triggerList . "\n\n"
                            . "A partir de ahora, cuando me hagas preguntas sobre este tema, activaré a **{$agentName}** automáticamente.",
            ];

        } catch (\Throwable $e) {
            error_log('[CreateAgentSkill] Error al crear agente: ' . $e->getMessage());
            return [
                'ok'     => false,
                'action' => 'respond_local',
                'reply'  => 'Error al guardar el agente. Por favor intentar nuevamente.',
            ];
        }
    }

    /**
     * Vectoriza las trigger_phrases en Qdrant para que el router las detecte automáticamente.
     * No bloquea la creación del agente si Qdrant no está disponible.
     */
    private function vectorizeTriggers(
        string $tenantId,
        string $area,
        string $agentId,
        array $triggerPhrases,
        string $taskDescription
    ): void {
        if (empty($triggerPhrases)) {
            return;
        }

        try {
            $embedding = new \App\Core\GeminiEmbeddingService();
            $store     = new \App\Core\QdrantVectorStore(
                null, null, null, null, null, null, null, 'agent_training'
            );
            $points = [];

            foreach (array_slice($triggerPhrases, 0, 10) as $i => $phrase) {
                $vector = $embedding->embed((string) $phrase);
                if (empty($vector)) {
                    continue;
                }
                $points[] = [
                    'id'      => abs(crc32($agentId . '_trigger_' . $i)),
                    'vector'  => $vector,
                    'payload' => [
                        'tenant_id'   => $tenantId,
                        'intent'      => strtolower($area),
                        'utterance'   => (string) $phrase,
                        'source_type' => 'custom_agent_trigger',
                        'agent_id'    => $agentId,
                        'description' => $taskDescription,
                    ],
                ];
            }

            if (!empty($points)) {
                $store->upsertPoints($points);
            }
        } catch (\Throwable $e) {
            error_log('[CreateAgentSkill] Qdrant vectorize failed (non-blocking): ' . $e->getMessage());
        }
    }

    private function listCustomAgents(string $tenantId): array
    {
        try {
            $db   = (new ProjectRegistry())->db();
            $stmt = $db->prepare(
                "SELECT area, config_json FROM ai_agents WHERE tenant_id = ? AND source = 'custom' AND status = 'active' LIMIT 10"
            );
            $stmt->execute([$tenantId]);
            $result = [];

            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $config   = json_decode((string) ($row['config_json'] ?? '{}'), true);
                $result[] = [
                    'area' => $row['area'],
                    'task' => (string) ($config['task_description'] ?? $row['area']),
                ];
            }

            return $result;
        } catch (\Throwable $e) {
            return [];
        }
    }
}
