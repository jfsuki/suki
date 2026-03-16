<?php

declare(strict_types=1);

namespace App\Core;

use JsonException;
use Opis\JsonSchema\Validator;
use RuntimeException;

final class ControlTowerArtifactValidator
{
    /** @var array<string, string> */
    private const SCHEMA_MAP = [
        'control_tower_run' => FRAMEWORK_ROOT . '/contracts/schemas/control_tower_run.schema.json',
        'sprint_ticket' => FRAMEWORK_ROOT . '/contracts/schemas/sprint_ticket.schema.json',
        'coder_proposal' => FRAMEWORK_ROOT . '/contracts/schemas/coder_proposal.schema.json',
        'review_decision' => FRAMEWORK_ROOT . '/contracts/schemas/review_decision.schema.json',
        'file_registry' => FRAMEWORK_ROOT . '/contracts/schemas/file_registry.schema.json',
        'agentops_incident' => FRAMEWORK_ROOT . '/contracts/schemas/agentops_incident.schema.json',
        'production_critic_finding' => FRAMEWORK_ROOT . '/contracts/schemas/production_critic_finding.schema.json',
        'checkpoint_state' => FRAMEWORK_ROOT . '/contracts/schemas/checkpoint_state.schema.json',
        'sprint_decision' => FRAMEWORK_ROOT . '/contracts/schemas/sprint_decision.schema.json',
        'sprint_status_summary' => FRAMEWORK_ROOT . '/contracts/schemas/sprint_status_summary.schema.json',
    ];

    /**
     * @return array<int, string>
     */
    public static function artifactKeys(): array
    {
        return array_keys(self::SCHEMA_MAP);
    }

    public static function schemaPath(string $artifactType): string
    {
        $artifactType = trim($artifactType);
        if ($artifactType === '' || !isset(self::SCHEMA_MAP[$artifactType])) {
            throw new RuntimeException('Artifacto Control Tower no soportado: ' . $artifactType);
        }

        return self::SCHEMA_MAP[$artifactType];
    }

    public static function validateOrFail(string $artifactType, array $payload, ?string $schemaPath = null): void
    {
        $schemaPath = $schemaPath ?? self::schemaPath($artifactType);
        if (!is_file($schemaPath)) {
            throw new RuntimeException('Schema Control Tower no existe: ' . $schemaPath);
        }

        $schema = json_decode((string) file_get_contents($schemaPath));
        if (!$schema) {
            throw new RuntimeException('Schema Control Tower invalido: ' . $schemaPath);
        }

        try {
            $payloadObject = json_decode(
                json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                false,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException $e) {
            throw new RuntimeException('Payload Control Tower no serializable.', 0, $e);
        }

        $validator = new Validator();
        $result = $validator->validate($payloadObject, $schema);
        if (!$result->isValid()) {
            $error = $result->error();
            $message = $error ? $error->message() : 'Payload Control Tower invalido.';
            throw new RuntimeException($message);
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function buildSprintStatusSummary(array $payload): array
    {
        $tenantId = self::requireString($payload['tenant_id'] ?? null, 'tenant_id');
        $projectId = self::requireString($payload['project_id'] ?? null, 'project_id');
        $runId = self::requireString($payload['run_id'] ?? null, 'run_id');
        $sprintId = self::requireString($payload['sprint_id'] ?? null, 'sprint_id');
        $appId = trim((string) ($payload['app_id'] ?? ''));
        if ($appId === '') {
            $appId = $projectId;
        }

        $summary = [
            'artifact_type' => 'sprint_status_summary',
            'schema_version' => '1.0.0',
            'tenant_id' => $tenantId,
            'project_id' => $projectId,
            'app_id' => $appId,
            'run_id' => $runId,
            'sprint_id' => $sprintId,
            'summary_id' => trim((string) ($payload['summary_id'] ?? '')) !== ''
                ? trim((string) $payload['summary_id'])
                : 'sprint_status_summary_' . $runId,
            'sprint_status' => self::normalizeSprintStatus($payload['sprint_status'] ?? null),
            'files_modified' => self::normalizeStringList($payload['files_modified'] ?? []),
            'risks_detected' => self::normalizeStringList($payload['risks_detected'] ?? []),
            'temporaries_created' => self::normalizeStringList($payload['temporaries_created'] ?? []),
            'backups_created' => self::normalizeStringList($payload['backups_created'] ?? []),
            'incidents_detected' => self::normalizeStringList($payload['incidents_detected'] ?? []),
            'next_steps' => self::normalizeStringList($payload['next_steps'] ?? []),
            'counts' => [],
            'generated_at' => trim((string) ($payload['generated_at'] ?? '')) !== ''
                ? trim((string) $payload['generated_at'])
                : date('c'),
        ];

        $summary['counts'] = [
            'files_modified' => count($summary['files_modified']),
            'risks_detected' => count($summary['risks_detected']),
            'temporaries_created' => count($summary['temporaries_created']),
            'backups_created' => count($summary['backups_created']),
            'incidents_detected' => count($summary['incidents_detected']),
            'next_steps' => count($summary['next_steps']),
        ];

        self::validateOrFail('sprint_status_summary', $summary);
        return $summary;
    }

    private static function requireString(mixed $value, string $field): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            throw new RuntimeException(strtoupper($field) . '_REQUIRED');
        }

        return $value;
    }

    /**
     * @param mixed $value
     * @return array<int, string>
     */
    private static function normalizeStringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            $candidate = trim((string) $item);
            if ($candidate === '') {
                continue;
            }
            $items[] = $candidate;
        }

        if ($items === []) {
            return [];
        }

        return array_values(array_unique($items));
    }

    private static function normalizeSprintStatus(mixed $value): string
    {
        $candidate = strtoupper(trim((string) $value));
        if (in_array($candidate, ['CLOSED', 'CONTINUE', 'BLOCKED', 'NEEDS_REVIEW'], true)) {
            return $candidate;
        }

        return 'NEEDS_REVIEW';
    }
}
