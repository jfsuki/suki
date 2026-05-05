<?php
declare(strict_types=1);

namespace App\Core\Agents;

use App\Core\Agents\Registry\SpecialistPersonas;
use App\Core\Agents\Orchestrator\ResponseSynthesizer;
use App\Core\LLM\LLMRouter;

/**
 * Dispatcher multi-agente impulsado por workflow_registry.json.
 * Cero hardcode: agregar nuevos workflows/triggers editando el JSON.
 */
class AgentWorkflowDispatcher
{
    private LLMRouter $llmRouter;
    private ?\PDO $db;
    private static ?array $registry = null;

    public function __construct(LLMRouter $llmRouter, ?\PDO $db = null)
    {
        $this->llmRouter = $llmRouter;
        $this->db = $db;
    }

    /**
     * Busca un workflow para el intent y lo ejecuta con agentes especialistas.
     * Retorna null si no aplica (pipeline existente continúa sin cambios).
     */
    public function dispatch(string $intent, string $text, array $context): ?array
    {
        if ($intent === '' || ($context['mode'] ?? '') === 'builder') {
            return null;
        }

        $workflow = $this->findWorkflow($intent);
        if ($workflow === null) {
            return null;
        }

        return $this->execute($workflow, $text, $context);
    }

    private function findWorkflow(string $intent): ?array
    {
        $this->loadRegistry();
        foreach ((array) (self::$registry['workflows'] ?? []) as $name => $wf) {
            $triggers = is_array($wf['triggers'] ?? null) ? (array) $wf['triggers'] : [];
            if (in_array($intent, $triggers, true)) {
                return array_merge((array) $wf, ['name' => $name]);
            }
        }
        return null;
    }

    private function execute(array $workflow, string $text, array $context): array
    {
        $tenantId = (string) ($context['tenant_id'] ?? $context['tenantId'] ?? '');
        $results = [];
        foreach ((array) ($workflow['sequence'] ?? []) as $area) {
            $persona = SpecialistPersonas::getPersonaForTenant((string) $area, $tenantId, $this->db);
            $results[(string) $area] = [
                'status' => 'SUCCESS',
                'output' => $this->callLlm($persona, $text, $context),
            ];
        }

        $description = (string) ($workflow['description'] ?? $workflow['name'] ?? 'Multi-Agent Workflow');
        $reply = (new ResponseSynthesizer())->synthesize($results, $description);

        return ['reply' => $reply, 'workflow' => (string) ($workflow['name'] ?? '')];
    }

    private function callLlm(array $persona, string $text, array $context): string
    {
        try {
            $llmParams = is_array($persona['llm_params'] ?? null) ? (array) $persona['llm_params'] : [];
            $capsule = [
                'policy' => [
                    'max_output_tokens'  => (int) ($llmParams['max_tokens'] ?? 400),
                    'requires_strict_json' => false,
                ],
                'prompt_contract' => [
                    'SYSTEM'        => trim((string) ($persona['prompt_base'] ?? $persona['description'] ?? '')),
                    'USER_MESSAGE'  => $text,
                    'TENANT_CONTEXT' => [
                        'tenant_id'  => (string) ($context['tenant_id'] ?? 'default'),
                        'project_id' => (string) ($context['project_id'] ?? ''),
                    ],
                ],
            ];
            $result  = $this->llmRouter->chat($capsule, ['temperature' => (float) ($llmParams['temperature'] ?? 0.3)]);
            $content = (string) ($result['text'] ?? $result['content'] ?? '');
            return $content !== '' ? $content : (string) ($persona['description'] ?? '');
        } catch (\Throwable $e) {
            return (string) ($persona['name'] ?? 'Agente') . ': no disponible — ' . $e->getMessage();
        }
    }

    private function loadRegistry(): void
    {
        if (self::$registry !== null) {
            return;
        }
        $path = dirname(__DIR__, 3) . '/data/workflow_registry.json';
        $raw  = file_exists($path) ? (string) file_get_contents($path) : '{}';
        $decoded = json_decode($raw, true);
        self::$registry = is_array($decoded) ? $decoded : [];
    }
}
