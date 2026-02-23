<?php
// app/Core/IntentRouter.php

namespace App\Core;

final class IntentRouter
{
    public function route(array $gatewayResult): IntentRouteResult
    {
        $action = (string) ($gatewayResult['action'] ?? 'respond_local');
        $reply = (string) ($gatewayResult['reply'] ?? '');
        $command = is_array($gatewayResult['command'] ?? null) ? (array) $gatewayResult['command'] : [];
        $llmRequest = is_array($gatewayResult['llm_request'] ?? null) ? (array) $gatewayResult['llm_request'] : [];
        $state = is_array($gatewayResult['state'] ?? null) ? (array) $gatewayResult['state'] : [];
        $telemetry = is_array($gatewayResult['telemetry'] ?? null) ? (array) $gatewayResult['telemetry'] : [];

        if (in_array($action, ['respond_local', 'ask_user'], true)) {
            return new IntentRouteResult($action, $reply, [], [], $state, $telemetry);
        }
        if ($action === 'execute_command' && !empty($command)) {
            return new IntentRouteResult($action, '', $command, [], $state, $telemetry);
        }
        if ($action === 'send_to_llm' && !empty($llmRequest)) {
            return new IntentRouteResult($action, '', [], $llmRequest, $state, $telemetry);
        }

        return new IntentRouteResult('error', $reply, [], [], $state, $telemetry);
    }
}

