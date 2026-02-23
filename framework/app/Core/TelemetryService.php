<?php
// app/Core/TelemetryService.php

namespace App\Core;

final class TelemetryService
{
    private MetricsRepositoryInterface $metrics;

    public function __construct(?MetricsRepositoryInterface $metrics = null)
    {
        $this->metrics = $metrics ?? new SqlMetricsRepository();
    }

    public function recordIntentMetric(array $metric): void
    {
        $this->metrics->saveIntentMetric($metric);
    }

    public function recordCommandMetric(array $metric): void
    {
        $this->metrics->saveCommandMetric($metric);
    }

    public function recordGuardrailEvent(array $event): void
    {
        $this->metrics->saveGuardrailEvent($event);
    }

    public function recordTokenUsage(array $usage): void
    {
        $this->metrics->saveTokenUsage($usage);
    }

    public function summary(string $tenantId, string $projectId, int $days = 7): array
    {
        return $this->metrics->summary($tenantId, $projectId, $days);
    }
}
