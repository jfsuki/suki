<?php
// app/Jobs/AgentNurtureJob.php

namespace App\Jobs;

final class AgentNurtureJob
{
    private string $projectRoot;

    public function __construct(?string $projectRoot = null)
    {
        $this->projectRoot = $projectRoot
            ?? (defined('PROJECT_ROOT') ? PROJECT_ROOT : dirname(__DIR__, 2) . '/project');
    }

    public function run(string $tenantId = 'default', int $maxLines = 200): array
    {
        $tenantId = $tenantId !== '' ? $tenantId : 'default';
        $telemetryDir = $this->projectRoot . '/storage/tenants/' . $this->safe($tenantId) . '/telemetry';
        $lexiconPath = $this->projectRoot . '/storage/tenants/' . $this->safe($tenantId) . '/lexicon.json';

        $lexicon = $this->readJson($lexiconPath, [
            'synonyms' => [],
            'shortcuts' => [],
            'stop_phrases' => [],
            'entity_aliases' => [],
            'field_aliases' => [],
        ]);

        $added = 0;
        $files = glob($telemetryDir . '/*.jsonl') ?: [];
        rsort($files);
        foreach ($files as $file) {
            $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            $lines = array_slice($lines, -$maxLines);
            foreach ($lines as $line) {
                $row = json_decode($line, true);
                if (!is_array($row)) {
                    continue;
                }
                if (!empty($row['entity']) && !empty($row['message'])) {
                    $entity = (string) $row['entity'];
                    $message = mb_strtolower((string) $row['message']);
                    if ($message !== '' && !isset($lexicon['entity_aliases'][$message])) {
                        // Heuristica simple: si el mensaje es una sola palabra, lo mapea a entidad.
                        if (preg_match('/^[a-z0-9_\\-]{3,}$/', $message)) {
                            $lexicon['entity_aliases'][$message] = $entity;
                            $added++;
                        }
                    }
                }
            }
        }

        $this->writeJson($lexiconPath, $lexicon);

        return [
            'tenant' => $tenantId,
            'added' => $added,
        ];
    }

    private function readJson(string $path, array $default): array
    {
        if (!is_file($path)) {
            $this->writeJson($path, $default);
            return $default;
        }
        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            return $default;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : $default;
    }

    private function writeJson(string $path, array $data): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $payload = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload !== false) {
            file_put_contents($path, $payload, LOCK_EX);
        }
    }

    private function safe(string $value): string
    {
        $value = preg_replace('/[^a-zA-Z0-9_\\-]/', '_', $value) ?? 'default';
        return trim($value, '_');
    }
}
