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
        $telemetryOverrides = [];

        if ($name !== '') {
            $llmRequest['skill_context'] = [
                'name' => $name,
                'execution_mode' => $executionMode,
                'allowed_tools' => is_array($skill['allowed_tools'] ?? null) ? (array) $skill['allowed_tools'] : [],
            ];
        }

        switch ($executionMode) {
            case 'tool':
                if ($this->isAlertsCenterSkill($name)) {
                    $toolOutcome = $this->executeAlertsCenterSkill($name, $context);
                    $action = (string) ($toolOutcome['action'] ?? 'respond_local');
                    $reply = (string) ($toolOutcome['reply'] ?? '');
                    $command = is_array($toolOutcome['command'] ?? null) ? (array) $toolOutcome['command'] : [];
                    $skillResultStatus = (string) ($toolOutcome['skill_result_status'] ?? 'safe_fallback');
                    $skillFallbackReason = (string) ($toolOutcome['skill_fallback_reason'] ?? 'none');
                    $skillFailed = (bool) ($toolOutcome['skill_failed'] ?? false);
                    $routingHintSteps = is_array($toolOutcome['routing_hint_steps'] ?? null)
                        ? (array) $toolOutcome['routing_hint_steps']
                        : ['cache', 'rules', 'skills'];
                    $telemetryOverrides = is_array($toolOutcome['telemetry'] ?? null) ? (array) $toolOutcome['telemetry'] : [];
                    break;
                }
                if ($this->isMediaSkill($name)) {
                    $toolOutcome = $this->executeMediaSkill($name, $context);
                    $action = (string) ($toolOutcome['action'] ?? 'respond_local');
                    $reply = (string) ($toolOutcome['reply'] ?? '');
                    $command = is_array($toolOutcome['command'] ?? null) ? (array) $toolOutcome['command'] : [];
                    $skillResultStatus = (string) ($toolOutcome['skill_result_status'] ?? 'safe_fallback');
                    $skillFallbackReason = (string) ($toolOutcome['skill_fallback_reason'] ?? 'none');
                    $skillFailed = (bool) ($toolOutcome['skill_failed'] ?? false);
                    $routingHintSteps = is_array($toolOutcome['routing_hint_steps'] ?? null)
                        ? (array) $toolOutcome['routing_hint_steps']
                        : ['cache', 'rules', 'skills'];
                    $telemetryOverrides = is_array($toolOutcome['telemetry'] ?? null) ? (array) $toolOutcome['telemetry'] : [];
                    break;
                }
                if ($this->isEntitySearchSkill($name)) {
                    $toolOutcome = $this->executeEntitySearchSkill($name, $context);
                    $action = (string) ($toolOutcome['action'] ?? 'respond_local');
                    $reply = (string) ($toolOutcome['reply'] ?? '');
                    $command = is_array($toolOutcome['command'] ?? null) ? (array) $toolOutcome['command'] : [];
                    $skillResultStatus = (string) ($toolOutcome['skill_result_status'] ?? 'safe_fallback');
                    $skillFallbackReason = (string) ($toolOutcome['skill_fallback_reason'] ?? 'none');
                    $skillFailed = (bool) ($toolOutcome['skill_failed'] ?? false);
                    $routingHintSteps = is_array($toolOutcome['routing_hint_steps'] ?? null)
                        ? (array) $toolOutcome['routing_hint_steps']
                        : ['cache', 'rules', 'skills'];
                    $telemetryOverrides = is_array($toolOutcome['telemetry'] ?? null) ? (array) $toolOutcome['telemetry'] : [];
                    break;
                }
                if ($this->isPosSkill($name)) {
                    $toolOutcome = $this->executePosSkill($name, $context);
                    $action = (string) ($toolOutcome['action'] ?? 'respond_local');
                    $reply = (string) ($toolOutcome['reply'] ?? '');
                    $command = is_array($toolOutcome['command'] ?? null) ? (array) $toolOutcome['command'] : [];
                    $skillResultStatus = (string) ($toolOutcome['skill_result_status'] ?? 'safe_fallback');
                    $skillFallbackReason = (string) ($toolOutcome['skill_fallback_reason'] ?? 'none');
                    $skillFailed = (bool) ($toolOutcome['skill_failed'] ?? false);
                    $routingHintSteps = is_array($toolOutcome['routing_hint_steps'] ?? null)
                        ? (array) $toolOutcome['routing_hint_steps']
                        : ['cache', 'rules', 'skills'];
                    $telemetryOverrides = is_array($toolOutcome['telemetry'] ?? null) ? (array) $toolOutcome['telemetry'] : [];
                    break;
                }
                if ($this->isPurchasesSkill($name)) {
                    $toolOutcome = $this->executePurchasesSkill($name, $context);
                    $action = (string) ($toolOutcome['action'] ?? 'respond_local');
                    $reply = (string) ($toolOutcome['reply'] ?? '');
                    $command = is_array($toolOutcome['command'] ?? null) ? (array) $toolOutcome['command'] : [];
                    $skillResultStatus = (string) ($toolOutcome['skill_result_status'] ?? 'safe_fallback');
                    $skillFallbackReason = (string) ($toolOutcome['skill_fallback_reason'] ?? 'none');
                    $skillFailed = (bool) ($toolOutcome['skill_failed'] ?? false);
                    $routingHintSteps = is_array($toolOutcome['routing_hint_steps'] ?? null)
                        ? (array) $toolOutcome['routing_hint_steps']
                        : ['cache', 'rules', 'skills'];
                    $telemetryOverrides = is_array($toolOutcome['telemetry'] ?? null) ? (array) $toolOutcome['telemetry'] : [];
                    break;
                }
                if ($this->isFiscalSkill($name)) {
                    $toolOutcome = $this->executeFiscalSkill($name, $context);
                    $action = (string) ($toolOutcome['action'] ?? 'respond_local');
                    $reply = (string) ($toolOutcome['reply'] ?? '');
                    $command = is_array($toolOutcome['command'] ?? null) ? (array) $toolOutcome['command'] : [];
                    $skillResultStatus = (string) ($toolOutcome['skill_result_status'] ?? 'safe_fallback');
                    $skillFallbackReason = (string) ($toolOutcome['skill_fallback_reason'] ?? 'none');
                    $skillFailed = (bool) ($toolOutcome['skill_failed'] ?? false);
                    $routingHintSteps = is_array($toolOutcome['routing_hint_steps'] ?? null)
                        ? (array) $toolOutcome['routing_hint_steps']
                        : ['cache', 'rules', 'skills'];
                    $telemetryOverrides = is_array($toolOutcome['telemetry'] ?? null) ? (array) $toolOutcome['telemetry'] : [];
                    break;
                }
                if ($this->isEcommerceSkill($name)) {
                    $toolOutcome = $this->executeEcommerceSkill($name, $context);
                    $action = (string) ($toolOutcome['action'] ?? 'respond_local');
                    $reply = (string) ($toolOutcome['reply'] ?? '');
                    $command = is_array($toolOutcome['command'] ?? null) ? (array) $toolOutcome['command'] : [];
                    $skillResultStatus = (string) ($toolOutcome['skill_result_status'] ?? 'safe_fallback');
                    $skillFallbackReason = (string) ($toolOutcome['skill_fallback_reason'] ?? 'none');
                    $skillFailed = (bool) ($toolOutcome['skill_failed'] ?? false);
                    $routingHintSteps = is_array($toolOutcome['routing_hint_steps'] ?? null)
                        ? (array) $toolOutcome['routing_hint_steps']
                        : ['cache', 'rules', 'skills'];
                    $telemetryOverrides = is_array($toolOutcome['telemetry'] ?? null) ? (array) $toolOutcome['telemetry'] : [];
                    break;
                }
                if ($this->isTenantAccessControlSkill($name)) {
                    $toolOutcome = $this->executeTenantAccessControlSkill($name, $context);
                    $action = (string) ($toolOutcome['action'] ?? 'respond_local');
                    $reply = (string) ($toolOutcome['reply'] ?? '');
                    $command = is_array($toolOutcome['command'] ?? null) ? (array) $toolOutcome['command'] : [];
                    $skillResultStatus = (string) ($toolOutcome['skill_result_status'] ?? 'safe_fallback');
                    $skillFallbackReason = (string) ($toolOutcome['skill_fallback_reason'] ?? 'none');
                    $skillFailed = (bool) ($toolOutcome['skill_failed'] ?? false);
                    $routingHintSteps = is_array($toolOutcome['routing_hint_steps'] ?? null)
                        ? (array) $toolOutcome['routing_hint_steps']
                        : ['cache', 'rules', 'skills'];
                    $telemetryOverrides = is_array($toolOutcome['telemetry'] ?? null) ? (array) $toolOutcome['telemetry'] : [];
                    break;
                }
                if ($this->isTenantPlanSkill($name)) {
                    $toolOutcome = $this->executeTenantPlanSkill($name, $context);
                    $action = (string) ($toolOutcome['action'] ?? 'respond_local');
                    $reply = (string) ($toolOutcome['reply'] ?? '');
                    $command = is_array($toolOutcome['command'] ?? null) ? (array) $toolOutcome['command'] : [];
                    $skillResultStatus = (string) ($toolOutcome['skill_result_status'] ?? 'safe_fallback');
                    $skillFallbackReason = (string) ($toolOutcome['skill_fallback_reason'] ?? 'none');
                    $skillFailed = (bool) ($toolOutcome['skill_failed'] ?? false);
                    $routingHintSteps = is_array($toolOutcome['routing_hint_steps'] ?? null)
                        ? (array) $toolOutcome['routing_hint_steps']
                        : ['cache', 'rules', 'skills'];
                    $telemetryOverrides = is_array($toolOutcome['telemetry'] ?? null) ? (array) $toolOutcome['telemetry'] : [];
                    break;
                }
                if ($this->isUsageMeteringSkill($name)) {
                    $toolOutcome = $this->executeUsageMeteringSkill($name, $context);
                    $action = (string) ($toolOutcome['action'] ?? 'respond_local');
                    $reply = (string) ($toolOutcome['reply'] ?? '');
                    $command = is_array($toolOutcome['command'] ?? null) ? (array) $toolOutcome['command'] : [];
                    $skillResultStatus = (string) ($toolOutcome['skill_result_status'] ?? 'safe_fallback');
                    $skillFallbackReason = (string) ($toolOutcome['skill_fallback_reason'] ?? 'none');
                    $skillFailed = (bool) ($toolOutcome['skill_failed'] ?? false);
                    $routingHintSteps = is_array($toolOutcome['routing_hint_steps'] ?? null)
                        ? (array) $toolOutcome['routing_hint_steps']
                        : ['cache', 'rules', 'skills'];
                    $telemetryOverrides = is_array($toolOutcome['telemetry'] ?? null) ? (array) $toolOutcome['telemetry'] : [];
                    break;
                }
                if ($this->isAgentToolsIntegrationSkill($name)) {
                    $toolOutcome = $this->executeAgentToolsIntegrationSkill($name, $context);
                    $action = (string) ($toolOutcome['action'] ?? 'respond_local');
                    $reply = (string) ($toolOutcome['reply'] ?? '');
                    $command = is_array($toolOutcome['command'] ?? null) ? (array) $toolOutcome['command'] : [];
                    $skillResultStatus = (string) ($toolOutcome['skill_result_status'] ?? 'safe_fallback');
                    $skillFallbackReason = (string) ($toolOutcome['skill_fallback_reason'] ?? 'none');
                    $skillFailed = (bool) ($toolOutcome['skill_failed'] ?? false);
                    $routingHintSteps = is_array($toolOutcome['routing_hint_steps'] ?? null)
                        ? (array) $toolOutcome['routing_hint_steps']
                        : ['cache', 'rules', 'skills'];
                    $telemetryOverrides = is_array($toolOutcome['telemetry'] ?? null) ? (array) $toolOutcome['telemetry'] : [];
                    break;
                }
                if ($this->isAgentOpsObservabilitySkill($name)) {
                    $toolOutcome = $this->executeAgentOpsObservabilitySkill($name, $context);
                    $action = (string) ($toolOutcome['action'] ?? 'respond_local');
                    $reply = (string) ($toolOutcome['reply'] ?? '');
                    $command = is_array($toolOutcome['command'] ?? null) ? (array) $toolOutcome['command'] : [];
                    $skillResultStatus = (string) ($toolOutcome['skill_result_status'] ?? 'safe_fallback');
                    $skillFallbackReason = (string) ($toolOutcome['skill_fallback_reason'] ?? 'none');
                    $skillFailed = (bool) ($toolOutcome['skill_failed'] ?? false);
                    $routingHintSteps = is_array($toolOutcome['routing_hint_steps'] ?? null)
                        ? (array) $toolOutcome['routing_hint_steps']
                        : ['cache', 'rules', 'skills'];
                    $telemetryOverrides = is_array($toolOutcome['telemetry'] ?? null) ? (array) $toolOutcome['telemetry'] : [];
                    break;
                }
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
            ] + $telemetryOverrides,
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

    private function isAlertsCenterSkill(string $name): bool
    {
        return in_array($name, [
            'create_task',
            'list_pending_tasks',
            'create_reminder',
            'list_reminders',
            'create_alert',
            'list_alerts',
        ], true);
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function executeAlertsCenterSkill(string $name, array $context): array
    {
        $parser = new AlertsCenterMessageParser();
        $parsed = $parser->parse($name, $context);
        $telemetry = is_array($parsed['telemetry'] ?? null) ? (array) $parsed['telemetry'] : [];

        if ((string) ($parsed['kind'] ?? '') === 'command') {
            return [
                'action' => 'execute_command',
                'reply' => '',
                'command' => is_array($parsed['command'] ?? null) ? (array) $parsed['command'] : [],
                'skill_result_status' => 'command_ready',
                'skill_fallback_reason' => 'none',
                'skill_failed' => false,
                'routing_hint_steps' => ['cache', 'rules', 'skills'],
                'telemetry' => $telemetry,
            ];
        }

        return [
            'action' => 'ask_user',
            'reply' => (string) ($parsed['reply'] ?? 'Necesito un dato adicional para continuar.'),
            'command' => [],
            'skill_result_status' => 'needs_input',
            'skill_fallback_reason' => 'missing_operational_payload',
            'skill_failed' => false,
            'routing_hint_steps' => ['cache', 'rules', 'skills'],
            'telemetry' => $telemetry,
        ];
    }

    private function isMediaSkill(string $name): bool
    {
        return in_array($name, ['media_upload', 'media_list', 'media_get', 'media_delete'], true);
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function executeMediaSkill(string $name, array $context): array
    {
        $parser = new MediaMessageParser();
        $parsed = $parser->parse($name, $context);
        $telemetry = is_array($parsed['telemetry'] ?? null) ? (array) $parsed['telemetry'] : [];

        if ((string) ($parsed['kind'] ?? '') === 'command') {
            return [
                'action' => 'execute_command',
                'reply' => '',
                'command' => is_array($parsed['command'] ?? null) ? (array) $parsed['command'] : [],
                'skill_result_status' => 'command_ready',
                'skill_fallback_reason' => 'none',
                'skill_failed' => false,
                'routing_hint_steps' => ['cache', 'rules', 'skills'],
                'telemetry' => $telemetry,
            ];
        }

        return [
            'action' => 'ask_user',
            'reply' => (string) ($parsed['reply'] ?? 'Necesito un dato adicional para continuar.'),
            'command' => [],
            'skill_result_status' => 'needs_input',
            'skill_fallback_reason' => 'missing_media_payload',
            'skill_failed' => false,
            'routing_hint_steps' => ['cache', 'rules', 'skills'],
            'telemetry' => $telemetry,
        ];
    }

    private function isEntitySearchSkill(string $name): bool
    {
        return in_array($name, ['entity_search', 'entity_resolve'], true);
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function executeEntitySearchSkill(string $name, array $context): array
    {
        $parser = new EntitySearchMessageParser();
        $parsed = $parser->parse($name, $context);
        $telemetry = is_array($parsed['telemetry'] ?? null) ? (array) $parsed['telemetry'] : [];

        if ((string) ($parsed['kind'] ?? '') === 'command') {
            return [
                'action' => 'execute_command',
                'reply' => '',
                'command' => is_array($parsed['command'] ?? null) ? (array) $parsed['command'] : [],
                'skill_result_status' => 'command_ready',
                'skill_fallback_reason' => 'none',
                'skill_failed' => false,
                'routing_hint_steps' => ['cache', 'rules', 'skills'],
                'telemetry' => $telemetry,
            ];
        }

        return [
            'action' => 'ask_user',
            'reply' => (string) ($parsed['reply'] ?? 'Necesito una referencia mas concreta para continuar.'),
            'command' => [],
            'skill_result_status' => 'needs_input',
            'skill_fallback_reason' => 'missing_entity_reference',
            'skill_failed' => false,
            'routing_hint_steps' => ['cache', 'rules', 'skills'],
            'telemetry' => $telemetry,
        ];
    }

    private function isPosSkill(string $name): bool
    {
        return in_array($name, [
            'pos_create_draft',
            'pos_get_draft',
            'pos_add_draft_line',
            'pos_add_line_by_reference',
            'pos_remove_draft_line',
            'pos_attach_customer',
            'pos_list_open_drafts',
            'pos_find_product',
            'pos_get_product_candidates',
            'pos_reprice_draft',
            'pos_finalize_sale',
            'pos_get_sale',
            'pos_list_sales',
            'pos_build_receipt',
            'pos_get_sale_by_number',
            'pos_cancel_sale',
            'pos_create_return',
            'pos_get_return',
            'pos_list_returns',
            'pos_build_return_receipt',
            'pos_open_cash_register',
            'pos_get_open_cash_session',
            'pos_close_cash_register',
            'pos_build_cash_summary',
            'pos_list_cash_sessions',
        ], true);
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function executePosSkill(string $name, array $context): array
    {
        $parser = new POSMessageParser();
        $parsed = $parser->parse($name, $context);
        $telemetry = is_array($parsed['telemetry'] ?? null) ? (array) $parsed['telemetry'] : [];

        if ((string) ($parsed['kind'] ?? '') === 'command') {
            return [
                'action' => 'execute_command',
                'reply' => '',
                'command' => is_array($parsed['command'] ?? null) ? (array) $parsed['command'] : [],
                'skill_result_status' => 'command_ready',
                'skill_fallback_reason' => 'none',
                'skill_failed' => false,
                'routing_hint_steps' => ['cache', 'rules', 'skills'],
                'telemetry' => $telemetry,
            ];
        }

        return [
            'action' => 'ask_user',
            'reply' => (string) ($parsed['reply'] ?? 'Necesito un dato adicional para continuar con POS.'),
            'command' => [],
            'skill_result_status' => 'needs_input',
            'skill_fallback_reason' => 'missing_pos_payload',
            'skill_failed' => false,
            'routing_hint_steps' => ['cache', 'rules', 'skills'],
            'telemetry' => $telemetry,
        ];
    }

    private function isPurchasesSkill(string $name): bool
    {
        return in_array($name, [
            'purchases_create_draft',
            'purchases_get_draft',
            'purchases_add_draft_line',
            'purchases_remove_draft_line',
            'purchases_attach_supplier',
            'purchases_finalize',
            'purchases_get_purchase',
            'purchases_list',
            'purchases_get_by_number',
            'purchases_attach_document_to_draft',
            'purchases_attach_document',
            'purchases_list_documents',
            'purchases_get_document',
            'purchases_detach_document',
            'purchases_register_document_metadata',
        ], true);
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function executePurchasesSkill(string $name, array $context): array
    {
        $parser = new PurchasesMessageParser();
        $parsed = $parser->parse($name, $context);
        $telemetry = is_array($parsed['telemetry'] ?? null) ? (array) $parsed['telemetry'] : [];

        if ((string) ($parsed['kind'] ?? '') === 'command') {
            return [
                'action' => 'execute_command',
                'reply' => '',
                'command' => is_array($parsed['command'] ?? null) ? (array) $parsed['command'] : [],
                'skill_result_status' => 'command_ready',
                'skill_fallback_reason' => 'none',
                'skill_failed' => false,
                'routing_hint_steps' => ['cache', 'rules', 'skills'],
                'telemetry' => $telemetry,
            ];
        }

        return [
            'action' => 'ask_user',
            'reply' => (string) ($parsed['reply'] ?? 'Necesito un dato adicional para continuar con compras.'),
            'command' => [],
            'skill_result_status' => 'needs_input',
            'skill_fallback_reason' => 'missing_purchases_payload',
            'skill_failed' => false,
            'routing_hint_steps' => ['cache', 'rules', 'skills'],
            'telemetry' => $telemetry,
        ];
    }

    private function isFiscalSkill(string $name): bool
    {
        return in_array($name, [
            'fiscal_create_document',
            'fiscal_create_sales_invoice_from_sale',
            'fiscal_create_credit_note',
            'fiscal_create_support_document_from_purchase',
            'fiscal_get_document',
            'fiscal_list_documents',
            'fiscal_list_documents_by_type',
            'fiscal_get_by_source',
            'fiscal_build_document_payload',
            'fiscal_record_event',
            'fiscal_update_status',
        ], true);
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function executeFiscalSkill(string $name, array $context): array
    {
        $parser = new FiscalEngineMessageParser();
        $parsed = $parser->parse($name, $context);
        $telemetry = is_array($parsed['telemetry'] ?? null) ? (array) $parsed['telemetry'] : [];

        if ((string) ($parsed['kind'] ?? '') === 'command') {
            return [
                'action' => 'execute_command',
                'reply' => '',
                'command' => is_array($parsed['command'] ?? null) ? (array) $parsed['command'] : [],
                'skill_result_status' => 'command_ready',
                'skill_fallback_reason' => 'none',
                'skill_failed' => false,
                'routing_hint_steps' => ['cache', 'rules', 'skills'],
                'telemetry' => $telemetry,
            ];
        }

        return [
            'action' => 'ask_user',
            'reply' => (string) ($parsed['reply'] ?? 'Necesito un dato adicional para continuar con fiscal.'),
            'command' => [],
            'skill_result_status' => 'needs_input',
            'skill_fallback_reason' => 'missing_fiscal_payload',
            'skill_failed' => false,
            'routing_hint_steps' => ['cache', 'rules', 'skills'],
            'telemetry' => $telemetry,
        ];
    }

    private function isEcommerceSkill(string $name): bool
    {
        return in_array($name, [
            'ecommerce_create_store',
            'ecommerce_update_store',
            'ecommerce_register_credentials',
            'ecommerce_validate_store_setup',
            'ecommerce_validate_connection',
            'ecommerce_get_store_metadata',
            'ecommerce_get_platform_capabilities',
            'ecommerce_ping_store',
            'ecommerce_list_stores',
            'ecommerce_get_store',
            'ecommerce_create_sync_job',
            'ecommerce_list_sync_jobs',
            'ecommerce_list_order_refs',
            'ecommerce_link_order',
            'ecommerce_get_order_link',
            'ecommerce_list_order_links',
            'ecommerce_register_order_pull_snapshot',
            'ecommerce_normalize_external_order',
            'ecommerce_mark_order_sync_status',
            'ecommerce_get_order_snapshot',
            'ecommerce_link_product',
            'ecommerce_unlink_product',
            'ecommerce_list_product_links',
            'ecommerce_get_product_link',
            'ecommerce_prepare_product_push_payload',
            'ecommerce_register_product_pull_snapshot',
            'ecommerce_mark_product_sync_status',
        ], true);
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function executeEcommerceSkill(string $name, array $context): array
    {
        $parser = new EcommerceHubMessageParser();
        $parsed = $parser->parse($name, $context);
        $telemetry = is_array($parsed['telemetry'] ?? null) ? (array) $parsed['telemetry'] : [];

        if ((string) ($parsed['kind'] ?? '') === 'command') {
            return [
                'action' => 'execute_command',
                'reply' => '',
                'command' => is_array($parsed['command'] ?? null) ? (array) $parsed['command'] : [],
                'skill_result_status' => 'command_ready',
                'skill_fallback_reason' => 'none',
                'skill_failed' => false,
                'routing_hint_steps' => ['cache', 'rules', 'skills'],
                'telemetry' => $telemetry,
            ];
        }

        return [
            'action' => 'ask_user',
            'reply' => (string) ($parsed['reply'] ?? 'Necesito un dato adicional para continuar con ecommerce.'),
            'command' => [],
            'skill_result_status' => 'needs_input',
            'skill_fallback_reason' => (($telemetry['ambiguity_detected'] ?? false) === true)
                ? 'ambiguous_ecommerce_reference'
                : 'missing_ecommerce_payload',
            'skill_failed' => false,
            'routing_hint_steps' => ['cache', 'rules', 'skills'],
            'telemetry' => $telemetry,
        ];
    }

    private function isTenantAccessControlSkill(string $name): bool
    {
        return in_array($name, [
            'tenant_add_user',
            'tenant_list_users',
            'tenant_get_user_role',
            'tenant_update_user_role',
            'tenant_deactivate_user',
            'tenant_check_permission',
        ], true);
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function executeTenantAccessControlSkill(string $name, array $context): array
    {
        $parser = new TenantAccessControlMessageParser();
        $parsed = $parser->parse($name, $context);
        $telemetry = is_array($parsed['telemetry'] ?? null) ? (array) $parsed['telemetry'] : [];

        if ((string) ($parsed['kind'] ?? '') === 'command') {
            return [
                'action' => 'execute_command',
                'reply' => '',
                'command' => is_array($parsed['command'] ?? null) ? (array) $parsed['command'] : [],
                'skill_result_status' => 'command_ready',
                'skill_fallback_reason' => 'none',
                'skill_failed' => false,
                'routing_hint_steps' => ['cache', 'rules', 'skills'],
                'telemetry' => $telemetry,
            ];
        }

        return [
            'action' => 'ask_user',
            'reply' => (string) ($parsed['reply'] ?? 'Necesito un dato adicional para continuar con acceso multiusuario.'),
            'command' => [],
            'skill_result_status' => 'needs_input',
            'skill_fallback_reason' => 'missing_access_control_payload',
            'skill_failed' => false,
            'routing_hint_steps' => ['cache', 'rules', 'skills'],
            'telemetry' => $telemetry,
        ];
    }

    private function isTenantPlanSkill(string $name): bool
    {
        return in_array($name, [
            'tenant_assign_plan',
            'tenant_get_plan',
            'tenant_list_plans',
            'tenant_set_plan_limits',
            'tenant_check_plan_limit',
            'tenant_get_enabled_modules',
        ], true);
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function executeTenantPlanSkill(string $name, array $context): array
    {
        $parser = new TenantPlanMessageParser();
        $parsed = $parser->parse($name, $context);
        $telemetry = is_array($parsed['telemetry'] ?? null) ? (array) $parsed['telemetry'] : [];

        if ((string) ($parsed['kind'] ?? '') === 'command') {
            return [
                'action' => 'execute_command',
                'reply' => '',
                'command' => is_array($parsed['command'] ?? null) ? (array) $parsed['command'] : [],
                'skill_result_status' => 'command_ready',
                'skill_fallback_reason' => 'none',
                'skill_failed' => false,
                'routing_hint_steps' => ['cache', 'rules', 'skills'],
                'telemetry' => $telemetry,
            ];
        }

        return [
            'action' => 'ask_user',
            'reply' => (string) ($parsed['reply'] ?? 'Necesito un dato adicional para continuar con el plan SaaS.'),
            'command' => [],
            'skill_result_status' => 'needs_input',
            'skill_fallback_reason' => 'missing_saas_plan_payload',
            'skill_failed' => false,
            'routing_hint_steps' => ['cache', 'rules', 'skills'],
            'telemetry' => $telemetry,
        ];
    }

    private function isUsageMeteringSkill(string $name): bool
    {
        return in_array($name, [
            'usage_record_event',
            'usage_get_summary',
            'usage_check_limit',
            'usage_list_metrics',
            'usage_get_history',
        ], true);
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function executeUsageMeteringSkill(string $name, array $context): array
    {
        $parser = new UsageMeteringMessageParser();
        $parsed = $parser->parse($name, $context);
        $telemetry = is_array($parsed['telemetry'] ?? null) ? (array) $parsed['telemetry'] : [];

        if ((string) ($parsed['kind'] ?? '') === 'command') {
            return [
                'action' => 'execute_command',
                'reply' => '',
                'command' => is_array($parsed['command'] ?? null) ? (array) $parsed['command'] : [],
                'skill_result_status' => 'command_ready',
                'skill_fallback_reason' => 'none',
                'skill_failed' => false,
                'routing_hint_steps' => ['cache', 'rules', 'skills'],
                'telemetry' => $telemetry,
            ];
        }

        return [
            'action' => 'ask_user',
            'reply' => (string) ($parsed['reply'] ?? 'Necesito un dato adicional para continuar con usage metering.'),
            'command' => [],
            'skill_result_status' => 'needs_input',
            'skill_fallback_reason' => 'missing_usage_metering_payload',
            'skill_failed' => false,
            'routing_hint_steps' => ['cache', 'rules', 'skills'],
            'telemetry' => $telemetry,
        ];
    }

    private function isAgentToolsIntegrationSkill(string $name): bool
    {
        return in_array($name, [
            'agent_list_tool_groups',
            'agent_get_module_capabilities',
            'agent_resolve_tool_for_request',
            'agent_check_module_enabled',
            'agent_check_action_allowed',
        ], true);
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function executeAgentToolsIntegrationSkill(string $name, array $context): array
    {
        $parser = new AgentToolsIntegrationMessageParser();
        $parsed = $parser->parse($name, $context);
        $telemetry = is_array($parsed['telemetry'] ?? null) ? (array) $parsed['telemetry'] : [];

        if ((string) ($parsed['kind'] ?? '') === 'command') {
            return [
                'action' => 'execute_command',
                'reply' => '',
                'command' => is_array($parsed['command'] ?? null) ? (array) $parsed['command'] : [],
                'skill_result_status' => 'command_ready',
                'skill_fallback_reason' => 'none',
                'skill_failed' => false,
                'routing_hint_steps' => ['cache', 'rules', 'skills'],
                'telemetry' => $telemetry,
            ];
        }

        return [
            'action' => 'ask_user',
            'reply' => (string) ($parsed['reply'] ?? 'Necesito un dato adicional para continuar con las herramientas del agente.'),
            'command' => [],
            'skill_result_status' => 'needs_input',
            'skill_fallback_reason' => (($telemetry['ambiguity_detected'] ?? false) === true)
                ? 'ambiguous_agent_tools_request'
                : 'missing_agent_tools_payload',
            'skill_failed' => false,
            'routing_hint_steps' => ['cache', 'rules', 'skills'],
            'telemetry' => $telemetry,
        ];
    }

    private function isAgentOpsObservabilitySkill(string $name): bool
    {
        return in_array($name, [
            'agentops_get_metrics_summary',
            'agentops_list_recent_decisions',
            'agentops_list_tool_executions',
            'agentops_get_anomaly_flags',
        ], true);
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function executeAgentOpsObservabilitySkill(string $name, array $context): array
    {
        $parser = new AgentOpsObservabilityMessageParser();
        $parsed = $parser->parse($name, $context);
        $telemetry = is_array($parsed['telemetry'] ?? null) ? (array) $parsed['telemetry'] : [];

        if ((string) ($parsed['kind'] ?? '') === 'command') {
            return [
                'action' => 'execute_command',
                'reply' => '',
                'command' => is_array($parsed['command'] ?? null) ? (array) $parsed['command'] : [],
                'skill_result_status' => 'command_ready',
                'skill_fallback_reason' => 'none',
                'skill_failed' => false,
                'routing_hint_steps' => ['cache', 'rules', 'skills'],
                'telemetry' => $telemetry,
            ];
        }

        return [
            'action' => 'ask_user',
            'reply' => (string) ($parsed['reply'] ?? 'Necesito un dato adicional para consultar AgentOps.'),
            'command' => [],
            'skill_result_status' => 'needs_input',
            'skill_fallback_reason' => 'missing_agentops_payload',
            'skill_failed' => false,
            'routing_hint_steps' => ['cache', 'rules', 'skills'],
            'telemetry' => $telemetry,
        ];
    }
}
