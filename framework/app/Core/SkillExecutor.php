<?php
// app/Core/SkillExecutor.php

declare(strict_types=1);

namespace App\Core;

final class SkillExecutor
{
    /**
     * @param array<string,mixed> $skill
     * @param array<string,mixed> $gatewayResult
     * @param array<string,mixed> $context
     * @param array<string,int> $runtimeBudget
     * @return array<string,mixed>
     */
    public function execute(array $skill, array $gatewayResult, array $context, array $runtimeBudget): array
    {
        $startedAt = microtime(true);
        $name = trim((string) ($skill['name'] ?? ''));
        $executionMode = trim((string) ($skill['execution_mode'] ?? 'deterministic'));
        $llmRequest = is_array($gatewayResult['llm_request'] ?? null) ? (array) $gatewayResult['llm_request'] : [];
        $contextOverrides = [];
        $action = 'send_to_llm';
        $reply = '';
        $command = [];
        $routingHintSteps = ['cache', 'rules', 'skills', 'rag', 'llm'];
        $skillFailed = false;
        $skillFallbackReason = 'none';
        $skillResultStatus = 'selected';

        if ($name !== '') {
            $llmRequest['skill_context'] = [
                'name' => $name,
                'execution_mode' => $executionMode,
                'allowed_tools' => is_array($skill['allowed_tools'] ?? null) ? (array) $skill['allowed_tools'] : [],
            ];
        }

        switch ($executionMode) {
            case 'tool':
                [$action, $reply, $skillResultStatus, $skillFallbackReason, $skillFailed, $routingHintSteps] = $this->executeToolSkill($skill, $context);
                break;

            case 'rag':
                $contextOverrides = $this->ragContextOverrides($skill, $runtimeBudget);
                $skillResultStatus = 'continued_to_rag';
                break;

            case 'hybrid':
                $contextOverrides = $this->ragContextOverrides($skill, $runtimeBudget);
                $skillResultStatus = 'continued_to_rag';
                break;

            case 'deterministic':
            default:
                [$action, $reply, $skillResultStatus, $skillFallbackReason, $routingHintSteps] = $this->executeDeterministicSkill($skill, $context);
                break;
        }

        return [
            'action' => $action,
            'reply' => $reply,
            'command' => $command,
            'llm_request' => $llmRequest,
            'context_overrides' => $contextOverrides,
            'routing_hint_steps' => $routingHintSteps,
            'telemetry' => [
                'skill_detected' => true,
                'skill_selected' => $name !== '' ? $name : 'none',
                'skill_executed' => true,
                'skill_failed' => $skillFailed,
                'skill_execution_mode' => $executionMode !== '' ? $executionMode : 'deterministic',
                'skill_execution_ms' => $this->latencyMs($startedAt),
                'skill_result_status' => $skillResultStatus,
                'skill_fallback_reason' => $skillFallbackReason,
            ],
        ];
    }

    /**
     * @param array<string,mixed> $skill
     * @param array<string,mixed> $context
     * @return array{0:string,1:string,2:string,3:string,4:bool,5:array<int,string>}
     */
    private function executeToolSkill(array $skill, array $context): array
    {
        $name = trim((string) ($skill['name'] ?? ''));
        if (in_array($name, ['read_document', 'extract_invoice_data', 'image_analysis'], true)) {
            return $this->executeAttachmentSkill($skill, $context);
        }

        $toolName = $this->resolvePrimaryToolName($skill);

        return [
            'respond_local',
            'La capacidad ' . $name . ' fue clasificada, pero el runtime actual aun no expone el tool ' . $toolName . '. No ejecute ninguna operacion.',
            'safe_fallback',
            'tool_runtime_unavailable',
            true,
            ['cache', 'rules', 'skills'],
        ];
    }

    /**
     * @param array<string,mixed> $skill
     * @param array<string,mixed> $context
     * @return array{0:string,1:string,2:string,3:string,4:bool,5:array<int,string>}
     */
    private function executeAttachmentSkill(array $skill, array $context): array
    {
        $mediaType = trim((string) (($skill['context_hints']['media_type'] ?? 'archivo')));
        $attachmentsCount = is_numeric($context['attachments_count'] ?? null)
            ? max(0, (int) $context['attachments_count'])
            : 0;
        $skillName = trim((string) ($skill['name'] ?? 'skill'));

        if ($attachmentsCount < 1) {
            return [
                'ask_user',
                'Necesito el ' . $mediaType . ' para ejecutar la capacidad ' . $skillName . ' sin recurrir al LLM libre.',
                'needs_input',
                'attachment_required',
                false,
                ['cache', 'rules', 'skills'],
            ];
        }

        return [
            'respond_local',
            'La capacidad ' . $skillName . ' fue detectada, pero el canal actual aun no expone un runtime de tool compatible para procesar este ' . $mediaType . '.',
            'safe_fallback',
            'tool_runtime_unavailable',
            true,
            ['cache', 'rules', 'skills'],
        ];
    }

    /**
     * @param array<string,mixed> $skill
     * @param array<string,mixed> $context
     * @return array{0:string,1:string,2:string,3:string,4:array<int,string>}
     */
    private function executeDeterministicSkill(array $skill, array $context): array
    {
        $name = trim((string) ($skill['name'] ?? ''));
        if ($name === 'generate_report' || $name === 'report_generation') {
            $query = strtolower(trim((string) ($context['message_text'] ?? '')));
            if (preg_match('/\b(hoy|ayer|semana|mes|ano|año|desde|hasta|202[0-9])\b/u', $query) !== 1) {
                return [
                    'ask_user',
                    'Indica el reporte y el periodo exacto para preparar esa solicitud sin abrir un flujo libre.',
                    'needs_input',
                    'missing_report_scope',
                    ['cache', 'rules', 'skills'],
                ];
            }

            return [
                'respond_local',
                'La solicitud de reporte quedo clasificada. El siguiente paso es usar un builder o tool de reportes del proyecto para materializarlo.',
                'resolved_local',
                'none',
                ['cache', 'rules', 'skills'],
            ];
        }

        return [
            'respond_local',
            'La capacidad ' . $name . ' fue resuelta por una ruta deterministica controlada.',
            'resolved_local',
            'none',
            ['cache', 'rules', 'skills'],
        ];
    }

    /**
     * @param array<string,mixed> $skill
     * @param array<string,int> $runtimeBudget
     * @return array<string,mixed>
     */
    private function ragContextOverrides(array $skill, array $runtimeBudget): array
    {
        $overrides = [
            'max_context_chunks' => max(1, (int) ($runtimeBudget['max_context_chunks'] ?? 2)),
        ];

        $memoryType = trim((string) ($skill['memory_type'] ?? ''));
        if ($memoryType !== '') {
            $overrides['memory_type'] = QdrantVectorStore::assertMemoryType($memoryType);
        }

        return $overrides;
    }

    /**
     * @param array<string,mixed> $skill
     */
    private function resolvePrimaryToolName(array $skill): string
    {
        $allowedTools = is_array($skill['allowed_tools'] ?? null) ? (array) $skill['allowed_tools'] : [];
        foreach ($allowedTools as $allowedTool) {
            $allowedTool = trim((string) $allowedTool);
            if ($allowedTool !== '') {
                return $allowedTool;
            }
        }

        return 'unregistered_tool';
    }

    private function latencyMs(float $startedAt): int
    {
        return (int) max(0, round((microtime(true) - $startedAt) * 1000));
    }
}
