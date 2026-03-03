<?php
// app/Core/IntentRouteResult.php

namespace App\Core;

final class IntentRouteResult
{
    private string $kind;
    private string $reply;
    private array $command;
    private array $llmRequest;
    private array $state;
    private array $telemetry;
    private array $agentOps;

    public function __construct(
        string $kind,
        string $reply = '',
        array $command = [],
        array $llmRequest = [],
        array $state = [],
        array $telemetry = [],
        array $agentOps = []
    ) {
        $this->kind = $kind;
        $this->reply = $reply;
        $this->command = $command;
        $this->llmRequest = $llmRequest;
        $this->state = $state;
        $this->telemetry = $telemetry;
        $this->agentOps = $agentOps;
    }

    public function kind(): string
    {
        return $this->kind;
    }

    public function reply(): string
    {
        return $this->reply;
    }

    public function command(): array
    {
        return $this->command;
    }

    public function llmRequest(): array
    {
        return $this->llmRequest;
    }

    public function state(): array
    {
        return $this->state;
    }

    public function telemetry(): array
    {
        return $this->telemetry;
    }

    public function agentOps(): array
    {
        return $this->agentOps;
    }

    public function isLocalResponse(): bool
    {
        return in_array($this->kind, ['respond_local', 'ask_user'], true);
    }

    public function isCommand(): bool
    {
        return $this->kind === 'execute_command' && !empty($this->command);
    }

    public function isLlmRequest(): bool
    {
        return $this->kind === 'send_to_llm' && !empty($this->llmRequest);
    }
}
