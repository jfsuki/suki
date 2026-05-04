<?php
// app/Jobs/AgentNurtureJob.php

namespace App\Jobs;

use App\Core\MemoryRepositoryInterface;
use App\Core\SqlMemoryRepository;

/**
 * AgentNurtureJob — learns from telemetry and nurtures the intent classifier.
 *
 * Config (entity aliases, typo rules, stop phrases) → lexicon.json / country_overrides.json
 * Training utterances → training_log SQLite → TrainingPromoter → Qdrant (semantic)
 *
 * No hardcoded word lists. The LLM and Qdrant handle semantic nuance.
 */
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
        $tenantDir    = $this->projectRoot . '/storage/tenants/' . $this->safe($tenantId);
        $lexiconPath  = $tenantDir . '/lexicon.json';
        $countryOverridePath = $tenantDir . '/country_language_overrides.json';
        $trainingPath = dirname(__DIR__, 2) . '/contracts/agents/conversation_training_base.json';
        $globalCountryPath = dirname(__DIR__, 2) . '/contracts/agents/country_language_overrides.json';

        $lexicon = $this->readJson($lexiconPath, [
            'synonyms'      => [],
            'shortcuts'     => [],
            'stop_phrases'  => [],
            'entity_aliases' => [],
            'field_aliases' => [],
        ]);

        $countryOverrides = $this->readJson($countryOverridePath, [
            'global'   => ['typo_rules' => [], 'synonyms' => []],
            'countries' => [],
            'updated'  => date('Y-m-d'),
        ]);
        $globalCountryOverrides = $this->readJson($globalCountryPath, ['countries' => []]);
        $countryHints   = $this->countryAliasHints();
        $canonicalTerms = $this->canonicalTerms();

        $baseTraining = $this->readJson($trainingPath, []);
        $baseIntents  = [];
        foreach (($baseTraining['intents'] ?? []) as $intent) {
            if (!empty($intent['name'])) {
                $baseIntents[(string) $intent['name']] = true;
            }
        }

        $stopPhrases = is_array($lexicon['stop_phrases'] ?? null)
            ? array_map('strval', $lexicon['stop_phrases'])
            : [];

        $added             = 0;
        $addedUtterances   = 0;
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
                $message    = mb_strtolower($rawMessage, 'UTF-8');
                $country    = $this->detectCountryCode($row, $message);

                // Config: entity aliases — stays in lexicon.json
                if (!empty($row['entity']) && $message !== '') {
                    if (preg_match('/^[a-z0-9_\\-]{3,}$/', $message) && !isset($lexicon['entity_aliases'][$message])) {
                        $lexicon['entity_aliases'][$message] = (string) $row['entity'];
                        $added++;
                    }
                }

                // Training: intent utterances → training_log → Qdrant
                if (!empty($row['intent']) && !empty($row['message']) && !empty($row['resolved_locally'])) {
                    $intent     = (string) $row['intent'];
                    $normalized = $this->normalizeUtterance((string) $row['message']);
                    if (isset($baseIntents[$intent]) && !$this->shouldSkipUtterance($normalized, $stopPhrases)) {
                        if ($this->writeUtteranceToTrainingLog($tenantId, $intent, $normalized)) {
                            $addedUtterances++;
                        }
                    }
                }

                // Config: typo rules and synonyms — stays in country_overrides.json
                if ($message !== '') {
                    $countryRules    = (array) ($countryOverrides['countries'][$country] ?? []);
                    $countryTypos    = is_array($countryRules['typo_rules'] ?? null) ? $countryRules['typo_rules'] : [];
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
                            if ($token === $target || abs(strlen($token) - strlen($target)) > 1) {
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
                    $countryRules['synonyms']   = $countrySynonyms;
                    $countryOverrides['countries'][$country] = $countryRules;
                }
            }
        }

        // Shared knowledge → training_log → Qdrant
        $promoted = $this->promoteSharedKnowledgeToTrainingLog($tenantId, $baseIntents, $stopPhrases);

        // Persist config (lexicon + country overrides) — NOT training utterances
        $this->writeJson($lexiconPath, $lexicon);
        $countryOverrides['updated'] = date('Y-m-d');
        $this->writeJson($countryOverridePath, $countryOverrides);
        $this->saveTenantMemorySafe($tenantId, 'country_language_overrides', $countryOverrides);
        $this->saveTenantMemorySafe($tenantId, 'lexicon', $lexicon);

        return [
            'tenant'                     => $tenantId,
            'added'                      => $added,
            'added_utterances'           => $addedUtterances,
            'added_country_rules'        => $addedCountryRules,
            'promoted_shared_utterances' => $promoted,
        ];
    }

    // ---------------------------------------------------------------------------
    // Training log (→ Qdrant via TrainingPromoter)
    // ---------------------------------------------------------------------------

    private function writeUtteranceToTrainingLog(string $tenantId, string $intent, string $text): bool
    {
        try {
            $dbPath = $this->resolveTrainingDbPath();
            $db = new \PDO('sqlite:' . $dbPath);
            $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_SILENT);
            $db->exec('CREATE TABLE IF NOT EXISTS training_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_text TEXT,
                intent_classified TEXT,
                llm_score REAL,
                status TEXT DEFAULT \'pending\',
                tenant_id TEXT,
                created_at TEXT
            )');
            $db->exec("ALTER TABLE training_log ADD COLUMN tenant_id TEXT");

            // Dedup: skip if same utterance+tenant already exists
            $check = $db->prepare('SELECT COUNT(*) FROM training_log WHERE user_text = ? AND tenant_id = ?');
            $check->execute([$text, $tenantId]);
            if ((int) $check->fetchColumn() > 0) {
                return false;
            }

            $db->prepare(
                'INSERT INTO training_log (user_text, intent_classified, llm_score, status, tenant_id, created_at)
                 VALUES (?, ?, ?, \'pending\', ?, ?)'
            )->execute([$text, $intent, 0.8, $tenantId, date('c')]);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function resolveTrainingDbPath(): string
    {
        $dir = $this->projectRoot . '/storage/meta';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        return $dir . '/intent_training_log.sqlite';
    }

    private function promoteSharedKnowledgeToTrainingLog(string $tenantId, array $baseIntents, array $stopPhrases): int
    {
        $shared = $this->loadTenantMemorySafe($tenantId, 'agent_shared_knowledge', []);
        if (empty($shared)) {
            return 0;
        }

        $sectorIntentMap = [
            'FERRETERIA'    => 'SOLVE_UNIT_CONVERSION',
            'FARMACIA'      => 'SOLVE_EXPIRY_CONTROL',
            'RESTAURANTE'   => 'SOLVE_RECIPE_COSTING',
            'MANTENIMIENTO' => 'SOLVE_MAINTENANCE_OT',
            'PRODUCCION'    => 'SOLVE_BATCH_TRACEABILITY',
            'BELLEZA'       => 'SOLVE_CLIENT_RETENTION',
        ];

        $promoted = 0;

        $recent = array_slice(is_array($shared['recent'] ?? null) ? $shared['recent'] : [], -160);
        foreach ($recent as $item) {
            if (!is_array($item)) continue;
            $sector = strtoupper((string) ($item['sector_key'] ?? ''));
            $intent = (string) ($sectorIntentMap[$sector] ?? '');
            if ($intent === '' || !isset($baseIntents[$intent])) continue;
            $text = $this->normalizeUtterance((string) ($item['text_excerpt'] ?? ''));
            if (!$this->shouldSkipUtterance($text, $stopPhrases) && $this->writeUtteranceToTrainingLog($tenantId, $intent, $text)) {
                $promoted++;
            }
        }

        foreach ((is_array($shared['sectors'] ?? null) ? $shared['sectors'] : []) as $sectorKey => $info) {
            $sectorKey = strtoupper((string) $sectorKey);
            $intent    = (string) ($sectorIntentMap[$sectorKey] ?? '');
            if ($intent === '' || !isset($baseIntents[$intent])) continue;
            if (!is_array($info) || (int) ($info['hits'] ?? 0) < 1) continue;

            // Synthetic example derived from sector context — not hardcoded phrases
            $sectorLabel = strtolower($sectorKey);
            $synthetic   = "mi negocio es de {$sectorLabel} y necesito ayuda con " . strtolower($intent);
            $synthetic   = $this->normalizeUtterance($synthetic);
            if (!$this->shouldSkipUtterance($synthetic, $stopPhrases) && $this->writeUtteranceToTrainingLog($tenantId, $intent, $synthetic)) {
                $promoted++;
            }
        }

        return $promoted;
    }

    // ---------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------

    private function canonicalTerms(): array
    {
        return [
            'mixto', 'contado', 'credito', 'cliente', 'paciente',
            'factura', 'producto', 'servicio', 'tabla', 'formulario',
            'inventario', 'cita', 'proveedor', 'pago',
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
        if ($raw !== '') return $raw;
        $map = [
            'colombia' => 'CO', 'mexico' => 'MX', 'argentina' => 'AR',
            'peru' => 'PE', 'chile' => 'CL', 'ecuador' => 'EC', 'espana' => 'ES',
        ];
        foreach ($map as $needle => $country) {
            if (str_contains($message, $needle)) return $country;
        }
        return 'CO';
    }

    /** @return array<int, string> */
    private function tokenize(string $text): array
    {
        $text  = mb_strtolower($text, 'UTF-8');
        $text  = preg_replace('/[^a-z0-9\\s]/u', ' ', $text) ?? $text;
        $parts = preg_split('/\\s+/', trim($text)) ?: [];
        return array_values(array_filter($parts, static fn(string $v): bool => $v !== ''));
    }

    private function shouldSkipTokenForTypos(string $token): bool
    {
        if ($token === '' || strlen($token) < 4) return true;
        if (preg_match('/^\\d+$/', $token)) return true;
        $blocked = ['crear', 'tabla', 'quiero', 'app', 'programa', 'hacer', 'usar', 'dime'];
        return in_array($token, $blocked, true);
    }

    private function hasTypoRule(array $rules, string $match, string $replace): bool
    {
        foreach ($rules as $rule) {
            if (!is_array($rule)) continue;
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

    private function shouldSkipUtterance(string $text, array $stopPhrases = []): bool
    {
        if ($text === '' || mb_strlen($text, 'UTF-8') < 3) return true;
        if (preg_match('/^\\d+$/', $text)) return true;
        // Dynamic stop phrases from lexicon — no hardcoded word lists
        return !empty($stopPhrases) && in_array($text, $stopPhrases, true);
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
        } catch (\Throwable) {
            return $default;
        }
    }

    private function saveTenantMemorySafe(string $tenantId, string $key, array $value): void
    {
        try {
            $this->memory()->saveTenantMemory($tenantId, $key, $value);
        } catch (\Throwable) {
            // Best-effort
        }
    }

    private function readJson(string $path, array $default): array
    {
        if (!is_file($path)) {
            $this->writeJson($path, $default);
            return $default;
        }
        $raw     = file_get_contents($path);
        $decoded = json_decode($raw ?: '', true);
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
