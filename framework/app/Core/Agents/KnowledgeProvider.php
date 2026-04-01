<?php
// framework/app/Core/Agents/KnowledgeProvider.php

namespace App\Core\Agents;

use App\Core\MemoryRepositoryInterface;
use RuntimeException;

/**
 * Provee conocimiento especializado de industria (UNSPSC, Contabilidad, Playbooks)
 * extraido del ConversationGateway para reducir su complejidad.
 */
class KnowledgeProvider
{
    private string $projectRoot;
    private MemoryRepositoryInterface $memory;
    
    // Caches internos para evitar recargas constantes de JSON
    private ?array $domainPlaybookCache = null;
    private ?array $accountingKnowledgeCache = null;
    private ?array $unspscCommonCache = null;
    private ?array $latamLexiconCache = null;
    private ?array $countryOverridesCache = null;
    private ?array $confusionBaseCache = null;
    private ?array $fiscalPlaybookCache = null;
    private ?object $workingMemorySchemaCache = null;

    public function __construct(string $projectRoot, MemoryRepositoryInterface $memory)
    {
        $this->projectRoot = $projectRoot;
        $this->memory = $memory;
    }

    public function loadDomainPlaybook(): array
    {
        if ($this->domainPlaybookCache !== null) {
            return $this->domainPlaybookCache;
        }
        $frameworkRoot = defined('FRAMEWORK_ROOT') ? FRAMEWORK_ROOT : dirname(__DIR__, 3);
        $frameworkPath = $frameworkRoot . '/contracts/agents/domain_playbooks.json';
        if (!is_file($frameworkPath)) {
            return [];
        }
        $raw = file_get_contents($frameworkPath);
        if ($raw === false || $raw === '') {
            return [];
        }
        $raw = ltrim($raw, "\xEF\xBB\xBF");
        $decoded = json_decode($raw, true);
        $base = is_array($decoded) ? $decoded : [];

        $projectPath = $this->projectRoot . '/contracts/knowledge/domain_playbooks.json';
        if (is_file($projectPath)) {
            $projectOverride = $this->readJson($projectPath, []);
            if (!empty($projectOverride)) {
                foreach ([
                    'solver_intents',
                    'sector_playbooks',
                    'knowledge_prompt_template',
                    'builder_guidance',
                    'guided_conversation_flows',
                    'discovery',
                    'unknown_business_protocol',
                    'profiles'
                ] as $key) {
                    if (isset($projectOverride[$key]) && is_array($projectOverride[$key])) {
                        $base[$key] = $projectOverride[$key];
                    }
                }
            }
        }

        $this->domainPlaybookCache = $base;
        return $base;
    }

    public function loadAccountingKnowledge(): array
    {
        if ($this->accountingKnowledgeCache !== null) {
            return $this->accountingKnowledgeCache;
        }
        $frameworkRoot = defined('FRAMEWORK_ROOT') ? FRAMEWORK_ROOT : dirname(__DIR__, 3);
        $path = $frameworkRoot . '/contracts/agents/accounting_tax_knowledge_co.json';
        if (!is_file($path)) {
            return [];
        }
        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            return [];
        }
        $raw = ltrim($raw, "\xEF\xBB\xBF");
        $decoded = json_decode($raw, true);
        $this->accountingKnowledgeCache = is_array($decoded) ? $decoded : [];
        return $this->accountingKnowledgeCache;
    }

    public function loadFiscalPlaybook(): array
    {
        if ($this->fiscalPlaybookCache !== null) {
            return $this->fiscalPlaybookCache;
        }
        
        $path = $this->projectRoot . '/contracts/playbooks/colombian_fiscal_playbook_2026.contract.json';
        if (!is_file($path)) {
            return [];
        }
        
        $decoded = $this->readJson($path, []);
        $this->fiscalPlaybookCache = $decoded;
        return $decoded;
    }

    public function loadUnspscCommon(): array
    {
        if ($this->unspscCommonCache !== null) {
            return $this->unspscCommonCache;
        }
        $frameworkRoot = defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 3);
        $path = $frameworkRoot . '/contracts/agents/unspsc_co_common.json';
        if (!is_file($path)) {
            return [];
        }
        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            return [];
        }
        $raw = ltrim($raw, "\xEF\xBB\xBF");
        $decoded = json_decode($raw, true);
        $this->unspscCommonCache = is_array($decoded) ? $decoded : [];
        return $this->unspscCommonCache;
    }

    public function matchUnspscItems(string $text, array $knowledge): array
    {
        $items = is_array($knowledge['common_codes'] ?? null) ? $knowledge['common_codes'] : [];
        if (empty($items)) {
            return [];
        }
        $matches = [];
        foreach ($items as $item) {
            if (!is_array($item)) continue;
            $score = 0;
            $lexicalMatch = false;
            $code = (string) ($item['code'] ?? '');
            $name = $this->normalizeText((string) ($item['name_es'] ?? ''));
            if ($code !== '' && str_contains($text, $code)) {
                $score += 100;
                $lexicalMatch = true;
            }
            if ($name !== '' && str_contains($text, $name)) {
                $score += 40;
                $lexicalMatch = true;
            }
            $aliases = is_array($item['aliases'] ?? null) ? $item['aliases'] : [];
            foreach ($aliases as $alias) {
                $aliasNorm = $this->normalizeText((string) $alias);
                if ($aliasNorm !== '' && str_contains($text, $aliasNorm)) {
                    $score += 25;
                    $lexicalMatch = true;
                }
            }
            $tags = is_array($item['business_tags'] ?? null) ? $item['business_tags'] : [];
            foreach ($tags as $tag) {
                $tagNorm = $this->normalizeText(str_replace('_', ' ', (string) $tag));
                if ($tagNorm !== '' && str_contains($text, $tagNorm)) {
                    $score += $lexicalMatch ? 3 : 1;
                }
            }
            if ($score > 0) {
                $item['_score'] = $score;
                $matches[] = $item;
            }
        }
        usort($matches, fn($a, $b) => ($b['_score'] ?? 0) <=> ($a['_score'] ?? 0));
        return $matches;
    }

    public function recommendedUnspscByBusiness(string $businessType, array $knowledge): array
    {
        $reco = is_array($knowledge['business_type_recommendations'] ?? null) ? $knowledge['business_type_recommendations'] : [];
        $codes = [];
        if ($businessType !== '' && is_array($reco[$businessType] ?? null)) {
            $codes = (array) $reco[$businessType];
        }
        if (empty($codes) && is_array($reco['default'] ?? null)) {
            $codes = (array) $reco['default'];
        }
        if (empty($codes)) return [];

        $items = is_array($knowledge['common_codes'] ?? null) ? $knowledge['common_codes'] : [];
        $byCode = [];
        foreach ($items as $item) {
            if (!is_array($item)) continue;
            $code = (string) ($item['code'] ?? '');
            if ($code !== '') $byCode[$code] = $item;
        }
        $result = [];
        foreach ($codes as $code) {
            $code = (string) $code;
            if ($code !== '' && isset($byCode[$code])) {
                $result[] = $byCode[$code];
            }
        }
        return $result;
    }

    public function mergeAccountingEntities(array $entities, string $businessType, array $accounting, string $operationModel = 'mixto'): array
    {
        $businessType = $this->normalizeBusinessType($businessType);
        $operationModel = $this->normalizeOperationModel($operationModel);
        $base = is_array($accounting['minimum_entities']['default'] ?? null) ? $accounting['minimum_entities']['default'] : [];
        $business = is_array($accounting['minimum_entities_by_business'][$businessType] ?? null) ? $accounting['minimum_entities_by_business'][$businessType] : [];
        
        if (empty($business)) {
            if (str_contains($businessType, 'servicio')) {
                $business = is_array($accounting['minimum_entities_by_business']['servicios'] ?? null) ? $accounting['minimum_entities_by_business']['servicios'] : [];
            } elseif (str_contains($businessType, 'tienda') || str_contains($businessType, 'producto')) {
                $business = is_array($accounting['minimum_entities_by_business']['productos'] ?? null) ? $accounting['minimum_entities_by_business']['productos'] : [];
            }
        }
        
        $byOperation = is_array($accounting['operation_model_entities'][$operationModel] ?? null) ? $accounting['operation_model_entities'][$operationModel] : [];
        $all = array_merge($entities, $base, $business, $byOperation);
        return array_values(array_filter(array_unique(array_map('strval', $all))));
    }

    public function normalizeBusinessType(string $businessType): string
    {
        $businessType = strtolower(trim($businessType));
        if ($businessType === '') return '';
        
        $playbook = $this->loadDomainPlaybook();
        $profiles = is_array($playbook['profiles'] ?? null) ? $playbook['profiles'] : [];
        foreach ($profiles as $profile) {
            $key = strtolower((string) ($profile['key'] ?? ''));
            if ($key === '') continue;
            if ($businessType === $key || str_contains($businessType, $key)) return $key;
            
            $label = strtolower((string) ($profile['label'] ?? ''));
            if ($label !== '' && str_contains($businessType, $label)) return $key;
            
            $aliases = is_array($profile['aliases'] ?? null) ? $profile['aliases'] : [];
            foreach ($aliases as $alias) {
                $alias = strtolower((string) $alias);
                if ($alias !== '' && str_contains($businessType, $alias)) return $key;
            }
        }
        if (str_contains($businessType, 'servicio')) return 'servicios_mantenimiento';
        if (str_contains($businessType, 'producto') || str_contains($businessType, 'tienda')) return 'retail_tienda';
        return $businessType;
    }

    public function normalizeOperationModel(string $value): string
    {
        $value = strtolower(trim($value));
        if ($value === '' || in_array($value, ['mixto', 'ambos'], true)) return 'mixto';
        if (str_contains($value, 'credito')) return 'credito';
        if (str_contains($value, 'contado')) return 'contado';
        return 'mixto';
    }

    public function domainLabelByBusinessType(string $businessType): string
    {
        $businessType = $this->normalizeBusinessType($businessType);
        $playbook = $this->loadDomainPlaybook();
        $profiles = is_array($playbook['profiles'] ?? null) ? $playbook['profiles'] : [];
        
        foreach ($profiles as $profile) {
            if (strtolower((string)($profile['key'] ?? '')) === $businessType) {
                return (string)($profile['label'] ?? ucfirst($businessType));
            }
        }
        
        return ucfirst(str_replace('_', ' ', $businessType ?: 'tu negocio'));
    }

    public function findDomainProfile(string $businessType, array $playbook = []): array
    {
        if (empty($playbook)) {
            $playbook = $this->loadDomainPlaybook();
        }
        $profiles = is_array($playbook['profiles'] ?? null) ? $playbook['profiles'] : [];
        foreach ($profiles as $profile) {
            if (strtolower((string) ($profile['key'] ?? '')) === $businessType) {
                return $profile;
            }
        }
        return [];
    }

    public function loadLatamLexiconPack(string $tenantId = 'default'): array
    {
        $frameworkRoot = defined('FRAMEWORK_ROOT') ? FRAMEWORK_ROOT : dirname(__DIR__, 3);
        $basePath = $frameworkRoot . '/contracts/agents/latam_es_col_conversation_lexicon.json';
        $tenantPath = $this->projectRoot . '/storage/tenants/' . $this->safe($tenantId) . '/latam_lexicon_overrides.json';
        $baseMtime = is_file($basePath) ? (int) @filemtime($basePath) : 0;
        $cacheKey = $this->safe($tenantId);

        $tenant = $this->memory->getTenantMemory($tenantId, 'latam_lexicon_overrides', []);
        if (empty($tenant) && is_file($tenantPath)) {
            $tenant = $this->readJson($tenantPath, []);
            if (!empty($tenant)) {
                $this->memory->saveTenantMemory($tenantId, 'latam_lexicon_overrides', $tenant);
            }
        }
        $tenantHashSource = json_encode($tenant, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $tenantHash = is_string($tenantHashSource) ? sha1($tenantHashSource) : '';

        if (isset($this->latamLexiconCache[$cacheKey])) {
            $cached = $this->latamLexiconCache[$cacheKey];
            if (($cached['base_mtime'] ?? 0) === $baseMtime && ($cached['tenant_hash'] ?? '') === $tenantHash) {
                return is_array($cached['data'] ?? null) ? $cached['data'] : [];
            }
        }

        $base = $this->readJson($basePath, []);
        $merged = [
            'phrase_rules' => array_merge(
                is_array($base['phrase_rules'] ?? null) ? $base['phrase_rules'] : [],
                is_array($tenant['phrase_rules'] ?? null) ? $tenant['phrase_rules'] : []
            ),
            'synonyms' => array_merge(
                is_array($base['synonyms'] ?? null) ? $base['synonyms'] : [],
                is_array($tenant['synonyms'] ?? null) ? $tenant['synonyms'] : []
            ),
            'stop_tokens' => array_values(array_unique(array_merge(
                is_array($base['stop_tokens'] ?? null) ? $base['stop_tokens'] : [],
                is_array($tenant['stop_tokens'] ?? null) ? $tenant['stop_tokens'] : []
            ))),
        ];

        $this->latamLexiconCache[$cacheKey] = [
            'data' => $merged,
            'base_mtime' => $baseMtime,
            'tenant_hash' => $tenantHash,
        ];

        return $merged;
    }

    public function applyLatamLexiconPack(string $text, array $pack, string $mode = 'app'): string
    {
        $phraseRules = is_array($pack['phrase_rules'] ?? null) ? $pack['phrase_rules'] : [];
        foreach ($phraseRules as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $match = $this->normalize((string) ($rule['match'] ?? ''));
            $replace = $this->normalize((string) ($rule['replace'] ?? ''));
            if ($match === '') {
                continue;
            }
            $text = str_replace($match, $replace, $text);
        }

        $synonyms = is_array($pack['synonyms'] ?? null) ? $pack['synonyms'] : [];
        foreach ($synonyms as $alias => $target) {
            $alias = $this->normalize((string) $alias);
            $target = $this->normalize((string) $target);
            if ($alias === '' || $target === '') {
                continue;
            }
            if ($this->shouldSkipAmbiguousSynonym($alias, $target, $text, $mode)) {
                continue;
            }
            $text = preg_replace('/\\b' . preg_quote($alias, '/') . '\\b/u', $target, $text) ?? $text;
        }

        $stopTokens = is_array($pack['stop_tokens'] ?? null) ? $pack['stop_tokens'] : [];
        foreach ($stopTokens as $token) {
            $token = $this->normalize((string) $token);
            if ($token === '') {
                continue;
            }
            $text = preg_replace('/\\b' . preg_quote($token, '/') . '\\b/u', ' ', $text) ?? $text;
        }

        return preg_replace('/\s+/', ' ', trim($text)) ?? $text;
    }

    public function shouldSkipAmbiguousSynonym(string $alias, string $target, string $text, string $mode): bool
    {
        if ($mode === 'builder') {
            return false;
        }
        if ($alias === 'lista' && $target === 'tabla') {
            if (preg_match('/\b(lista(r)?|mostrar|ver|buscar|dame)\b/u', $text) === 1) {
                return true;
            }
            if (preg_match('/\blista\s+de\b/u', $text) === 1) {
                return true;
            }
        }
        return false;
    }

    public function loadCountryOverrides(string $tenantId = 'default'): array
    {
        if ($this->countryOverridesCache !== null) {
            return $this->countryOverridesCache;
        }

        $frameworkRoot = defined('FRAMEWORK_ROOT') ? FRAMEWORK_ROOT : dirname(__DIR__, 3);
        $basePath = $frameworkRoot . '/contracts/agents/country_language_overrides.json';
        $tenantPath = $this->projectRoot . '/storage/tenants/' . $this->safe($tenantId) . '/country_language_overrides.json';

        $base = $this->readJson($basePath, [
            'global' => ['typo_rules' => [], 'synonyms' => []],
            'countries' => [],
        ]);
        $tenant = $this->memory->getTenantMemory($tenantId, 'country_language_overrides', []);
        if (empty($tenant) && is_file($tenantPath)) {
            $tenant = $this->readJson($tenantPath, []);
            if (!empty($tenant)) {
                $this->memory->saveTenantMemory($tenantId, 'country_language_overrides', $tenant);
            }
        }

        if (!empty($tenant['global']) && is_array($tenant['global'])) {
            $baseGlobal = is_array($base['global'] ?? null) ? $base['global'] : [];
            $tenantGlobal = $tenant['global'];
            $base['global'] = [
                'typo_rules' => array_merge(
                    is_array($baseGlobal['typo_rules'] ?? null) ? $baseGlobal['typo_rules'] : [],
                    is_array($tenantGlobal['typo_rules'] ?? null) ? $tenantGlobal['typo_rules'] : []
                ),
                'synonyms' => array_merge(
                    is_array($baseGlobal['synonyms'] ?? null) ? $baseGlobal['synonyms'] : [],
                    is_array($tenantGlobal['synonyms'] ?? null) ? $tenantGlobal['synonyms'] : []
                ),
            ];
        }

        if (!empty($tenant['countries']) && is_array($tenant['countries'])) {
            if (!is_array($base['countries'] ?? null)) {
                $base['countries'] = [];
            }
            foreach ($tenant['countries'] as $country => $cfg) {
                if (!is_array($cfg)) {
                    continue;
                }
                $country = strtoupper((string) $country);
                $existing = is_array($base['countries'][$country] ?? null) ? $base['countries'][$country] : [];
                $base['countries'][$country] = [
                    'typo_rules' => array_merge(
                        is_array($existing['typo_rules'] ?? null) ? $existing['typo_rules'] : [],
                        is_array($cfg['typo_rules'] ?? null) ? $cfg['typo_rules'] : []
                    ),
                    'synonyms' => array_merge(
                        is_array($existing['synonyms'] ?? null) ? $existing['synonyms'] : [],
                        is_array($cfg['synonyms'] ?? null) ? $cfg['synonyms'] : []
                    ),
                ];
            }
        }

        $this->countryOverridesCache = $base;
        return $this->countryOverridesCache;
    }

    public function workingMemorySchema(): object
    {
        if (is_object($this->workingMemorySchemaCache)) {
            return $this->workingMemorySchemaCache;
        }
        $frameworkRoot = defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 3);
        $path = $frameworkRoot . '/contracts/agents/WORKING_MEMORY_SCHEMA.json';
        if (!is_file($path)) {
            return (object)[];
        }
        $raw = file_get_contents($path);
        if (!is_string($raw) || trim($raw) === '') {
            throw new RuntimeException('Schema de working memory vacio.');
        }
        $decoded = json_decode($raw);
        if (!is_object($decoded)) {
            throw new RuntimeException('Schema de working memory invalido.');
        }
        $this->workingMemorySchemaCache = $decoded;
        return $decoded;
    }

    public function loadConfusionBase(): array
    {
        if ($this->confusionBaseCache !== null) {
            return $this->confusionBaseCache;
        }
        $frameworkRoot = defined('FRAMEWORK_ROOT') ? FRAMEWORK_ROOT : dirname(__DIR__, 3);
        $path = $frameworkRoot . '/contracts/agents/conversation_confusion_base.json';
        if (!is_file($path)) {
            $this->confusionBaseCache = [];
            return [];
        }
        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            $this->confusionBaseCache = [];
            return [];
        }
        $raw = ltrim($raw, "\xEF\xBB\xBF");
        $decoded = json_decode($raw, true);
        $this->confusionBaseCache = is_array($decoded) ? $decoded : [];
        return $this->confusionBaseCache;
    }

    public function confusionNonEntityTokens(): array
    {
        $confusion = $this->loadConfusionBase();
        $tokens = is_array($confusion['rules']['non_entity_tokens'] ?? null)
            ? $confusion['rules']['non_entity_tokens']
            : [];
        return array_values(array_filter(array_map(
            fn($token) => trim(strtolower((string) $token)),
            $tokens
        )));
    }

    public function normalize(string $text): string
    {
        $text = mb_strtolower(trim($text), 'UTF-8');
        $text = preg_replace('/\s+/', ' ', trim($text)) ?? $text;
        return $text;
    }

    private function safe(string $value): string
    {
        $value = preg_replace('/[^a-zA-Z0-9_\\-]/', '_', $value) ?? 'default';
        return trim($value, '_');
    }

    public function loadLexicon(string $tenantId): array
    {
        $default = [
            'synonyms' => [],
            'shortcuts' => [],
            'stop_phrases' => [],
            'entity_aliases' => ['cliente' => 'cliente', 'factura' => 'factura'],
            'field_aliases' => ['nit' => 'nit', 'telefono' => 'telefono', 'correo' => 'email'],
        ];
        $stored = $this->memory->getTenantMemory($tenantId, 'lexicon', []);
        return !empty($stored) ? array_merge($default, $stored) : $default;
    }

    public function loadPolicy(string $tenantId): array
    {
        $default = [
            'ask_style' => 'short',
            'confirm_delete' => true,
            'max_questions_before_llm' => 2,
            'latency_budget_ms' => 1200,
            'max_output_tokens' => 400,
            'question_templates' => [],
        ];
        $stored = $this->memory->getTenantMemory($tenantId, 'dialog_policy', []);
        return !empty($stored) ? array_merge($default, $stored) : $default;
    }

    private function readJson(string $path, array $default): array
    {
        if (!is_file($path)) return $default;
        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') return $default;
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : $default;
    }

    private function normalizeText(string $text): string
    {
        return strtolower(trim($text));
    }
}
