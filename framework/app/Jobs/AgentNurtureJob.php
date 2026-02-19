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
        $overridePath = $this->projectRoot . '/storage/tenants/' . $this->safe($tenantId) . '/training_overrides.json';
        $trainingPath = dirname(__DIR__, 2) . '/contracts/agents/conversation_training_base.json';

        $lexicon = $this->readJson($lexiconPath, [
            'synonyms' => [],
            'shortcuts' => [],
            'stop_phrases' => [],
            'entity_aliases' => [],
            'field_aliases' => [],
        ]);

        $overrides = $this->readJson($overridePath, [
            'intents' => [],
            'updated' => date('Y-m-d'),
        ]);
        $baseTraining = $this->readJson($trainingPath, []);
        $baseIntents = [];
        foreach (($baseTraining['intents'] ?? []) as $intent) {
            if (!empty($intent['name'])) {
                $baseIntents[(string) $intent['name']] = true;
            }
        }

        $added = 0;
        $addedUtterances = 0;
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
                if (!empty($row['intent']) && !empty($row['message']) && !empty($row['resolved_locally'])) {
                    $intent = (string) $row['intent'];
                    if (!isset($baseIntents[$intent])) {
                        continue;
                    }
                    $normalized = $this->normalizeUtterance((string) $row['message']);
                    if ($this->shouldSkipUtterance($normalized)) {
                        continue;
                    }
                    if (!isset($overrides['intents'][$intent])) {
                        $overrides['intents'][$intent] = ['utterances' => []];
                    }
                    if (!isset($overrides['intents'][$intent]['utterances'])) {
                        $overrides['intents'][$intent]['utterances'] = [];
                    }
                    if (!in_array($normalized, $overrides['intents'][$intent]['utterances'], true)) {
                        $overrides['intents'][$intent]['utterances'][] = $normalized;
                        $overrides['intents'][$intent]['utterances'] = array_slice(
                            $overrides['intents'][$intent]['utterances'],
                            -60
                        );
                        $addedUtterances++;
                    }
                }
            }
        }

        $this->writeJson($lexiconPath, $lexicon);
        $overrides['updated'] = date('Y-m-d');
        $this->writeJson($overridePath, $overrides);

        return [
            'tenant' => $tenantId,
            'added' => $added,
            'added_utterances' => $addedUtterances,
        ];
    }

    private function normalizeUtterance(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');
        $text = preg_replace('/[^a-z0-9ñáéíóúü\\s]/u', ' ', $text) ?? $text;
        $text = preg_replace('/\\s+/', ' ', trim($text)) ?? $text;
        return $text;
    }

    private function shouldSkipUtterance(string $text): bool
    {
        if ($text === '' || mb_strlen($text, 'UTF-8') < 4) {
            return true;
        }
        $stop = ['hola', 'buenas', 'ok', 'listo', 'gracias', 'thanks'];
        if (in_array($text, $stop, true)) {
            return true;
        }
        if (preg_match('/^\\d+$/', $text)) {
            return true;
        }
        return false;
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
