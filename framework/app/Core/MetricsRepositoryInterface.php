<?php
// app/Core/MetricsRepositoryInterface.php

namespace App\Core;

interface MetricsRepositoryInterface
{
    public function saveIntentMetric(array $metric): void;

    public function saveCommandMetric(array $metric): void;

    public function saveGuardrailEvent(array $event): void;

    public function saveTokenUsage(array $usage): void;

    public function saveDecisionTrace(array $trace): void;

    public function saveToolExecutionTrace(array $trace): void;

    public function summary(string $tenantId, string $projectId, int $days = 7): array;

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function listDecisionTraces(string $tenantId, string $projectId, array $filters = [], int $limit = 25): array;

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function listToolExecutionTraces(string $tenantId, string $projectId, array $filters = [], int $limit = 25): array;

    public function observabilitySummary(string $tenantId, string $projectId, int $days = 7): array;
}
