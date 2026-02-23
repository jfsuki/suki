<?php
// app/Core/MetricsRepositoryInterface.php

namespace App\Core;

interface MetricsRepositoryInterface
{
    public function saveIntentMetric(array $metric): void;

    public function saveCommandMetric(array $metric): void;

    public function saveGuardrailEvent(array $event): void;

    public function saveTokenUsage(array $usage): void;

    public function summary(string $tenantId, string $projectId, int $days = 7): array;
}
