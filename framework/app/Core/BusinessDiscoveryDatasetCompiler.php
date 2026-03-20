<?php

declare(strict_types=1);

namespace App\Core;

use JsonException;
use Opis\JsonSchema\Validator;
use RuntimeException;

final class BusinessDiscoveryDatasetCompiler
{
    private const DEFAULT_SCHEMA = FRAMEWORK_ROOT . '/contracts/schemas/business_discovery_template.schema.json';

    /**
     * @param array<string, mixed> $payload
     */
    public static function validateTemplate(array $payload, ?string $schemaPath = null): void
    {
        $schemaPath = $schemaPath ?? self::DEFAULT_SCHEMA;
        if (!is_file($schemaPath)) {
            throw new RuntimeException('Schema de business discovery no existe: ' . $schemaPath);
        }

        $schema = json_decode((string) file_get_contents($schemaPath));
        if (!$schema) {
            throw new RuntimeException('Schema de business discovery invalido.');
        }

        try {
            $payloadObject = json_decode(
                json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                false,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException $e) {
            throw new RuntimeException('Payload business discovery no serializable.', 0, $e);
        }

        $validator = new Validator();
        $result = $validator->validate($payloadObject, $schema);
        if (!$result->isValid()) {
            $error = $result->error();
            $message = $error ? $error->message() : 'Business discovery invalido por schema.';
            throw new RuntimeException($message);
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{dataset: array<string, mixed>, stats: array<string, int>}
     */
    public static function compile(array $payload, ?string $schemaPath = null): array
    {
        self::validateTemplate($payload, $schemaPath);
        $payload = self::applySectorSeed($payload);

        $version = trim((string) ($payload['version'] ?? '1.0.0'));
        if ($version === '') {
            $version = '1.0.0';
        }

        $datasetTarget = is_array($payload['dataset_target'] ?? null) ? $payload['dataset_target'] : [];
        $accountingRules = is_array($payload['accounting_rules'] ?? null) ? $payload['accounting_rules'] : [];
        $sectorKey = trim((string) ($payload['sector_key'] ?? 'SECTOR_UNKNOWN'));
        $sectorLabel = trim((string) ($payload['sector_label'] ?? $sectorKey));

        $intentsBuild = self::buildIntents($payload);
        $skillIntentMap = is_array($intentsBuild['skill_intent_map'] ?? null)
            ? $intentsBuild['skill_intent_map']
            : [];

        $dataset = [
            'batch_id' => trim((string) ($datasetTarget['batch_id'] ?? 'business_discovery_batch')),
            'language' => trim((string) ($payload['language'] ?? 'es-CO')),
            'dataset_version' => trim((string) ($datasetTarget['dataset_version'] ?? $version)),
            'source_type' => 'business_discovery',
            'memory_type' => 'sector_knowledge',
            'sector' => $sectorKey,
            'sector_key' => $sectorKey,
            'sector_label' => $sectorLabel,
            'country_or_regulation' => trim((string) ($payload['country_or_regulation'] ?? '')),
            'source_metadata' => [
                'discovery_id' => trim((string) ($payload['discovery_id'] ?? 'business_discovery')),
                'sector_key' => $sectorKey,
                'sector_label' => $sectorLabel,
                'country_or_regulation' => trim((string) ($payload['country_or_regulation'] ?? '')),
                'channels' => self::uniqueLocalStrings($datasetTarget['channels'] ?? []),
                'skills_referenced' => array_values(array_keys($skillIntentMap)),
            ],
            'knowledge_stable' => self::buildKnowledgeStable($payload, $version),
            'support_faq' => self::buildSupportFaq($payload, $version),
            'policy_constraints' => self::buildPolicyConstraints($payload, $version),
            'ontology' => self::buildOntology($payload),
            'publication' => [
                'status' => 'draft',
            ],
            'context_pack' => [
                'business_type' => trim((string) ($payload['business_type'] ?? '')),
                'country' => trim((string) ($datasetTarget['country'] ?? '')),
                'currency' => trim((string) ($datasetTarget['currency'] ?? '')),
                'tax_regime' => trim((string) ($datasetTarget['tax_regime'] ?? '')),
                'payment_methods' => self::uniqueLocalStrings($accountingRules['payment_types'] ?? []),
                'document_flows' => self::buildDocumentFlows($payload),
                'channels' => self::uniqueLocalStrings($datasetTarget['channels'] ?? []),
                'volume_profile' => trim((string) ($datasetTarget['volume_profile'] ?? $sectorLabel)),
                'chart_of_accounts_min' => self::buildChartOfAccounts($accountingRules['chart_of_accounts_min'] ?? []),
                'posting_rules' => self::buildPostingRules($accountingRules['posting_rules'] ?? []),
            ],
            'intents_expansion' => $intentsBuild['records'],
            'multi_turn_dialogues' => self::buildDialogues($payload),
            'emotion_cases' => self::buildEmotionCases($payload),
            'qa_cases' => self::buildQaCases($payload, $skillIntentMap),
        ];
        $balanceAudit = SectorIntentBalance::audit((array) ($dataset['intents_expansion'] ?? []));

        return [
            'dataset' => $dataset,
            'stats' => [
                'knowledge_stable' => count($dataset['knowledge_stable']),
                'support_faq' => count($dataset['support_faq']),
                'policy_constraints' => count($dataset['policy_constraints']),
                'ontology_terms' => count((array) ($dataset['ontology']['canonical_terms'] ?? [])),
                'intents_expansion' => count($dataset['intents_expansion']),
                'multi_turn_dialogues' => count($dataset['multi_turn_dialogues']),
                'emotion_cases' => count($dataset['emotion_cases']),
                'qa_cases' => count($dataset['qa_cases']),
                'dedupe_removed' => (int) ($intentsBuild['dedupe_removed'] ?? 0),
                'intent_balance' => $balanceAudit,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<int, array<string, mixed>>
     */
    private static function buildKnowledgeStable(array $payload, string $version): array
    {
        $records = [];
        $sectorKey = trim((string) ($payload['sector_key'] ?? 'SECTOR_UNKNOWN'));
        $discoveryId = trim((string) ($payload['discovery_id'] ?? 'business_discovery'));

        $processes = is_array($payload['business_processes'] ?? null) ? $payload['business_processes'] : [];
        foreach ($processes as $processKey => $process) {
            if (!is_array($process)) {
                continue;
            }
            $label = trim((string) ($process['label'] ?? $processKey));
            $facts = self::uniqueLocalStrings([
                'Eventos clave: ' . implode(', ', self::uniqueLocalStrings($process['events'] ?? [])),
                'Documentos involucrados: ' . implode(', ', self::uniqueLocalStrings($process['documents_involved'] ?? [])),
                'Acciones ERP: ' . implode(', ', self::uniqueLocalStrings($process['erp_actions'] ?? [])),
                'Skills usadas: ' . implode(', ', self::uniqueLocalStrings($process['skills_used'] ?? [])),
            ]);
            $records[] = [
                'id' => self::safeId($discoveryId . '_process_' . (string) $processKey),
                'sector' => $sectorKey,
                'title' => 'Proceso ' . $label,
                'facts' => $facts,
                'source_refs' => ['business_discovery:' . $discoveryId, 'process:' . (string) $processKey],
                'tags' => self::uniqueLocalStrings(array_merge(['process', (string) $processKey], self::uniqueLocalStrings($process['skills_used'] ?? []))),
                'version' => $version,
                'vectorize' => true,
            ];
        }

        $documents = is_array($payload['business_documents'] ?? null) ? $payload['business_documents'] : [];
        foreach ($documents as $documentKey => $document) {
            if (!is_array($document)) {
                continue;
            }
            $records[] = [
                'id' => self::safeId($discoveryId . '_document_' . (string) $documentKey),
                'sector' => $sectorKey,
                'title' => 'Documento ' . str_replace('_', ' ', (string) $documentKey),
                'facts' => self::uniqueLocalStrings([
                    'Evento origen: ' . trim((string) ($document['origin_event'] ?? '')),
                    'Campos clave: ' . implode(', ', self::uniqueLocalStrings($document['key_fields'] ?? [])),
                    'Skill relacionada: ' . trim((string) ($document['related_skill'] ?? '')),
                ]),
                'source_refs' => ['business_discovery:' . $discoveryId, 'document:' . (string) $documentKey],
                'tags' => self::uniqueLocalStrings(['document', (string) $documentKey, (string) ($document['related_skill'] ?? '')]),
                'version' => $version,
                'vectorize' => true,
            ];
        }

        $flows = is_array($payload['operational_flows'] ?? null) ? $payload['operational_flows'] : [];
        foreach ($flows as $flow) {
            if (!is_array($flow)) {
                continue;
            }
            $flowKey = trim((string) ($flow['flow_key'] ?? 'flow'));
            $label = trim((string) ($flow['label'] ?? $flowKey));
            $records[] = [
                'id' => self::safeId($discoveryId . '_flow_' . $flowKey),
                'sector' => $sectorKey,
                'title' => 'Flujo ' . $label,
                'facts' => self::uniqueLocalStrings([
                    'Solicitud inicial: ' . trim((string) ($flow['user_request_example'] ?? '')),
                    'Secuencia: ' . self::flowSequenceText($flow),
                    'Documentos del flujo: ' . implode(', ', self::uniqueLocalStrings($flow['documents'] ?? [])),
                ]),
                'source_refs' => ['business_discovery:' . $discoveryId, 'flow:' . $flowKey],
                'tags' => self::uniqueLocalStrings(array_merge(['flow', $flowKey], self::flowSkills($flow))),
                'version' => $version,
                'vectorize' => true,
            ];
        }

        $accountingRules = is_array($payload['accounting_rules'] ?? null) ? $payload['accounting_rules'] : [];
        $records[] = [
            'id' => self::safeId($discoveryId . '_accounting_rules'),
            'sector' => $sectorKey,
            'title' => 'Reglas contables del sector',
            'facts' => self::uniqueLocalStrings([
                'Impuestos: ' . self::ruleSummary($accountingRules['tax'] ?? []),
                'IVA: ' . self::ruleSummary($accountingRules['vat'] ?? []),
                'Retenciones: ' . self::ruleSummary($accountingRules['withholding'] ?? []),
                'Ventas a credito: ' . self::ruleSummary($accountingRules['credit_sales'] ?? []),
                'Costo de inventario: ' . self::ruleSummary($accountingRules['inventory_cost'] ?? []),
                'Costo de ventas: ' . self::ruleSummary($accountingRules['cost_of_goods_sold'] ?? []),
            ]),
            'source_refs' => ['business_discovery:' . $discoveryId, 'accounting_rules'],
            'tags' => ['accounting', 'tax', 'inventory'],
            'version' => $version,
            'vectorize' => true,
        ];

        $sectorSeed = is_array($payload['_sector_seed'] ?? null) ? $payload['_sector_seed'] : [];
        $seedKnowledge = is_array($sectorSeed['knowledge_stable_seed'] ?? null)
            ? $sectorSeed['knowledge_stable_seed']
            : [];
        foreach ($seedKnowledge as $index => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $title = trim((string) ($entry['title'] ?? ''));
            $facts = self::uniqueLocalStrings($entry['facts'] ?? []);
            if ($title === '' || $facts === []) {
                continue;
            }
            $records[] = [
                'id' => self::safeId($discoveryId . '_sector_seed_knowledge_' . $index),
                'sector' => $sectorKey,
                'title' => $title,
                'facts' => $facts,
                'source_refs' => ['business_discovery:' . $discoveryId, 'sector_seed:' . $sectorKey],
                'tags' => self::uniqueLocalStrings(array_merge(
                    ['sector_seed'],
                    self::uniqueLocalStrings($entry['tags'] ?? [])
                )),
                'version' => $version,
                'vectorize' => (bool) ($entry['vectorize'] ?? true),
            ];
        }

        return $records;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<int, array<string, mixed>>
     */
    private static function buildSupportFaq(array $payload, string $version): array
    {
        $records = [];
        $questions = is_array($payload['frequent_business_questions'] ?? null) ? $payload['frequent_business_questions'] : [];
        $seen = [];

        foreach ($questions as $index => $question) {
            if (!is_array($question)) {
                continue;
            }
            $questionText = self::sanitizeText((string) ($question['question'] ?? ''), true);
            $answerText = self::sanitizeText((string) ($question['answer'] ?? ''), false);
            $key = self::toKey($questionText);
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $records[] = [
                'id' => self::safeId('faq_' . $index . '_' . $key),
                'question' => $questionText,
                'answer' => $answerText,
                'tags' => self::uniqueLocalStrings(array_merge(
                    self::uniqueLocalStrings($question['tags'] ?? []),
                    [(string) ($question['related_process'] ?? ''), (string) ($question['related_skill'] ?? '')]
                )),
                'version' => $version,
                'vectorize' => true,
            ];
        }

        $sectorSeed = is_array($payload['_sector_seed'] ?? null) ? $payload['_sector_seed'] : [];
        $seedFaq = is_array($sectorSeed['support_faq_seed'] ?? null) ? $sectorSeed['support_faq_seed'] : [];
        foreach ($seedFaq as $index => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $questionText = self::sanitizeText((string) ($entry['question'] ?? ''), true);
            $answerText = self::sanitizeText((string) ($entry['answer'] ?? ''), false);
            $key = self::toKey($questionText);
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $records[] = [
                'id' => self::safeId('seed_faq_' . $index . '_' . $key),
                'question' => $questionText,
                'answer' => $answerText,
                'tags' => self::uniqueLocalStrings(array_merge(
                    ['sector_seed'],
                    self::uniqueLocalStrings($entry['tags'] ?? [])
                )),
                'version' => $version,
                'vectorize' => (bool) ($entry['vectorize'] ?? true),
            ];
        }

        return $records;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<int, array<string, mixed>>
     */
    private static function buildPolicyConstraints(array $payload, string $version): array
    {
        $records = [];
        $architecture = is_array($payload['architecture_guards'] ?? null) ? $payload['architecture_guards'] : [];
        foreach ($architecture as $guard) {
            if (!is_array($guard)) {
                continue;
            }
            $records[] = [
                'id' => trim((string) ($guard['id'] ?? 'guard_unknown')),
                'scope' => trim((string) ($guard['scope'] ?? 'runtime')),
                'rule' => self::sanitizeText((string) ($guard['rule'] ?? ''), false),
                'severity' => trim((string) ($guard['severity'] ?? 'medium')),
                'action_if_violate' => trim((string) ($guard['action_if_violate'] ?? 'block')),
                'version' => $version,
                'vectorize' => false,
            ];
        }

        $qualityRules = is_array($payload['data_quality_rules'] ?? null) ? $payload['data_quality_rules'] : [];
        foreach ($qualityRules as $qualityRule) {
            if (!is_array($qualityRule)) {
                continue;
            }
            $records[] = [
                'id' => trim((string) ($qualityRule['id'] ?? 'quality_unknown')),
                'scope' => 'dataset.hygiene',
                'rule' => self::sanitizeText((string) ($qualityRule['rule'] ?? ''), false),
                'severity' => 'medium',
                'action_if_violate' => 'clean_and_retry',
                'version' => $version,
                'vectorize' => false,
            ];
        }

        return $records;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function buildOntology(array $payload): array
    {
        $terms = [];
        $terminology = is_array($payload['sector_terminology'] ?? null) ? $payload['sector_terminology'] : [];
        foreach ($terminology as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $terms[] = [
                'term' => self::sanitizeText((string) ($entry['term'] ?? ''), false),
                'type' => trim((string) ($entry['type'] ?? 'concept')),
                'synonyms' => self::uniqueLocalStrings(array_merge(
                    self::uniqueLocalStrings($entry['synonyms'] ?? []),
                    self::uniqueLocalStrings($entry['common_phrases'] ?? [])
                )),
            ];
        }

        return ['canonical_terms' => $terms];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{records: array<int, array<string, mixed>>, skill_intent_map: array<string, string>, dedupe_removed: int}
     */
    private static function buildIntents(array $payload): array
    {
        $terminologyBySkill = self::buildTerminologyBySkill($payload);
        $questionsBySkill = self::buildQuestionsBySkill($payload);
        $globalSeen = [];
        $records = [];
        $skillIntentMap = [];
        $dedupeRemoved = 0;

        $mappings = is_array($payload['skill_mappings'] ?? null) ? $payload['skill_mappings'] : [];
        foreach ($mappings as $mapping) {
            if (!is_array($mapping)) {
                continue;
            }

            $skill = trim((string) ($mapping['skill'] ?? ''));
            $intent = trim((string) ($mapping['intent'] ?? strtoupper($skill)));
            if ($skill === '' || $intent === '') {
                continue;
            }

            $terminology = is_array($terminologyBySkill[$skill] ?? null) ? $terminologyBySkill[$skill] : [];
            $questionUtterances = is_array($questionsBySkill[$skill] ?? null) ? $questionsBySkill[$skill] : [];

            $explicitSource = array_merge(
                self::uniqueLocalStrings($mapping['utterances_explicit'] ?? []),
                self::uniqueLocalStrings($terminology['common_phrases'] ?? []),
                $questionUtterances
            );
            $implicitSource = array_merge(
                self::uniqueLocalStrings($mapping['utterances_implicit'] ?? []),
                self::uniqueLocalStrings($terminology['how_users_ask'] ?? [])
            );
            $negativeSource = self::uniqueLocalStrings($mapping['hard_negatives'] ?? []);

            $explicit = self::uniqueIntentPhrases($explicitSource, $globalSeen, [], $dedupeRemoved);
            $blocked = self::phraseKeys($explicit);
            $implicit = self::uniqueIntentPhrases($implicitSource, $globalSeen, $blocked, $dedupeRemoved);
            $explicit = self::ensureMinimumIntentCoverage($mapping, $explicit, $implicit, $globalSeen, $dedupeRemoved);
            $blocked = self::phraseKeys(array_merge($explicit, $implicit));
            $negatives = self::uniqueIntentPhrases($negativeSource, $globalSeen, $blocked, $dedupeRemoved);

            $records[] = [
                'intent' => $intent,
                'action' => $skill,
                'utterances_explicit' => $explicit,
                'utterances_implicit' => $implicit,
                'hard_negatives' => $negatives,
                'slots' => self::buildSlots($mapping['required_slots'] ?? []),
                'one_question_policy' => self::buildOneQuestionPolicy($mapping['one_question_policy'] ?? []),
                'confirm_required' => (bool) ($mapping['confirm_required'] ?? false),
                'success_reply_template' => trim((string) ($mapping['success_reply_template'] ?? 'Listo.')),
                'error_reply_template' => trim((string) ($mapping['error_reply_template'] ?? 'Falta informacion.')),
            ];
            $skillIntentMap[$skill] = $intent;
        }

        return [
            'records' => $records,
            'skill_intent_map' => $skillIntentMap,
            'dedupe_removed' => $dedupeRemoved,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<int, array<string, mixed>>
     */
    private static function buildDialogues(array $payload): array
    {
        $dialogues = [];
        $flows = is_array($payload['operational_flows'] ?? null) ? $payload['operational_flows'] : [];
        foreach ($flows as $flow) {
            if (!is_array($flow)) {
                continue;
            }
            $dialogues[] = [
                'id' => self::safeId((string) ($flow['flow_key'] ?? 'flow')),
                'scenario' => trim((string) ($flow['label'] ?? 'Flujo')),
                'turns' => [
                    [
                        'user' => self::sanitizeText((string) ($flow['user_request_example'] ?? ''), true),
                    ],
                    [
                        'assistant_expected_action' => self::flowSequenceText($flow),
                    ],
                ],
            ];
        }

        return $dialogues;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<int, array<string, mixed>>
     */
    private static function buildEmotionCases(array $payload): array
    {
        $sectorLabel = trim((string) ($payload['sector_label'] ?? 'este sector'));

        return [
            [
                'emotion' => 'urgencia',
                'user_samples' => [
                    'Necesito resolverlo hoy en ' . $sectorLabel,
                    'Es urgente y debo sacar ese soporte ya',
                ],
                'assistant_style' => 'directo y enfocado en el siguiente dato critico',
                'expected_next_step' => 'pedir un solo dato faltante para completar la accion o consulta',
            ],
            [
                'emotion' => 'confusion',
                'user_samples' => [
                    'No se si eso va como factura o como nota',
                    'No tengo claro que documento toca usar',
                ],
                'assistant_style' => 'claro, corto y orientado a aclarar el flujo correcto',
                'expected_next_step' => 'explicar diferencia operativa y proponer la skill correcta',
            ],
            [
                'emotion' => 'frustracion',
                'user_samples' => [
                    'Eso no me cuadra en el sistema',
                    'No encuentro el soporte y ya me canse',
                ],
                'assistant_style' => 'calmo, preciso y sin tecnicismos',
                'expected_next_step' => 'guiar una verificacion puntual o derivar a la consulta correcta',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $skillIntentMap
     * @return array<int, array<string, mixed>>
     */
    private static function buildQaCases(array $payload, array $skillIntentMap): array
    {
        $cases = [];
        $seen = [];
        $questions = is_array($payload['frequent_business_questions'] ?? null) ? $payload['frequent_business_questions'] : [];

        foreach ($questions as $question) {
            if (!is_array($question)) {
                continue;
            }
            $text = self::sanitizeText((string) ($question['question'] ?? ''), true);
            $skill = trim((string) ($question['related_skill'] ?? ''));
            $intent = trim((string) ($skillIntentMap[$skill] ?? ''));
            $key = self::toKey($text);
            if ($key === '' || $intent === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $cases[] = [
                'text' => $text,
                'expect_intent' => $intent,
                'expect_action' => $skill,
            ];
        }

        return $cases;
    }

    /**
     * @param mixed $value
     * @return array<int, array<string, mixed>>
     */
    private static function buildChartOfAccounts($value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $accounts = [];
        foreach ($value as $account) {
            if (!is_array($account)) {
                continue;
            }
            $code = trim((string) ($account['code'] ?? ''));
            $name = trim((string) ($account['name'] ?? ''));
            if ($code === '' || $name === '') {
                continue;
            }
            $accounts[] = ['code' => $code, 'name' => $name];
        }
        return $accounts;
    }

    /**
     * @param mixed $value
     * @return array<int, array<string, mixed>>
     */
    private static function buildPostingRules($value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $rules = [];
        foreach ($value as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $trigger = trim((string) ($rule['trigger'] ?? ''));
            $debit = trim((string) ($rule['debit_account'] ?? ''));
            $credit = trim((string) ($rule['credit_account'] ?? ''));
            if ($trigger === '' || $debit === '' || $credit === '') {
                continue;
            }
            $rules[] = [
                'trigger' => $trigger,
                'debit_account' => $debit,
                'credit_account' => $credit,
            ];
        }
        return $rules;
    }

    /**
     * @param mixed $value
     * @return array<int, array<string, mixed>>
     */
    private static function buildSlots($value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $slots = [];
        foreach ($value as $slot) {
            if (!is_array($slot)) {
                continue;
            }
            $name = trim((string) ($slot['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $slots[] = [
                'name' => $name,
                'required' => (bool) ($slot['required'] ?? false),
            ];
        }
        return $slots;
    }

    /**
     * @param mixed $value
     * @return array<int, array<string, mixed>>
     */
    private static function buildOneQuestionPolicy($value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $policies = [];
        foreach ($value as $policy) {
            if (!is_array($policy)) {
                continue;
            }
            $missingSlot = trim((string) ($policy['missing_slot'] ?? ''));
            $question = self::sanitizeText((string) ($policy['question'] ?? ''), true);
            if ($missingSlot === '' || $question === '') {
                continue;
            }
            $policies[] = [
                'missing_slot' => $missingSlot,
                'question' => $question,
            ];
        }
        return $policies;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<int, string>
     */
    private static function buildDocumentFlows(array $payload): array
    {
        $flows = is_array($payload['operational_flows'] ?? null) ? $payload['operational_flows'] : [];
        $documentFlows = [];
        foreach ($flows as $flow) {
            if (!is_array($flow)) {
                continue;
            }
            $documentFlows[] = self::flowSequenceText($flow);
        }
        return $documentFlows;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, array<string, array<int, string>>>
     */
    private static function buildTerminologyBySkill(array $payload): array
    {
        $result = [];
        $entries = is_array($payload['sector_terminology'] ?? null) ? $payload['sector_terminology'] : [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $skill = trim((string) ($entry['related_skill'] ?? ''));
            if ($skill === '') {
                continue;
            }
            $result[$skill]['common_phrases'] = array_merge(
                $result[$skill]['common_phrases'] ?? [],
                self::uniqueLocalStrings($entry['common_phrases'] ?? [])
            );
            $result[$skill]['how_users_ask'] = array_merge(
                $result[$skill]['how_users_ask'] ?? [],
                self::uniqueLocalStrings($entry['how_users_ask'] ?? [])
            );
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, array<int, string>>
     */
    private static function buildQuestionsBySkill(array $payload): array
    {
        $result = [];
        $questions = is_array($payload['frequent_business_questions'] ?? null) ? $payload['frequent_business_questions'] : [];
        foreach ($questions as $question) {
            if (!is_array($question)) {
                continue;
            }
            $skill = trim((string) ($question['related_skill'] ?? ''));
            $text = self::sanitizeText((string) ($question['question'] ?? ''), true);
            if ($skill === '' || $text === '') {
                continue;
            }
            $result[$skill][] = $text;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function applySectorSeed(array $payload): array
    {
        $sectorSeed = is_array($payload['sector_seed'] ?? null) ? $payload['sector_seed'] : [];
        if ($sectorSeed === []) {
            return $payload;
        }

        SectorSeedValidator::validateOrFail($sectorSeed, [
            'expected_sector_key' => (string) ($payload['sector_key'] ?? ''),
            'expected_sector_label' => (string) ($payload['sector_label'] ?? ''),
            'expected_country_or_regulation' => (string) ($payload['country_or_regulation'] ?? ''),
        ]);

        $payload['sector_terminology'] = self::mergeTerminologyEntries(
            is_array($payload['sector_terminology'] ?? null) ? $payload['sector_terminology'] : [],
            is_array($sectorSeed['terminology_seed'] ?? null) ? $sectorSeed['terminology_seed'] : []
        );
        $payload['skill_mappings'] = self::mergeSkillOverrides(
            is_array($payload['skill_mappings'] ?? null) ? $payload['skill_mappings'] : [],
            is_array($sectorSeed['skill_overrides'] ?? null) ? $sectorSeed['skill_overrides'] : []
        );
        $payload['_sector_seed'] = $sectorSeed;

        return $payload;
    }

    /**
     * @param array<int, mixed> $base
     * @param array<int, mixed> $seed
     * @return array<int, array<string, mixed>>
     */
    private static function mergeTerminologyEntries(array $base, array $seed): array
    {
        $result = [];
        $seen = [];

        foreach (array_merge($base, $seed) as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $term = trim((string) ($entry['term'] ?? ''));
            $skill = trim((string) ($entry['related_skill'] ?? ''));
            $key = self::toKey($term . '|' . $skill);
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $result[] = $entry;
        }

        return $result;
    }

    /**
     * @param array<int, mixed> $mappings
     * @param array<int, mixed> $overrides
     * @return array<int, array<string, mixed>>
     */
    private static function mergeSkillOverrides(array $mappings, array $overrides): array
    {
        if ($overrides === []) {
            return array_values(array_filter($mappings, 'is_array'));
        }

        $bySkill = [];
        foreach ($mappings as $index => $mapping) {
            if (!is_array($mapping)) {
                continue;
            }
            $skill = trim((string) ($mapping['skill'] ?? ''));
            if ($skill !== '') {
                $bySkill[$skill] = $index;
            }
        }

        foreach ($overrides as $override) {
            if (!is_array($override)) {
                continue;
            }
            $skill = trim((string) ($override['skill'] ?? ''));
            if ($skill === '') {
                continue;
            }
            if (!isset($bySkill[$skill])) {
                throw new RuntimeException('Sector seed referencia skill no presente en business discovery: ' . $skill);
            }

            $index = $bySkill[$skill];
            $mapping = is_array($mappings[$index] ?? null) ? $mappings[$index] : [];
            $mapping['utterances_explicit'] = self::uniqueLocalStrings(array_merge(
                self::uniqueLocalStrings($mapping['utterances_explicit'] ?? []),
                self::uniqueLocalStrings($override['utterances_explicit'] ?? [])
            ));
            $mapping['utterances_implicit'] = self::uniqueLocalStrings(array_merge(
                self::uniqueLocalStrings($mapping['utterances_implicit'] ?? []),
                self::uniqueLocalStrings($override['utterances_implicit'] ?? [])
            ));
            $mapping['hard_negatives'] = self::uniqueLocalStrings(array_merge(
                self::uniqueLocalStrings($mapping['hard_negatives'] ?? []),
                self::uniqueLocalStrings($override['hard_negatives'] ?? [])
            ));
            $mappings[$index] = $mapping;
        }

        return array_values(array_filter($mappings, 'is_array'));
    }

    /**
     * @param array<string, mixed> $mapping
     * @param array<int, string> $explicit
     * @param array<int, string> $implicit
     * @param array<string, bool> $globalSeen
     * @param int $dedupeRemoved
     * @return array<int, string>
     */
    private static function ensureMinimumIntentCoverage(
        array $mapping,
        array $explicit,
        array $implicit,
        array &$globalSeen,
        int &$dedupeRemoved
    ): array {
        $thresholds = SectorIntentBalance::resolveThresholds();
        $minTotal = (int) ($thresholds['min_total_utterances_per_intent'] ?? SectorIntentBalance::DEFAULT_MIN_TOTAL_UTTERANCES_PER_INTENT);
        if ((count($explicit) + count($implicit)) >= $minTotal) {
            return $explicit;
        }

        $skill = trim((string) ($mapping['skill'] ?? ''));
        $label = self::sanitizeText((string) ($mapping['label'] ?? ''), true);
        $candidates = array_merge(
            SectorIntentBalance::catalogKeywords($skill),
            $label !== '' ? [$label] : []
        );

        $localSeen = self::phraseKeys(array_merge($explicit, $implicit));
        foreach ($candidates as $candidate) {
            if ((count($explicit) + count($implicit)) >= $minTotal) {
                break;
            }

            $text = self::sanitizeText((string) $candidate, true);
            $key = self::toKey($text);
            if ($key === '') {
                continue;
            }
            if (isset($localSeen[$key]) || isset($globalSeen[$key])) {
                $dedupeRemoved++;
                continue;
            }

            $localSeen[$key] = true;
            $globalSeen[$key] = true;
            $explicit[] = $text;
        }

        return $explicit;
    }

    /**
     * @param array<int, string> $values
     * @param array<string, bool> $globalSeen
     * @param array<string, bool> $blocked
     * @param int $dedupeRemoved
     * @return array<int, string>
     */
    private static function uniqueIntentPhrases(array $values, array &$globalSeen, array $blocked, int &$dedupeRemoved): array
    {
        $result = [];
        foreach ($values as $value) {
            $text = self::sanitizeText((string) $value, true);
            $key = self::toKey($text);
            if ($key === '') {
                continue;
            }
            if (isset($blocked[$key]) || isset($globalSeen[$key])) {
                $dedupeRemoved++;
                continue;
            }
            $globalSeen[$key] = true;
            $result[] = $text;
        }
        return $result;
    }

    /**
     * @param array<int, string> $values
     * @return array<string, bool>
     */
    private static function phraseKeys(array $values): array
    {
        $keys = [];
        foreach ($values as $value) {
            $key = self::toKey($value);
            if ($key !== '') {
                $keys[$key] = true;
            }
        }
        return $keys;
    }

    /**
     * @param mixed $value
     * @return array<int, string>
     */
    private static function uniqueLocalStrings($value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $result = [];
        $seen = [];
        foreach ($value as $item) {
            $text = self::sanitizeText((string) $item, false);
            $key = self::toKey($text);
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $result[] = $text;
        }
        return $result;
    }

    /**
     * @param array<string, mixed> $flow
     * @return array<int, string>
     */
    private static function flowSkills(array $flow): array
    {
        $skills = [];
        $sequence = is_array($flow['sequence'] ?? null) ? $flow['sequence'] : [];
        foreach ($sequence as $step) {
            if (!is_array($step)) {
                continue;
            }
            if ((string) ($step['kind'] ?? '') !== 'skill') {
                continue;
            }
            $skills[] = trim((string) ($step['value'] ?? ''));
        }
        return self::uniqueLocalStrings($skills);
    }

    /**
     * @param array<string, mixed> $flow
     */
    private static function flowSequenceText(array $flow): string
    {
        $sequence = is_array($flow['sequence'] ?? null) ? $flow['sequence'] : [];
        $parts = [];
        foreach ($sequence as $step) {
            if (!is_array($step)) {
                continue;
            }
            $kind = trim((string) ($step['kind'] ?? ''));
            $value = trim((string) ($step['value'] ?? ''));
            if ($kind === '' || $value === '') {
                continue;
            }
            $parts[] = $kind . ':' . $value;
        }
        return implode(' -> ', $parts);
    }

    /**
     * @param mixed $value
     */
    private static function ruleSummary($value): string
    {
        if (!is_array($value)) {
            return '';
        }
        $summary = trim((string) ($value['summary'] ?? ''));
        $rules = self::uniqueLocalStrings($value['rules'] ?? []);
        if ($summary === '') {
            return implode(', ', $rules);
        }
        if ($rules === []) {
            return $summary;
        }
        return $summary . '. ' . implode(', ', $rules);
    }

    private static function sanitizeText(string $value, bool $stripChannelPrefix): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if ($stripChannelPrefix) {
            $value = preg_replace('/^\s*(web|telegram|whatsapp|chat|email|sms)\s*:\s*/iu', '', $value) ?? $value;
        }
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        return trim($value);
    }

    private static function safeId(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9_]+/', '_', $value) ?? $value;
        $value = trim($value, '_');
        return $value !== '' ? $value : 'id_unknown';
    }

    private static function toKey(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $value = function_exists('iconv')
            ? (string) (iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value)
            : $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        $value = strtolower(trim($value));
        return $value;
    }
}
