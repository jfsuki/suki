<?php
// app/Jobs/AgentNurtureJob.php

namespace App\Jobs;

use App\Core\MemoryRepositoryInterface;
use App\Core\SqlMemoryRepository;

final class AgentNurtureJob
{
    private string $projectRoot;
    private ?MemoryRepositoryInterface $memory = null;

    public function __construct(?string $projectRoot = null, ?MemoryRepositoryInterface $memory = null)
    {
        $this->projectRoot = $projectRoot
            ?? (defined('PROJECT_ROOT') ? PROJECT_ROOT : dirname(__DIR__, 2) . '/project');
        $this->memory = $memory;
    }

    public function run(string $tenantId = 'default', int $maxLines = 200): array
    {
        $tenantId = $tenantId !== '' ? $tenantId : 'default';
        $telemetryDir = $this->projectRoot . '/storage/tenants/' . $this->safe($tenantId) . '/telemetry';
        $tenantDir = $this->projectRoot . '/storage/tenants/' . $this->safe($tenantId);
        $lexiconPath = $this->projectRoot . '/storage/tenants/' . $this->safe($tenantId) . '/lexicon.json';
        $overridePath = $this->projectRoot . '/storage/tenants/' . $this->safe($tenantId) . '/training_overrides.json';
        $countryOverridePath = $tenantDir . '/country_language_overrides.json';
        $trainingPath = dirname(__DIR__, 2) . '/contracts/agents/conversation_training_base.json';
        $globalCountryPath = dirname(__DIR__, 2) . '/contracts/agents/country_language_overrides.json';

        $lexicon = $this->readJson($lexiconPath, [
            'synonyms' => [],
            'shortcuts' => [],
            'stop_phrases' => [],
            'entity_aliases' => [],
            'field_aliases' => [],
        ]);

        $overrides = $this->loadTrainingOverrides($tenantId, $overridePath);
        $countryOverrides = $this->readJson($countryOverridePath, [
            'global' => ['typo_rules' => [], 'synonyms' => []],
            'countries' => [],
            'updated' => date('Y-m-d'),
        ]);
        $globalCountryOverrides = $this->readJson($globalCountryPath, [
            'countries' => [],
        ]);
        $countryHints = $this->countryAliasHints();
        $canonicalTerms = $this->canonicalTerms();
        $baseTraining = $this->readJson($trainingPath, []);
        $baseIntents = [];
        foreach (($baseTraining['intents'] ?? []) as $intent) {
            if (!empty($intent['name'])) {
                $baseIntents[(string) $intent['name']] = true;
            }
        }

        $added = 0;
        $addedUtterances = 0;
        $addedCountryRules = 0;
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
                $rawMessage = (string) ($row['message'] ?? '');
                $message = mb_strtolower($rawMessage, 'UTF-8');
                $country = $this->detectCountryCode($row, $message);
                if (!empty($row['entity']) && !empty($row['message'])) {
                    $entity = (string) $row['entity'];
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

                if ($message !== '') {
                    $countryRules = (array) ($countryOverrides['countries'][$country] ?? []);
                    $countryTypos = is_array($countryRules['typo_rules'] ?? null) ? $countryRules['typo_rules'] : [];
                    $countrySynonyms = is_array($countryRules['synonyms'] ?? null) ? $countryRules['synonyms'] : [];
                    $globalCountrySynonyms = is_array($globalCountryOverrides['countries'][$country]['synonyms'] ?? null)
                        ? $globalCountryOverrides['countries'][$country]['synonyms']
                        : [];
                    $tokens = $this->tokenize($message);

                    foreach ($tokens as $token) {
                        if (isset($countryHints[$country][$token]) && empty($lexicon['field_aliases'][$token])) {
                            $lexicon['field_aliases'][$token] = $countryHints[$country][$token];
                            $countrySynonyms[$token] = $countryHints[$country][$token];
                            $addedCountryRules++;
                        }
                        if (isset($globalCountrySynonyms[$token]) && empty($lexicon['field_aliases'][$token])) {
                            $lexicon['field_aliases'][$token] = (string) $globalCountrySynonyms[$token];
                            $countrySynonyms[$token] = (string) $globalCountrySynonyms[$token];
                            $addedCountryRules++;
                        }
                        if ($this->shouldSkipTokenForTypos($token)) {
                            continue;
                        }
                        foreach ($canonicalTerms as $target) {
                            if ($token === $target) {
                                continue;
                            }
                            if (abs(strlen($token) - strlen($target)) > 1) {
                                continue;
                            }
                            $distance = levenshtein($token, $target);
                            if ($distance === 1 && !$this->hasTypoRule($countryTypos, $token, $target)) {
                                $countryTypos[] = ['match' => $token, 'replace' => $target];
                                $addedCountryRules++;
                                break;
                            }
                        }
                    }

                    $countryRules['typo_rules'] = $countryTypos;
                    $countryRules['synonyms'] = $countrySynonyms;
                    $countryOverrides['countries'][$country] = $countryRules;
                }
            }
        }

        $promoted = $this->promoteSharedKnowledgeToTraining($tenantId, $overrides, $baseIntents);

        $this->writeJson($lexiconPath, $lexicon);
        $overrides['updated'] = date('Y-m-d');
        $this->writeJson($overridePath, $overrides);
        $this->saveTenantMemorySafe($tenantId, 'training_overrides', $overrides);
        $countryOverrides['updated'] = date('Y-m-d');
        $this->writeJson($countryOverridePath, $countryOverrides);
        $this->saveTenantMemorySafe($tenantId, 'country_language_overrides', $countryOverrides);
        $this->saveTenantMemorySafe($tenantId, 'lexicon', $lexicon);

        return [
            'tenant' => $tenantId,
            'added' => $added,
            'added_utterances' => $addedUtterances,
            'added_country_rules' => $addedCountryRules,
            'promoted_shared_utterances' => $promoted,
        ];
    }

    private function canonicalTerms(): array
    {
        return [
            'mixto',
            'contado',
            'credito',
            'cliente',
            'paciente',
            'factura',
            'producto',
            'servicio',
            'tabla',
            'formulario',
            'inventario',
            'cita',
            'proveedor',
            'pago',
        ];
    }

    private function countryAliasHints(): array
    {
        return [
            'CO' => ['cel' => 'telefono', 'celular' => 'telefono', 'nit' => 'documento', 'correo' => 'email'],
            'MX' => ['cel' => 'telefono', 'rfc' => 'documento', 'correo' => 'email'],
            'AR' => ['cel' => 'telefono', 'cuit' => 'documento', 'correo' => 'email'],
            'PE' => ['cel' => 'telefono', 'ruc' => 'documento', 'correo' => 'email'],
            'CL' => ['cel' => 'telefono', 'rut' => 'documento', 'correo' => 'email'],
        ];
    }

    private function detectCountryCode(array $row, string $message): string
    {
        $raw = strtoupper(trim((string) ($row['country'] ?? $row['country_code'] ?? '')));
        if ($raw !== '') {
            return $raw;
        }
        $map = [
            'colombia' => 'CO',
            'mexico' => 'MX',
            'argentina' => 'AR',
            'peru' => 'PE',
            'chile' => 'CL',
            'ecuador' => 'EC',
            'espana' => 'ES',
        ];
        foreach ($map as $needle => $country) {
            if (str_contains($message, $needle)) {
                return $country;
            }
        }
        return 'CO';
    }

    /**
     * @return array<int, string>
     */
    private function tokenize(string $text): array
    {
        $text = mb_strtolower($text, 'UTF-8');
        $text = preg_replace('/[^a-z0-9\\s]/u', ' ', $text) ?? $text;
        $parts = preg_split('/\\s+/', trim($text)) ?: [];
        return array_values(array_filter($parts, static fn(string $v): bool => $v !== ''));
    }

    private function shouldSkipTokenForTypos(string $token): bool
    {
        if ($token === '' || strlen($token) < 4) {
            return true;
        }
        if (preg_match('/^\\d+$/', $token)) {
            return true;
        }
        $blocked = ['crear', 'tabla', 'quiero', 'app', 'programa', 'hacer', 'usar', 'dime'];
        return in_array($token, $blocked, true);
    }

    private function hasTypoRule(array $rules, string $match, string $replace): bool
    {
        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            if ((string) ($rule['match'] ?? '') === $match && (string) ($rule['replace'] ?? '') === $replace) {
                return true;
            }
        }
        return false;
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

    private function loadTrainingOverrides(string $tenantId, string $overridePath): array
    {
        $default = [
            'intents' => [],
            'updated' => date('Y-m-d'),
        ];
        $fileOverrides = $this->readJson($overridePath, $default);
        $memoryOverrides = $this->loadTenantMemorySafe($tenantId, 'training_overrides', []);
        if (empty($memoryOverrides)) {
            return $fileOverrides;
        }
        return $this->mergeTrainingOverrides($fileOverrides, $memoryOverrides);
    }

    private function mergeTrainingOverrides(array $base, array $incoming): array
    {
        if (empty($incoming['intents']) || !is_array($incoming['intents'])) {
            return $base;
        }
        if (!isset($base['intents']) || !is_array($base['intents'])) {
            $base['intents'] = [];
        }
        foreach ($incoming['intents'] as $intentName => $intentCfg) {
            if (!isset($base['intents'][$intentName]) || !is_array($base['intents'][$intentName])) {
                $base['intents'][$intentName] = ['utterances' => []];
            }
            $existing = is_array($base['intents'][$intentName]['utterances'] ?? null)
                ? $base['intents'][$intentName]['utterances']
                : [];
            $extra = is_array($intentCfg['utterances'] ?? null) ? $intentCfg['utterances'] : [];
            foreach ($extra as $utterance) {
                $normalized = $this->normalizeUtterance((string) $utterance);
                if ($this->shouldSkipUtterance($normalized)) {
                    continue;
                }
                if (!in_array($normalized, $existing, true)) {
                    $existing[] = $normalized;
                }
            }
            $base['intents'][$intentName]['utterances'] = array_slice($existing, -120);
        }
        return $base;
    }

    private function promoteSharedKnowledgeToTraining(string $tenantId, array &$overrides, array $baseIntents): int
    {
        $shared = $this->loadTenantMemorySafe($tenantId, 'agent_shared_knowledge', []);
        if (empty($shared)) {
            return 0;
        }

        $sectorIntentMap = [
            'FERRETERIA' => 'SOLVE_UNIT_CONVERSION',
            'FARMACIA' => 'SOLVE_EXPIRY_CONTROL',
            'RESTAURANTE' => 'SOLVE_RECIPE_COSTING',
            'MANTENIMIENTO' => 'SOLVE_MAINTENANCE_OT',
            'PRODUCCION' => 'SOLVE_BATCH_TRACEABILITY',
            'BELLEZA' => 'SOLVE_CLIENT_RETENTION',
        ];

        $promoted = 0;
        $recent = is_array($shared['recent'] ?? null) ? $shared['recent'] : [];
        $recent = array_slice($recent, -160);
        foreach ($recent as $item) {
            if (!is_array($item)) {
                continue;
            }
            $sector = strtoupper((string) ($item['sector_key'] ?? ''));
            $intent = (string) ($sectorIntentMap[$sector] ?? '');
            if ($intent === '' || !isset($baseIntents[$intent])) {
                continue;
            }
            $text = $this->normalizeUtterance((string) ($item['text_excerpt'] ?? ''));
            if ($this->shouldSkipUtterance($text)) {
                continue;
            }
            if ($this->addIntentUtterance($overrides, $intent, $text)) {
                $promoted++;
            }
        }

        $sectors = is_array($shared['sectors'] ?? null) ? $shared['sectors'] : [];
        foreach ($sectors as $sectorKey => $info) {
            $sectorKey = strtoupper((string) $sectorKey);
            $intent = (string) ($sectorIntentMap[$sectorKey] ?? '');
            if ($intent === '' || !isset($baseIntents[$intent])) {
                continue;
            }
            $hits = is_array($info) ? (int) ($info['hits'] ?? 0) : 0;
            if ($hits < 1) {
                continue;
            }
            $synthetic = match ($sectorKey) {
                'FERRETERIA' => 'mi negocio es ferreteria y vendo por metros',
                'FARMACIA' => 'mi negocio es farmacia y necesito control fefo',
                'RESTAURANTE' => 'mi restaurante necesita escandallo y mermas',
                'MANTENIMIENTO' => 'quiero mini app de ordenes de trabajo',
                'PRODUCCION' => 'necesito trazabilidad de lotes en produccion',
                'BELLEZA' => 'quiero retener clientes con recordatorios',
                default => '',
            };
            if ($synthetic === '') {
                continue;
            }
            if ($this->addIntentUtterance($overrides, $intent, $synthetic)) {
                $promoted++;
            }
        }

        return $promoted;
    }

    private function addIntentUtterance(array &$overrides, string $intent, string $utterance): bool
    {
        $utterance = $this->normalizeUtterance($utterance);
        if ($this->shouldSkipUtterance($utterance)) {
            return false;
        }
        if (!isset($overrides['intents']) || !is_array($overrides['intents'])) {
            $overrides['intents'] = [];
        }
        if (!isset($overrides['intents'][$intent]) || !is_array($overrides['intents'][$intent])) {
            $overrides['intents'][$intent] = ['utterances' => []];
        }
        if (!isset($overrides['intents'][$intent]['utterances']) || !is_array($overrides['intents'][$intent]['utterances'])) {
            $overrides['intents'][$intent]['utterances'] = [];
        }
        if (in_array($utterance, $overrides['intents'][$intent]['utterances'], true)) {
            return false;
        }
        $overrides['intents'][$intent]['utterances'][] = $utterance;
        $overrides['intents'][$intent]['utterances'] = array_slice(
            $overrides['intents'][$intent]['utterances'],
            -120
        );
        return true;
    }

    private function memory(): MemoryRepositoryInterface
    {
        if ($this->memory === null) {
            $this->memory = new SqlMemoryRepository();
        }
        return $this->memory;
    }

    private function loadTenantMemorySafe(string $tenantId, string $key, array $default): array
    {
        try {
            return $this->memory()->getTenantMemory($tenantId, $key, $default);
        } catch (\Throwable $e) {
            return $default;
        }
    }

    private function saveTenantMemorySafe(string $tenantId, string $key, array $value): void
    {
        try {
            $this->memory()->saveTenantMemory($tenantId, $key, $value);
        } catch (\Throwable $e) {
            // Memory persistence is best-effort for nurture jobs.
        }
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
